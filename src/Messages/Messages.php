<?php

declare(strict_types=1);

namespace DPay\Messages;

use DPay\Exceptions\AmountOutOfRangeException;
use DPay\Exceptions\ApiException;
use DPay\Exceptions\ConnectionException;
use DPay\Exceptions\DPayException;
use DPay\Exceptions\RateLimitException;
use DPay\Exceptions\ValidationException;

/**
 * The API's payer-facing refusals, said in the payer's language.
 *
 * The API answers in its legacy English, byte-exact. On the Arabic surface
 * the known refusals are matched by pattern (ported from
 * `platform/apps/checkout/lib/messages.ts`, first match wins) and said in
 * Arabic; anything unrecognised is returned as it came, so no reason is
 * ever lost — isolate it with `<bdi>` when rendering inside Arabic text.
 * The English surface keeps the API's wording untouched: it is what
 * merchant support recognises.
 *
 *   Messages::localize('OTP verification failed.', 'ar')
 *     → «رمز التحقق غير صحيح. يرجى المحاولة مرة أخرى.»
 */
final class Messages
{
    /** @var array<string, array{ar: string, en: string}> */
    public const TEXT = [
        'otpLocked' => ['ar' => 'تم تجاوز عدد المحاولات المسموح بها وأُغلقت جلسة الدفع.', 'en' => 'Too many OTP attempts. Payment session locked.'],
        'otpIncorrect' => ['ar' => 'رمز التحقق غير صحيح. يرجى المحاولة مرة أخرى.', 'en' => 'The code is incorrect. Please try again.'],
        'insufficientBalance' => ['ar' => 'الرصيد غير كافٍ لإتمام العملية.', 'en' => 'Insufficient balance to complete the payment.'],
        'customerNotFound' => ['ar' => 'لم يتم العثور على هذا الرقم لدى المصرف. تحقق منه وحاول مرة أخرى.', 'en' => 'The bank could not find this number. Check it and try again.'],
        'limitExceeded' => ['ar' => 'المبلغ يتجاوز الحد المسموح به لحسابك.', 'en' => 'The amount is outside the limits of your account.'],
        'crossBankCard' => ['ar' => 'هذه البطاقة تتبع مصرفاً آخر؛ استخدم OnePay للدفع بها.', 'en' => 'This card belongs to a different bank; use OnePay to pay with it.'],
        'interbankInitFailed' => ['ar' => 'تعذّر بدء الدفع عبر الشبكة المصرفية المشتركة (OnePay). لم يتم خصم أي مبلغ؛ حاول لاحقاً أو استخدم بطاقة من مصرف آخر.', 'en' => 'The interbank network (OnePay) could not start this payment. Nothing was charged; try again later or use a card from another bank.'],
        'cardNotAcceptedHere' => ['ar' => 'هذا النوع من البطاقات غير مقبول هنا. يرجى استخدام بطاقة أخرى.', 'en' => 'This card type is not accepted here. Please try a different card.'],
        'cardDeclined' => ['ar' => 'تم رفض البطاقة. يرجى استخدام بطاقة أخرى.', 'en' => 'Your card was declined. Please try a different card.'],
        'alreadyPaidText' => ['ar' => 'تمت معالجة هذه الدفعة بنجاح بالفعل.', 'en' => 'This payment has already been processed successfully.'],
        'checkoutNotOpen' => ['ar' => 'جلسة الدفع هذه لم تعد مفتوحة.', 'en' => 'This checkout session is no longer open.'],
        'sessionExpiredDesc' => ['ar' => 'انتهت صلاحية جلسة الدفع.', 'en' => 'This payment session has expired.'],
        'notPayable' => ['ar' => 'هذه الفاتورة غير متاحة للدفع.', 'en' => 'This invoice is not available for payment.'],
        'sessionNotPayable' => ['ar' => 'جلسة الدفع هذه لم تعد قابلة للدفع.', 'en' => 'This payment session is no longer payable.'],
        'notFoundDesc' => ['ar' => 'رابط الدفع غير صحيح أو لم يعد متاحاً.', 'en' => 'This payment link is not valid or is no longer available.'],
        'methodUnavailable' => ['ar' => 'طريقة الدفع هذه غير متاحة حالياً.', 'en' => 'This payment method is not available right now.'],
        'cardUnavailable' => ['ar' => 'الدفع بالبطاقة غير متاح مؤقتاً. يرجى اختيار طريقة أخرى.', 'en' => 'Card payments are temporarily unavailable. Please choose another method.'],
        'cardAmountChanged' => ['ar' => 'تغيّر المبلغ الذي سيُخصم من البطاقة منذ فتح هذه الصفحة. يرجى مراجعته والمحاولة مرة أخرى.', 'en' => 'The card amount has changed since this page was loaded. Please review it and try again.'],
        'serviceUnavailableDesc' => ['ar' => 'تعذر الاتصال بخدمة الدفع. يرجى المحاولة بعد قليل.', 'en' => 'We could not reach the payment service. Please try again shortly.'],
        'gatewayDeclined' => ['ar' => 'رفضت بوابة الدفع العملية. لم يتم خصم أي مبلغ.', 'en' => 'The payment gateway declined the transaction. Nothing was charged.'],
        'jsVerifyFailed' => ['ar' => 'فشل التحقق.', 'en' => 'Verification failed.'],
        'errorOccurred' => ['ar' => 'حدث خطأ، يرجى المحاولة مرة أخرى', 'en' => 'An error occurred. Please try again.'],
        'jsInitiateFailed' => ['ar' => 'فشل في بدء عملية الدفع.', 'en' => 'Failed to initiate payment.'],
        // SDK-only keys (not in the checkout table): shapes the API answers with a number in them.
        'amountBelowMinimum' => ['ar' => 'المبلغ أقل من الحد الأدنى المسموح به (:limit د.ل).', 'en' => 'Amount is below the minimum deposit of :limit.'],
        'amountAboveMaximum' => ['ar' => 'المبلغ يتجاوز الحد الأقصى المسموح به (:limit د.ل).', 'en' => 'Amount exceeds the maximum deposit of :limit.'],
        'tooManyAttempts' => ['ar' => 'محاولات كثيرة. يرجى الانتظار :seconds ثانية ثم المحاولة مرة أخرى.', 'en' => 'Too many attempts. Please wait :seconds seconds and try again.'],
        'validationFailed' => ['ar' => 'بعض البيانات المدخلة غير صحيحة. يرجى مراجعتها والمحاولة مرة أخرى.', 'en' => 'Some of the entered details are invalid. Please review them and try again.'],
        'unauthenticated' => ['ar' => 'تعذر التحقق من مفتاح الربط. راجع إعدادات DPay.', 'en' => 'The API token was not accepted. Check your DPay settings.'],
        'sandboxOtpHint' => ['ar' => 'رمز التحقق غير صحيح. في وضع التجربة استخدم 111111 للنجاح أو 000000 لمحاكاة الفشل.', 'en' => 'Invalid OTP. In sandbox mode, use 111111 for success or 000000 to simulate final failure.'],
        'idempotencyKeyReused' => ['ar' => 'أُعيد استخدام مفتاح الطلب (Idempotency-Key) مع بيانات مختلفة. أنشئ محاولة دفع جديدة.', 'en' => 'This Idempotency-Key was already used with a different request body. Start a new payment attempt.'],
    ];

