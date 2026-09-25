<?php

declare(strict_types=1);

namespace DPay\Http;

use DPay\Exceptions\AmountOutOfRangeException;
use DPay\Exceptions\ApiException;
use DPay\Exceptions\AuthenticationException;
use DPay\Exceptions\CheckoutNotOpenException;
use DPay\Exceptions\ConflictException;
use DPay\Exceptions\CrossBankCardException;
use DPay\Exceptions\GatewayDeclinedException;
use DPay\Exceptions\GatewayRejectedException;
use DPay\Exceptions\IdempotencyException;
use DPay\Exceptions\MethodDisabledException;
use DPay\Exceptions\MethodNotActiveException;
use DPay\Exceptions\NotFoundException;
use DPay\Exceptions\OtpRejectedException;
use DPay\Exceptions\PaymentRequestException;
use DPay\Exceptions\PermissionException;
use DPay\Exceptions\ProviderUnavailableException;
use DPay\Exceptions\RateLimitException;
use DPay\Exceptions\ServerException;
use DPay\Exceptions\SessionExpiredException;
use DPay\Exceptions\SessionLockedException;
use DPay\Exceptions\SessionNotPendingException;
use DPay\Exceptions\UnexpectedResponseException;
use DPay\Exceptions\UnsupportedMethodException;
use DPay\Exceptions\ValidationException;

/**
 * HTTP error → typed exception, for BOTH dialects the API speaks:
 *
 *   - legacy v1: `{"message": "..."}` (deliberate refusals), `{"message",
 *     "status"}` (auth/throttle/HTTP exceptions), `{"message", "errors"}`
 *     (Laravel 422);
 *   - v2: RFC 7807 `{"type", "title", "status", "detail", "code"?, "errors"?}`.
 *
 * Domain refusals are keyed on the EXACT controller strings so the SDK never
 * guesses: a string it does not know stays a generic exception of its status
 * class, message intact.
 */
final class ErrorMapper
{
    public const LEGACY_500_OPEN = ServerException::LEGACY_OPEN_MESSAGE;
    public const LEGACY_500_VERIFY = 'Payment verification failed. Please try again.';

    /** The 422 strings after which the session is still pending (retry the OTP). */
    public const OTP_REJECTED_MESSAGES = [
        OtpRejectedException::EDFALI_PIN,
        OtpRejectedException::GENERIC,
        OtpRejectedException::SANDBOX,
    ];

    /** @param array<string, mixed>|null $body */
    public static function map(int $status, ?array $body, ApiResponse $response): ApiException|UnexpectedResponseException
    {
        $requestId = $response->requestId();
        if ($body === null) {
            return new UnexpectedResponseException(
                sprintf('DPay API answered HTTP %d without a JSON body.', $status),
                $status,
                null,
                $response->rawBody,
            );
        }
        $problemType = isset($body['type']) && is_string($body['type']) && str_contains($body['type'], '/problems/') ? $body['type'] : null;
        $message = self::messageOf($body, $status);
        /** @var array<string, list<string>> $errors */
        $errors = isset($body['errors']) && is_array($body['errors']) ? self::normalizeErrors($body['errors']) : [];
        $code = isset($body['code']) && is_string($body['code']) ? $body['code'] : null;

        return match (true) {
            $status === 401 => new AuthenticationException($message, 401, $body, $requestId, $problemType),
            $status === 403 => new PermissionException($message, 403, $body, $requestId, $problemType),
            $status === 404 => new NotFoundException($message, 404, $body, $requestId, $problemType),
            $status === 429 => new RateLimitException(
                $message,
                $response->retryAfterSeconds() ?? self::intBody($body, 'retry_after_seconds'),
                self::intHeader($response, 'x-ratelimit-limit'),
                self::intHeader($response, 'x-ratelimit-reset'),
                $body,
                $requestId,
                $problemType,
            ),
            $status === 409 => self::conflict($message, $code, $body, $requestId, $problemType),
            $status === 422 && $errors !== [] => new ValidationException($message, $errors, 422, $body, $requestId, $problemType),
            $status === 422 => self::refusal422($message, $body, $requestId),
            $status === 400 => self::refusal400($message, $body, $requestId),
            $status === 503 => new ProviderUnavailableException($message, 503, $body, $requestId, $problemType),
            $status >= 500 => self::server($message, $status, $body, $requestId, $problemType),
            default => new ApiException($message, $status, $body, $requestId, $problemType),
        };
    }

