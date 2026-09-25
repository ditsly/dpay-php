<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Http;

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
use DPay\Http\ApiResponse;
use DPay\Http\ErrorMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ErrorMapperTest extends TestCase
{
    /** @return iterable<string, array{int, array<string, mixed>, class-string}> */
    public static function legacyBodies(): iterable
    {
        yield '401' => [401, ['message' => 'Unauthenticated.', 'status' => 401], AuthenticationException::class];
        yield '401 sandbox' => [401, ['message' => 'Sandbox API token required. Pass your token as: Authorization: Bearer sb_tk_...'], AuthenticationException::class];
        yield '403 ability' => [403, ['message' => 'Your token is missing a required ability. …', 'error' => 'insufficient_token_ability', 'required_abilities' => ['role:api', 'role:session']], PermissionException::class];
        yield '403 not authorized' => [403, ['message' => 'Not authorized'], PermissionException::class];
        yield '403 invoice' => [403, ['message' => 'Invoice not found'], PermissionException::class];
        yield '404' => [404, ['message' => 'Payment session not found'], NotFoundException::class];
        yield '400 not active' => [400, ['message' => 'Payment method is not active'], MethodNotActiveException::class];
        yield '400 disabled' => [400, ['message' => 'This payment method is currently disabled'], MethodDisabledException::class];
        yield '400 below' => [400, ['message' => 'Amount is below the minimum deposit of 5'], AmountOutOfRangeException::class];
        yield '400 above' => [400, ['message' => 'Amount exceeds the maximum deposit of 60000'], AmountOutOfRangeException::class];
        yield '400 not pending' => [400, ['message' => 'Payment session is not pending'], SessionNotPendingException::class];
        yield '400 expired' => [400, ['message' => 'Payment session has expired'], SessionExpiredException::class];
        yield '400 unsupported' => [400, ['message' => 'Unsupported payment method'], UnsupportedMethodException::class];
        yield '400 sandbox decline' => [400, ['message' => 'Sandbox: gateway declined the transaction (simulated final failure)', 'status' => 'failed', 'session_id' => 18, 'sandbox' => true], GatewayDeclinedException::class];
        yield '400 unknown' => [400, ['message' => 'Something new'], PaymentRequestException::class];
        yield '422 laravel' => [422, ['message' => 'The pay method field is required. (and 1 more error)', 'errors' => ['pay_method' => ['The pay method field is required.'], 'amount' => ['The amount field is required.']]], ValidationException::class];
        yield '422 locked' => [422, ['message' => 'Too many OTP attempts. Payment session locked.'], SessionLockedException::class];
        yield '422 edfali pin' => [422, ['message' => 'EDFali PIN is not correct'], OtpRejectedException::class];
        yield '422 otp failed' => [422, ['message' => 'OTP verification failed.'], OtpRejectedException::class];
        yield '422 sandbox otp' => [422, ['message' => 'Invalid OTP. In sandbox mode, use 111111 for success or 000000 to simulate final failure.'], OtpRejectedException::class];
        yield '422 cross bank' => [422, ['message' => 'This card belongs to a different bank. Enable OnePay on this gateway to accept cross-bank payments.'], CrossBankCardException::class];
        yield '422 sandbox unsupported' => [422, ['message' => 'Unsupported payment method: sadad'], UnsupportedMethodException::class];
        yield '422 moamalat mismatch' => [422, ['message' => 'Payment verification failed: reference mismatch'], GatewayRejectedException::class];
        yield '422 customer not found' => [422, ['message' => 'The customer is not found in the bank'], GatewayRejectedException::class];
        yield '429' => [429, ['message' => 'Too Many Attempts.', 'status' => 429], RateLimitException::class];
        yield '503' => [503, ['message' => 'The payment provider is temporarily unavailable. Please try again in a few minutes.'], ProviderUnavailableException::class];
        yield '500 open (redacted — any open failure)' => [500, ['message' => 'Payment processing failed. Please try again.'], ServerException::class];
        yield '500 verify' => [500, ['message' => 'Payment verification failed. Please try again.'], ServerException::class];
        yield '413' => [413, ['message' => 'Request body too large.', 'status' => 413], ApiException::class];
    }

    /**
     * @param array<string, mixed> $body
     * @param class-string         $expected
     */
    #[Test]
    #[DataProvider('legacyBodies')]
    public function mapsTheLegacyDialectOnExactStrings(int $status, array $body, string $expected): void
    {
        $response = new ApiResponse($status, ['content-type' => 'application/json', 'x-request-id' => 'rid'], (string) json_encode($body));
        $e = ErrorMapper::map($status, $body, $response);
        self::assertInstanceOf($expected, $e);
        self::assertInstanceOf(ApiException::class, $e);
        self::assertSame($body['message'], $e->getMessage(), 'the API wording is kept byte for byte');
        self::assertSame('rid', $e->requestId);
        self::assertNull($e->problemType);
    }

    #[Test]
    public function theLegacyOpen500IsAnOutageWithACollisionHintOnly(): void
    {
        $r = new ApiResponse(500, [], '');
        $e = ErrorMapper::map(500, ['message' => ErrorMapper::LEGACY_500_OPEN], $r);
        self::assertInstanceOf(ServerException::class, $e);
        self::assertSame(ServerException::class, $e::class, 'never an IdempotencyException: the string is the redacted answer for every open failure');
        self::assertTrue($e->mayBeIdempotencyCollision());
        self::assertSame(ErrorMapper::LEGACY_500_OPEN, $e->getMessage());

        $verify = ErrorMapper::map(500, ['message' => ErrorMapper::LEGACY_500_VERIFY], $r);
        self::assertInstanceOf(ServerException::class, $verify);
        self::assertFalse($verify->mayBeIdempotencyCollision());
        $other = ErrorMapper::map(502, ['message' => 'Bad gateway'], $r);
        self::assertInstanceOf(ServerException::class, $other);
        self::assertFalse($other->mayBeIdempotencyCollision());
    }

    #[Test]
    public function amountLimitsAreParsed(): void
    {
        $r = new ApiResponse(400, [], '');
        $below = ErrorMapper::map(400, ['message' => 'Amount is below the minimum deposit of 5'], $r);
        self::assertInstanceOf(AmountOutOfRangeException::class, $below);
        self::assertTrue($below->belowMinimum);
        self::assertSame('5', $below->limit);
        $above = ErrorMapper::map(400, ['message' => 'Amount exceeds the maximum deposit of 60000'], $r);
        self::assertInstanceOf(AmountOutOfRangeException::class, $above);
        self::assertTrue($above->aboveMaximum());
        self::assertSame('60000', $above->limit);
    }

    #[Test]
    public function rateLimitReadsHeadersOrBody(): void
    {
        $r = new ApiResponse(429, ['retry-after' => '60', 'x-ratelimit-limit' => '5', 'x-ratelimit-reset' => '1789034460'], '');
        $e = ErrorMapper::map(429, ['message' => 'Too Many Attempts.', 'status' => 429], $r);
        self::assertInstanceOf(RateLimitException::class, $e);
        self::assertSame(60, $e->retryAfter);
        self::assertSame(5, $e->limit);
        self::assertSame(1789034460, $e->resetAt);

        $v2 = ErrorMapper::map(429, ['type' => '/api/v2/problems/rate-limited', 'title' => 'Rate limited', 'status' => 429, 'detail' => 'Slow down.', 'retry_after_seconds' => 7], new ApiResponse(429, [], ''));
        self::assertInstanceOf(RateLimitException::class, $v2);
        self::assertSame(7, $v2->retryAfter);
        self::assertSame('/api/v2/problems/rate-limited', $v2->problemType);
    }

    #[Test]
    public function mapsTheRfc7807Dialect(): void
    {
        $r = new ApiResponse(422, ['content-type' => 'application/problem+json'], '');
        $v = ErrorMapper::map(422, ['type' => '/api/v2/problems/validation-error', 'title' => 'Validation failed', 'status' => 422, 'detail' => 'The request failed validation.', 'errors' => ['amount' => ['amount must have at most 2 decimal places']]], $r);
        self::assertInstanceOf(ValidationException::class, $v);
        self::assertSame('The request failed validation.', $v->getMessage());
        self::assertSame(['amount'], $v->fields());
        self::assertSame('amount must have at most 2 decimal places', $v->first('amount'));

        $idem = ErrorMapper::map(409, ['type' => '/api/v2/problems/conflict', 'title' => 'Conflict', 'status' => 409, 'detail' => 'Idempotency-Key reused with a different body.', 'code' => 'idempotency_key_reused'], $r);
        self::assertInstanceOf(IdempotencyException::class, $idem);
        self::assertSame('idempotency_key_reused', $idem->errorCode());

        $notOpen = ErrorMapper::map(409, ['type' => '/api/v2/problems/conflict', 'title' => 'Conflict', 'status' => 409, 'detail' => 'Checkout is not open.', 'code' => 'checkout_not_open', 'checkout_status' => 'paid'], $r);
        self::assertInstanceOf(CheckoutNotOpenException::class, $notOpen);
        self::assertSame('paid', $notOpen->body['checkout_status']);

        $other = ErrorMapper::map(409, ['type' => '/api/v2/problems/conflict', 'title' => 'Conflict', 'status' => 409, 'detail' => 'Cap reached.'], $r);
        self::assertSame(ConflictException::class, $other::class);

        $forbidden = ErrorMapper::map(403, ['type' => '/api/v2/problems/merchant-verification-required', 'title' => 'Verification required', 'status' => 403, 'detail' => 'KYC not cleared.', 'verification_status' => 'pending'], $r);
        self::assertInstanceOf(PermissionException::class, $forbidden);
        self::assertSame('/api/v2/problems/merchant-verification-required', $forbidden->problemType);

        $titleOnly = ErrorMapper::map(500, ['type' => '/api/v2/problems/internal-error', 'title' => 'Internal error', 'status' => 500], $r);
        self::assertInstanceOf(ServerException::class, $titleOnly);
        self::assertSame('Internal error', $titleOnly->getMessage());
    }

    #[Test]
    public function nonJsonErrorsAreUnexpected(): void
    {
        $e = ErrorMapper::map(502, null, new ApiResponse(502, [], '<html>bad gateway</html>'));
        self::assertInstanceOf(UnexpectedResponseException::class, $e);
        self::assertSame(502, $e->status);
        self::assertSame('<html>bad gateway</html>', $e->rawBody);
    }
}