    /**
     * v2 RFC 7807 `code`s said in the payer's language on both surfaces —
     * their `detail` varies («…is paid and can no longer be cancelled.»),
     * the code does not. SDK-only: the hosted checkout never shows these.
     */
    public const CODES = [
        'checkout_not_open' => 'checkoutNotOpen',
        'idempotency_key_reused' => 'idempotencyKeyReused',
    ];

    /** Internal checkout codes, translated on both surfaces. */
    private const INTERNAL_CODES = [
        'request_failed' => 'jsInitiateFailed',
        'upstream_unreachable' => 'serviceUnavailableDesc',
    ];

    /** The SDK's own rules, ahead of the platform's (shapes with a number in them, and auth). */
    public const SDK_RULES_COUNT = 5;

    /**
     * Ordered: the first pattern that matches wins. The first
     * {@see Messages::SDK_RULES_COUNT} entries are the SDK's own; the rest
     * is a verbatim port of `platform/apps/checkout/lib/messages.ts` RULES
     * in the same order ({@see \DPay\Tests\Unit\Messages\MessagesTest}
     * diffs the two inside the monorepo).
     *
     * @var list<array{0: string, 1: string}>
     */
    public const RULES = [
        ['/^Amount is below the minimum deposit of/i', 'amountBelowMinimum'],
        ['/^Amount exceeds the maximum deposit of/i', 'amountAboveMaximum'],
        ['/^Too Many Attempts\.?$/i', 'tooManyAttempts'],
        ['/^Unauthenticated\.?$|sandbox api token|invalid sandbox api token/i', 'unauthenticated'],
        ['/In sandbox mode, use 111111/i', 'sandboxOtpHint'],
        ['/too many otp attempts|session locked/i', 'otpLocked'],
        ['/invalid otp|otp verification failed|pin is (not correct|incorrect)|bad otp|incorrect (otp|code|pin)/i', 'otpIncorrect'],
        ['/insufficient/i', 'insufficientBalance'],
        ['/customer (is|was) not found/i', 'customerNotFound'],
        ['/out of your limits/i', 'limitExceeded'],
        ['/cross-bank|different bank/i', 'crossBankCard'],
        ['/init payment api failed/i', 'interbankInitFailed'],
        ['/card type is not accepted here/i', 'cardNotAcceptedHere'],
        ['/card (was )?declined|different card/i', 'cardDeclined'],
        ['/already (paid|verified|processed)/i', 'alreadyPaidText'],
        // A34 — the hosted checkout's own refusals: a tile pressed after the
        // checkout closed, and an attempt id that is not this checkout's.
        ['/checkout session is no longer open/i', 'checkoutNotOpen'],
        ['/does not belong to this checkout/i', 'errorOccurred'],
        ['/expired/i', 'sessionExpiredDesc'],
        ['/invoice is not available/i', 'notPayable'],
        ['/not payable|not in a payable state|not pending/i', 'sessionNotPayable'],
        ['/session not found|^not found\.?$|invoice not found/i', 'notFoundDesc'],
        ['/payment method|unsupported payment method|not configured for this merchant|gateway (is )?not configured/i', 'methodUnavailable'],
        ['/card payments are temporarily unavailable/i', 'cardUnavailable'],
        ['/card amount has changed/i', 'cardAmountChanged'],
        ['/temporarily unavailable|unreachable|unexpected response/i', 'serviceUnavailableDesc'],
        ['/declined|transaction failed|فشلت العملية/i', 'gatewayDeclined'],
        ['/verification failed|securehash/i', 'jsVerifyFailed'],
        ['/processing error|unable to start checkout/i', 'errorOccurred'],
    ];

