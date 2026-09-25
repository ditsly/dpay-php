<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Messages;

use DPay\Exceptions\AmountOutOfRangeException;
use DPay\Exceptions\CheckoutNotOpenException;
use DPay\Exceptions\ConnectionException;
use DPay\Exceptions\IdempotencyException;
use DPay\Exceptions\OtpRejectedException;
use DPay\Exceptions\RateLimitException;
use DPay\Exceptions\ValidationException;
use DPay\Messages\Messages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MessagesTest extends TestCase
{
    /** @return iterable<string, array{string, string}> the checkout app's own examples plus every controller string */
    public static function arabic(): iterable
    {
        yield 'otp failed' => ['OTP verification failed.', 'رمز التحقق غير صحيح. يرجى المحاولة مرة أخرى.'];
        yield 'edfali pin' => ['EDFali PIN is not correct', 'رمز التحقق غير صحيح. يرجى المحاولة مرة أخرى.'];
        yield 'locked' => ['Too many OTP attempts. Payment session locked.', 'تم تجاوز عدد المحاولات المسموح بها وأُغلقت جلسة الدفع.'];
        yield 'customer not found' => ['The customer is not found in the bank', 'لم يتم العثور على هذا الرقم لدى المصرف. تحقق منه وحاول مرة أخرى.'];
        yield 'limits' => ['The required amount is out of your limits', 'المبلغ يتجاوز الحد المسموح به لحسابك.'];
        yield 'cross bank' => ['This card belongs to a different bank. Enable OnePay on this gateway to accept cross-bank payments.', 'هذه البطاقة تتبع مصرفاً آخر؛ استخدم OnePay للدفع بها.'];
        yield 'already verified' => ['Payment already verified', 'تمت معالجة هذه الدفعة بنجاح بالفعل.'];
        yield 'expired' => ['Payment session has expired', 'انتهت صلاحية جلسة الدفع.'];
        yield 'not pending' => ['Payment session is not pending', 'جلسة الدفع هذه لم تعد قابلة للدفع.'];
        yield 'not found' => ['Payment session not found', 'رابط الدفع غير صحيح أو لم يعد متاحاً.'];
        yield 'invoice not found' => ['Invoice not found', 'رابط الدفع غير صحيح أو لم يعد متاحاً.'];
        yield 'not active' => ['Payment method is not active', 'طريقة الدفع هذه غير متاحة حالياً.'];
        yield 'disabled' => ['This payment method is currently disabled', 'طريقة الدفع هذه غير متاحة حالياً.'];
        yield 'unsupported' => ['Unsupported payment method: sadad', 'طريقة الدفع هذه غير متاحة حالياً.'];
        yield '503' => ['The payment provider is temporarily unavailable. Please try again in a few minutes.', 'تعذر الاتصال بخدمة الدفع. يرجى المحاولة بعد قليل.'];
        yield 'sandbox decline' => ['Sandbox: gateway declined the transaction (simulated final failure)', 'رفضت بوابة الدفع العملية. لم يتم خصم أي مبلغ.'];
        yield 'reference mismatch' => ['Payment verification failed: reference mismatch', 'فشل التحقق.'];
        yield 'sandbox otp' => ['Invalid OTP. In sandbox mode, use 111111 for success or 000000 to simulate final failure.', 'رمز التحقق غير صحيح. في وضع التجربة استخدم 111111 للنجاح أو 000000 لمحاكاة الفشل.'];
        yield 'too many' => ['Too Many Attempts.', 'محاولات كثيرة. يرجى الانتظار :seconds ثانية ثم المحاولة مرة أخرى.'];
        yield 'unauthenticated' => ['Unauthenticated.', 'تعذر التحقق من مفتاح الربط. راجع إعدادات DPay.'];
        yield 'below min' => ['Amount is below the minimum deposit of 5', 'المبلغ أقل من الحد الأدنى المسموح به (:limit د.ل).'];
        yield 'internal code' => ['upstream_unreachable', 'تعذر الاتصال بخدمة الدفع. يرجى المحاولة بعد قليل.'];
        yield 'card declined' => ['Your card was declined. Please try a different card.', 'تم رفض البطاقة. يرجى استخدام بطاقة أخرى.'];
        yield 'card type' => ['This card type is not accepted here. Please try a different card.', 'هذا النوع من البطاقات غير مقبول هنا. يرجى استخدام بطاقة أخرى.'];
        yield 'interbank' => ['init payment api failed', 'تعذّر بدء الدفع عبر الشبكة المصرفية المشتركة (OnePay). لم يتم خصم أي مبلغ؛ حاول لاحقاً أو استخدم بطاقة من مصرف آخر.'];
        yield 'checkout not open (A34)' => ['This checkout session is no longer open.', 'جلسة الدفع هذه لم تعد مفتوحة.'];
        yield 'attempt not of this checkout (A34)' => ['This attempt does not belong to this checkout.', 'حدث خطأ، يرجى المحاولة مرة أخرى'];
    }

    /**
     * Inside the monorepo, RULES (after the SDK's own) must be the platform's
     * `messages.ts` RULES, pattern for pattern and in the same order; TEXT must
     * hold every key they name. Outside the monorepo the bundled table is the port.
     */
    #[Test]
    public function rulesArePortedVerbatimFromTheCheckoutApp(): void
    {
        $file = __DIR__.'/../../../../../platform/apps/checkout/lib/messages.ts';
        if (!is_file($file)) {
            self::markTestSkipped('platform checkout app not present (standalone checkout)');
        }
        $source = (string) file_get_contents($file);
        $start = strpos($source, 'const RULES');
        $end = strpos($source, '];', (int) $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        preg_match_all('#/((?:[^/\\\\]|\\\\.)+)/i,\s*\'(\w+)\'#', substr($source, $start, $end - $start), $m, PREG_SET_ORDER);
        $platform = array_map(static fn (array $r): array => ['/'.$r[1].'/i', $r[2]], $m);
        self::assertGreaterThan(20, count($platform), 'messages.ts RULES parsed');

        $sdk = array_slice(Messages::RULES, Messages::SDK_RULES_COUNT);
        self::assertSame($platform, $sdk, 'Messages::RULES has drifted from platform/apps/checkout/lib/messages.ts');
        foreach (Messages::RULES as [, $key]) {
            self::assertArrayHasKey($key, Messages::TEXT, "TEXT lacks the key \"$key\" a rule names");
        }
        foreach (Messages::CODES as $key) {
            self::assertArrayHasKey($key, Messages::TEXT);
        }
    }

    #[Test]
    public function v2CodesAreSaidByCodeNotByDetail(): void
    {
        // The 409 details vary («…is paid and can no longer be cancelled.»); the code is the stable key.
        $notOpen = new CheckoutNotOpenException('This checkout session is paid and can no longer be cancelled.', 409, ['code' => 'checkout_not_open', 'checkout_status' => 'paid']);
        self::assertSame('جلسة الدفع هذه لم تعد مفتوحة.', Messages::forException($notOpen));
        self::assertSame('This checkout session is no longer open.', Messages::forException($notOpen, 'en'));
        $reused = new IdempotencyException('This Idempotency-Key was already used with a different request body.', 409, ['code' => 'idempotency_key_reused']);
        self::assertSame('أُعيد استخدام مفتاح الطلب (Idempotency-Key) مع بيانات مختلفة. أنشئ محاولة دفع جديدة.', Messages::forException($reused));
        self::assertSame('This Idempotency-Key was already used with a different request body. Start a new payment attempt.', Messages::forException($reused, 'en'));
    }

    #[Test]
    #[DataProvider('arabic')]
    public function saysKnownRefusalsInArabic(string $api, string $ar): void
    {
        self::assertSame($ar, Messages::localize($api, 'ar'));
    }

    #[Test]
    public function keepsTheApiWordingOnTheEnglishSurfaceAndForUnknownStrings(): void
    {
        self::assertSame('OTP verification failed.', Messages::localize('OTP verification failed.', 'en'));
        self::assertSame('Something brand new', Messages::localize('Something brand new', 'ar'));
        self::assertSame('', Messages::localize(null));
        self::assertSame('Failed to initiate payment.', Messages::localize('request_failed', 'en'), 'internal codes translate on both surfaces');
        self::assertSame('فشل في بدء عملية الدفع.', Messages::localize('request_failed', 'ar'));
        self::assertSame('Failed to initiate payment.', Messages::text('jsInitiateFailed', 'en'));
    }

    #[Test]
    public function firstMatchingRuleWins(): void
    {
        // "…different card" also matches cardDeclined; the card-type rule sits first.
        self::assertSame('cardNotAcceptedHere', Messages::keyFor('This card type is not accepted here. Please try a different card.'));
        self::assertSame('otpLocked', Messages::keyFor('Too many OTP attempts. Payment session locked.'));
        self::assertNull(Messages::keyFor('nothing known'));
    }

    #[Test]
    public function exceptionsGetTheirParameters(): void
    {
        $below = new AmountOutOfRangeException('Amount is below the minimum deposit of 5', true, '5');
        self::assertSame('المبلغ أقل من الحد الأدنى المسموح به (5 د.ل).', Messages::forException($below));
        self::assertSame('Amount is below the minimum deposit of 5.', Messages::forException($below, 'en'));
        $rate = new RateLimitException('Too Many Attempts.', 42);
        self::assertSame('محاولات كثيرة. يرجى الانتظار 42 ثانية ثم المحاولة مرة أخرى.', Messages::forException($rate));
        $otp = new OtpRejectedException('EDFali PIN is not correct', 422);
        self::assertSame('رمز التحقق غير صحيح. يرجى المحاولة مرة أخرى.', Messages::forException($otp));
        self::assertSame('EDFali PIN is not correct', Messages::forException($otp, 'en'));
        $validation = new ValidationException('The customer mobile field format is invalid.', ['customer_mobile' => ['The customer mobile field format is invalid.']]);
        self::assertSame('بعض البيانات المدخلة غير صحيحة. يرجى مراجعتها والمحاولة مرة أخرى.', Messages::forException($validation));
        self::assertSame('تعذر الاتصال بخدمة الدفع. يرجى المحاولة بعد قليل.', Messages::forException(new ConnectionException('boom')));
    }
}