    /** @param array<string, mixed> $body */
    private static function refusal400(string $message, array $body, ?string $requestId): ApiException
    {
        if ($message === MethodNotActiveException::MESSAGE) {
            return new MethodNotActiveException($message, 400, $body, $requestId);
        }
        if ($message === MethodDisabledException::MESSAGE) {
            return new MethodDisabledException($message, 400, $body, $requestId);
        }
        if (str_starts_with($message, AmountOutOfRangeException::BELOW_PREFIX)) {
            return new AmountOutOfRangeException($message, true, substr($message, strlen(AmountOutOfRangeException::BELOW_PREFIX)), $body, $requestId);
        }
        if (str_starts_with($message, AmountOutOfRangeException::ABOVE_PREFIX)) {
            return new AmountOutOfRangeException($message, false, substr($message, strlen(AmountOutOfRangeException::ABOVE_PREFIX)), $body, $requestId);
        }
        if ($message === SessionNotPendingException::MESSAGE) {
            return new SessionNotPendingException($message, 400, $body, $requestId);
        }
        if ($message === SessionExpiredException::MESSAGE) {
            return new SessionExpiredException($message, 400, $body, $requestId);
        }
        if ($message === UnsupportedMethodException::VERIFY_MESSAGE) {
            return new UnsupportedMethodException($message, 400, $body, $requestId);
        }
        if ($message === GatewayDeclinedException::SANDBOX_MESSAGE || (($body['status'] ?? null) === 'failed')) {
            return new GatewayDeclinedException($message, 400, self::intBody($body, 'session_id'), $body, $requestId);
        }

        return new PaymentRequestException($message, 400, $body, $requestId);
    }

    /** @param array<string, mixed> $body */
    private static function refusal422(string $message, array $body, ?string $requestId): ApiException
    {
        if ($message === SessionLockedException::MESSAGE) {
            return new SessionLockedException($message, 422, $body, $requestId);
        }
        if (in_array($message, self::OTP_REJECTED_MESSAGES, true)) {
            return new OtpRejectedException($message, 422, $body, $requestId);
        }
        if ($message === CrossBankCardException::MESSAGE) {
            return new CrossBankCardException($message, 422, $body, $requestId);
        }
        if (str_starts_with($message, UnsupportedMethodException::SANDBOX_PREFIX)) {
            return new UnsupportedMethodException($message, 422, $body, $requestId);
        }

        return new GatewayRejectedException($message, 422, $body, $requestId);
    }

    /** @param array<string, mixed> $body */
    private static function conflict(string $message, ?string $code, array $body, ?string $requestId, ?string $problemType): ConflictException
    {
        return match ($code) {
            'idempotency_key_reused' => new IdempotencyException($message, 409, $body, $requestId, $problemType),
            'checkout_not_open' => new CheckoutNotOpenException($message, 409, $body, $requestId, $problemType),
            default => new ConflictException($message, 409, $body, $requestId, $problemType),
        };
    }

    /**
     * Every 5xx is a {@see ServerException}, message intact. The legacy open
     * 500 `Payment processing failed. Please try again.` is the compat
     * controller's REDACTED answer for every unclassified open failure
     * (a bank adapter error, a JSON parse failure, a storage error, and —
     * among others — a cross-merchant Idempotency-Key collision), so it is
     * never mapped to {@see IdempotencyException}: that class is reserved
     * for the v2 409 `idempotency_key_reused`, the one answer that PROVES a
     * key was reused. {@see ServerException::mayBeIdempotencyCollision()}
     * keeps the hint, non-authoritatively.
     *
     * @param array<string, mixed> $body
     */
    private static function server(string $message, int $status, array $body, ?string $requestId, ?string $problemType): ApiException
    {
        return new ServerException($message, $status, $body, $requestId, $problemType);
    }

    /** @param array<string, mixed> $body */
    private static function messageOf(array $body, int $status): string
    {
        foreach (['message', 'detail', 'title'] as $key) {
            if (isset($body[$key]) && is_string($body[$key]) && $body[$key] !== '') {
                return $body[$key];
            }
        }

        return sprintf('DPay API error (HTTP %d)', $status);
    }

    /**
     * @param array<mixed> $errors
     *
     * @return array<string, list<string>>
     */
    private static function normalizeErrors(array $errors): array
    {
        $out = [];
        foreach ($errors as $field => $messages) {
            $list = [];
            foreach (is_array($messages) ? $messages : [$messages] as $m) {
                if (is_scalar($m)) {
                    $list[] = (string) $m;
                }
            }
            $out[(string) $field] = $list;
        }

        return $out;
    }

    /** @param array<string, mixed> $body */
    private static function intBody(array $body, string $key): ?int
    {
        $v = $body[$key] ?? null;

        return is_int($v) ? $v : (is_numeric($v) ? (int) $v : null);
    }

    private static function intHeader(ApiResponse $response, string $name): ?int
    {
        $v = $response->header($name);

        return $v !== null && preg_match('/^\d+$/', trim($v)) === 1 ? (int) $v : null;
    }
}