    /** @param array<string, string|int> $params `:name` placeholders */
    public static function localize(?string $message, string $locale = 'ar', array $params = []): string
    {
        $text = trim((string) $message);
        if ($text === '') {
            return '';
        }
        $internal = self::INTERNAL_CODES[$text] ?? null;
        if ($internal !== null) {
            return self::text($internal, $locale, $params);
        }
        if ($locale !== 'ar') {
            return $text;
        }
        $key = self::keyFor($text);

        return $key === null ? $text : self::text($key, $locale, $params);
    }

    /** The message key a raw API string maps to, or null when unrecognised. */
    public static function keyFor(string $message): ?string
    {
        foreach (self::RULES as [$pattern, $key]) {
            if (preg_match($pattern, $message) === 1) {
                return $key;
            }
        }

        return null;
    }

    /** @param array<string, string|int> $params */
    public static function text(string $key, string $locale = 'ar', array $params = []): string
    {
        $entry = self::TEXT[$key] ?? null;
        if ($entry === null) {
            return $key;
        }
        $out = $entry[$locale === 'ar' ? 'ar' : 'en'];
        foreach ($params as $name => $value) {
            $out = str_replace(':'.$name, (string) $value, $out);
        }

        return $out;
    }

    /** Say an SDK exception to the customer (Arabic first); the raw API wording stays on `$e->getMessage()`. */
    public static function forException(DPayException $e, string $locale = 'ar'): string
    {
        if ($e instanceof AmountOutOfRangeException) {
            return self::text($e->belowMinimum ? 'amountBelowMinimum' : 'amountAboveMaximum', $locale, ['limit' => $e->limit ?? '']);
        }
        if ($e instanceof RateLimitException) {
            return self::text('tooManyAttempts', $locale, ['seconds' => $e->retryAfter ?? 60]);
        }
        if ($e instanceof ValidationException) {
            $first = $e->first($e->fields()[0] ?? '');
            $localized = $first === null ? null : self::localize($first, $locale);

            return $localized !== null && $localized !== $first ? $localized : self::text('validationFailed', $locale);
        }
        if ($e instanceof ConnectionException) {
            return self::text('serviceUnavailableDesc', $locale);
        }
        if ($e instanceof ApiException) {
            $code = $e->errorCode();
            $byCode = $code === null ? null : (self::CODES[$code] ?? null);
            if ($byCode !== null) {
                return self::text($byCode, $locale);
            }

            return self::localize($e->getMessage(), $locale);
        }

        return self::text('errorOccurred', $locale);
    }
}
