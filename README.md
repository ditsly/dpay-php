# DPay PHP SDK — `dpay/dpay-php`

<div dir="rtl">

## حزمة DPay الرسمية للغة PHP

الطريقة الرسمية للربط مع DPay من أي تطبيق PHP (‏8.1 فما فوق): صفحة الدفع المستضافة لكل وسائل الدفع (إدفعلي، سداد، موبي كاش، مصارف MITF، معاملات، ماستركارد)، والواجهة الخام لكل وسيلة على حدة، والتحقق من توقيع الويب هوك، وبيئة التجربة.

- **بلا أرقام هواتف ولا بطاقات ولا رموز تحقق على خادمك** عند استخدام صفحة الدفع المستضافة.
- **المبالغ نصوص عشرية دائماً** (`"125.50"`) — لا أرقام عشرية عائمة أبداً، وقاعدة التقريب مطابقة للمنصة بتّاً بتّ.
- **المصادر الموثوقة**: اتصال HTTPS فقط بمضيفي DPay، ولا يتبع أي تحويل، والتحقق من TLS دائماً مفعّل.
- **رسائل عربية أولاً** عبر `Messages`، مع الاحتفاظ بنص الـ API الإنجليزي كما هو للدعم الفني.

### التثبيت

```bash
composer require dpay/dpay-php guzzlehttp/guzzle
```

الحزمة لا تفرض Guzzle؛ أي عميل PSR-18 يفي بالغرض. عند وجود Guzzle تبنيه الحزمة بنفسها بإعدادات آمنة (بدون تحويلات، مهلة 15 ثانية، التحقق من TLS)، وترفض عميل Guzzle مُمرَّراً يتبع التحويلات أو يعطّل التحقق من TLS. مع أي عميل PSR-18 آخر لا تستطيع الحزمة قراءة إعداداته — ابنه أنت بلا تحويلات ومع التحقق من TLS (في Symfony: `max_redirects => 0`).

### البداية السريعة — صفحة الدفع المستضافة (موصى بها)

أربع خطوات لا غير: **إنشاء** جلسة دفع ← **تحويل** العميل إلى `url` ← **إعادة قراءة** الحالة عند العودة ← **استقبال الويب هوك** والمطابقة الدورية.

```php
use DPay\Client;
use DPay\Idempotency\KeyFactory;
use DPay\Requests\CreateCheckoutSessionRequest;

$dpay = Client::fromToken(getenv('DPAY_API_TOKEN')); // sb_tk_… ⇒ بيئة التجربة تلقائياً
$keys = new KeyFactory('my-shop', $storeUid);         // storeUid: قيمة عشوائية تُولَّد مرة واحدة عند التثبيت

$checkout = $dpay->checkoutSessions()->create(
    CreateCheckoutSessionRequest::of('125.50', 'https://shop.ly/dpay/return?order=10483&key=k9')
        ->reference('10483')
        ->description('طلب رقم 10483')
        ->cancelUrl('https://shop.ly/cart')
        ->metadata(['order_id' => 10483, 'platform' => 'my-shop'])
        ->customer(name: 'سالم علي', phone: '0912345678')
        ->locale('ar'),
    $keys->forCheckout(10483, attempt: 1),
);

// احفظ $checkout->id و $checkout->url و $checkout->expiresAt على الطلب، ثم:
header('Location: '.$checkout->url, true, 303);
```

عند عودة العميل، **لا تثق بالحالة في رابط العودة** — أعد قراءتها:

```php
use DPay\Models\CheckoutStatus;
use DPay\ReturnUrl\ReturnUrl;

$hint = ReturnUrl::parse($_GET);                                  // مجرد تلميح
if (!$hint->isCheckout()) {                                       // فُتح الرابط يدوياً أو بلا معرّف
    http_response_code(400); exit;
}
$order = $orders->findByCheckoutId($hint->requireCheckoutId());   // ابحث عن الطلب بالمعرّف الذي حفظته عند الإنشاء
if ($order === null) {
    http_response_code(404); exit;                                // معرّف لا يخص أي طلب لدينا
}
$checkout = $dpay->checkoutSessions()->get($order->checkoutId);   // المعرّف المحفوظ لا الوارد في الرابط

if (!$checkout->matchesOrder($order->total, 'LYD', (string) $order->id)) {
    // لا تُكمل الطلب — سجّل ملاحظة للمراجعة
}
if ($checkout->status === CheckoutStatus::Paid) {
    $order->markPaid($checkout->payment->txId, $checkout->payment->amountCharged); // المبلغ المخصوم فعلاً (2 خانات عشرية)
}
```

استقبل الويب هوك الموقّع (سجّل رابطك في لوحة التحكم ← Build ← Webhooks):

```php
use DPay\Config\Environment;
use DPay\Exceptions\WebhookEnvironmentMismatchException;
use DPay\Exceptions\WebhookException;
use DPay\Webhooks\Verifier;
use DPay\Webhooks\WebhookEvent;

$verifier = new Verifier(getenv('DPAY_WEBHOOK_SECRET'), Environment::Live);
try {
    $event = $verifier->verifyGlobals();          // php://input + X-DPAY-* — التحقق على البايتات كما وصلت
} catch (WebhookEnvironmentMismatchException) {
    http_response_code(200); exit;                // حدث من بيئة التجربة على متجر حي (أو العكس): أقرّ بالاستلام وتجاهله
} catch (WebhookException $e) {
    http_response_code(401); exit;                // توقيع خاطئ أو مفقود، أو طابع زمني قديم: لا تُجب بـ 2xx أبداً
}
// أزل التكرار عبر $event->dedupeKey()، ثم:
if ($event->is(WebhookEvent::CheckoutCompleted)) {
    // نفس دالة الانتقال المستخدمة في صفحة العودة
}
http_response_code(200);
```

وشغّل مطابقة كل 5 دقائق (`checkoutSessions()->get()` لكل طلب معلّق) — هذا هو مسار التسوية الفعلي للمتاجر التي لا يصلها الويب هوك (استضافة HTTP فقط أو خلف عنوان خاص). انظر `examples/hosted-checkout/`.

### بيئة التجربة

نفس الشيفرة تماماً مع رمز `sb_tk_…` من لوحة التحكم: المعرّفات `cs_test_…`، رموز التحقق السحرية `111111` (نجاح) و`000000` (فشل نهائي)، `sadad` و`mpgs` غير متاحتين في التجربة، والويب هوك يصل بـ `live: false` — وترفضه `Verifier` تلقائياً إن كان متجرك في الوضع الحي.

### الواجهة الخام لكل وسيلة (للمطوّرين الذين يبنون واجهتهم بنفسهم)

```php
use DPay\Requests\OpenSessionRequest;

$opened = $dpay->paymentSessions()->open(
    OpenSessionRequest::edfali('75.50', '0912345678')->withData(['order_id' => 10483]),
    $keys->forOrder(10483, 1, 'edfali'),
);
// $opened->total = 76.255 (3 خانات) لكن المخصوم فعلاً $opened->charge() = 76.26
$result  = $dpay->paymentSessions()->verify($opened->sessionId, $otpFromCustomer);
$session = $dpay->paymentSessions()->get($opened->sessionId);
```

تحذيرات مهمة: التحقق من رمز OTP محدود بـ **5 محاولات في الدقيقة لكل عنوان IP** على المسار العام؛ لا تعيد المحاولة تلقائياً بل اعرض `RateLimitException::$retryAfter`. وماستركارد على هذا المسار تُحصّل **بالدولار** بلا تحويل — استخدم صفحة الدفع المستضافة لبطاقات ماستركارد.

### الأخطاء

كل استثناء يطبّق `DPay\Exceptions\DPayException`؛ `getMessage()` يحمل نص الـ API حرفياً، و`Messages::forException($e, 'ar')` يقوله بالعربية. أهمها: `OtpRejectedException` (الجلسة ما زالت معلّقة)، `SessionLockedException` (5 محاولات خاطئة — افتح جلسة جديدة)، `SessionExpiredException`، `AmountOutOfRangeException`، `CrossBankCardException`، `RateLimitException`، `ProviderUnavailableException`، `IdempotencyException` (‏409 `idempotency_key_reused` فقط)، `CheckoutNotOpenException`، و`ServerException` لكل 5xx — بما فيها 500 «Payment processing failed…» على مسار الفتح القديم، وهي إجابة عامة لأي فشل في الفتح وليست دليلاً على تكرار المفتاح: أعد قراءة الجلسة أو أرسل نفس المفتاح ولا تولّد مفتاحاً جديداً.

</div>

---

## English

The official DPay SDK for PHP 8.1+: hosted checkout for every payment method (EDFali, Sadad, MobiCash, the MITF banks, Moamalat, Mastercard), the raw per-method API, signed-webhook verification and the sandbox — with money as decimal strings, an exception per API refusal, and Arabic-first customer messages.

- Requires PHP >= 8.1, `ext-json`, a PSR-18 client + PSR-17 factories (Guzzle recommended; discovered automatically).
- Tested on PHP 8.1, 8.2, 8.3, 8.4 and 8.5 (`tools/matrix.sh` runs the whole gate set in Docker).
- Zero floats in money: `Money` holds decimal strings; the platform's rounding (`PhpRound`) is ported so `round(2.675, 2)` is `2.68` on every PHP version, proven against the platform's golden vectors.

### Install

```bash
composer require dpay/dpay-php guzzlehttp/guzzle
```

The SDK depends on the PSR virtual packages, not on Guzzle. When Guzzle is present and you inject nothing, the SDK builds it hardened: `allow_redirects: false`, `verify: true`, 15 s timeout, 5 s connect. An **injected Guzzle** client is checked for the same two settings and refused when it follows redirects or skips TLS verification (a 307/308 would re-send the payment body wherever `Location` points). With any other PSR-18 client the SDK cannot read the settings — build it with redirects off and TLS on yourself; the SDK refuses to read a redirect regardless, and `dpay:doctor` (Laravel) names the client class and whether it could verify it:

```php
// Symfony HttpClient: redirects are ON by default (max_redirects = 20) — turn them off.
use DPay\Http\PsrTransport;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

$psr18 = new Psr18Client(HttpClient::create(['max_redirects' => 0, 'verify_peer' => true, 'timeout' => 15]));
$dpay = Client::live($token, ['transport' => new PsrTransport($psr18, $psr18, $psr18)]);
```

### Quickstart — hosted checkout (recommended)

```php
use DPay\Client;
use DPay\Idempotency\KeyFactory;
use DPay\Requests\CreateCheckoutSessionRequest;

$dpay = Client::live($integrationToken);        // Dashboard → Settings → API tokens (role:api)
// $dpay = Client::sandbox($sbToken);            // sb_tk_… — same code, simulated banks
$keys = new KeyFactory('my-shop', $storeUid);   // storeUid: KeyFactory::generateStoreUid() once per install

$checkout = $dpay->checkoutSessions()->create(
    CreateCheckoutSessionRequest::of('125.50', 'https://shop.ly/dpay/return?order=10483&key=k9')
        ->reference('10483')
        ->description('Order #10483')
        ->cancelUrl('https://shop.ly/cart')
        ->metadata(['order_id' => 10483, 'platform' => 'my-shop', 'plugin_version' => '1.0.0'])
        ->customer(name: 'Salem Ali', phone: '0912345678')
        ->expiresInMinutes(60)
        ->locale('ar'),
    $keys->forCheckout(10483, attempt: 1),      // same key + same body ⇒ the same session back (replay)
);

// persist $checkout->id, ->url, ->expiresAt on the order; mark it "pending payment"
header('Location: '.$checkout->url, true, 303);
```

**Return** — the query string (`checkout_session_id`, `status`, `payment_id`) is a hint; the API is the truth:

```php
$hint = ReturnUrl::parse($_GET);
if (!$hint->isCheckout()) { return 400; }                                  // opened by hand / no id
$order = $orders->findByCheckoutId($hint->requireCheckoutId());           // the id you stored at create time
if ($order === null) { return 404; }                                       // not an order of ours
$checkout = $dpay->checkoutSessions()->get($order->checkoutId);           // the STORED id, not the query's

if (!$checkout->matchesOrder($order->total, 'LYD', (string) $order->id)) { /* flag, never complete */ }

match ($checkout->status) {
    CheckoutStatus::Paid      => $order->complete($checkout->payment->txId, $checkout->payment->amountCharged),
    CheckoutStatus::Open      => /* "payment not completed — try again": link $checkout->url */,
    CheckoutStatus::Expired   => /* new checkout, attempt + 1 */,
    CheckoutStatus::Cancelled => /* cancelled by the store */,
};
```

**Webhook** — verify over the raw bytes, dedupe, answer 2xx within 15 s:

```php
$verifier = new Verifier(getenv('DPAY_WEBHOOK_SECRET'), Environment::Live);   // or [$new, $old] during rotation
try {
    $event = $verifier->verify($request->getContent(), $request->headers->all());
} catch (WebhookEnvironmentMismatchException) { return 200; /* sandbox event on a live store: ignore */ }
  catch (WebhookException $e)                 { return 401; }

if ($event->isTest()) { return 200; }
if (!$store->firstTimeSeen($event->dedupeKey())) { return 200; }   // (live, id, event)
if ($event->is(WebhookEvent::CheckoutCompleted)) { $transition($event->checkoutSessionId(), $event->payment()); }
return 200;
```

**Reconcile** every 5 minutes with `checkoutSessions()->get()` for pending orders (≤ 30 reads per run). Stores on HTTP-only or private hosts cannot receive webhooks at all (DPay refuses those targets) — for them the reconciler *is* the settlement path.

Full runnable versions: [`examples/hosted-checkout/`](examples/hosted-checkout).

### Sandbox

`Client::sandbox('sb_tk_…')`. Every live/sandbox difference lives in one place, `DPay\Config\Environment`: `/api/sandbox` paths, `cs_test_` ids, `sadad`/`mpgs` withheld, flat 5-minute lazy session expiry, decimal-string amounts and `sandbox: true` on v1 bodies, 2dp fees, magic OTPs `111111` / `000000`, webhooks with `live: false` (refused by a live `Verifier`). `$dpay->sandboxTools()->simulatePaid($sessionId)` settles a raw session.

### Raw per-method surface

```php
$methods = $dpay->payMethods()->usable();                 // active + configured + enabled (+ not hidden in sandbox)

$opened = $dpay->paymentSessions()->open(
    OpenSessionRequest::edfali('75.50', '0912345678')     // sadad(...), mobicash(...), mitf(Gateway::YousrPay, ...),
        ->withData(['order_id' => 10483]),                // moamalat(..., returnUrl), mpgs(...)
    $keys->forOrder(10483, 1, 'edfali'),
);
$opened->feeAmount;   // "0.755"  (3dp)      $opened->total;    // "76.255" (3dp)
$opened->charge();    // "76.26"  — what the payer is debited and what every later read reports
$opened->paymentLink; // Moamalat / Mastercard: redirect the customer here; OTP methods: null

$result  = $dpay->paymentSessions()->verify($opened->sessionId, $otp);   // never retried
$session = $dpay->paymentSessions()->get($opened->sessionId);            // authoritative
$page    = $dpay->payments()->list();                                     // settled records (live only)
```

Read before shipping this surface:

- **Verify is throttled 5/min per caller IP** on the public route. Never auto-retry; show `RateLimitException::$retryAfter`.
- After 5 wrong OTPs EDFali/Sadad lock the session (`SessionLockedException`, session `failed`) — open a new one (attempt + 1, new idempotency key).
- **Mastercard on this path charges USD with no FX** and its hosted page returns only to the dashboard-configured pivot URL. Use the hosted checkout for cards.
- The open response's `total` is 3dp; the charge is `round(total, 2)` (`FeeMath::charge()`, `OpenedSession::charge()`). Compare limits (`min_deposit`/`max_deposit`) against the raw amount.
- `Idempotency-Key` is **1..64 characters** on both surfaces (`KeyFactory` keys are 43). The v1 column is `varchar(64)` and the live open runs the bank leg *before* the insert, so the SDK refuses a longer key locally rather than let the customer receive an OTP for a session that then answers 500. Since A34 the sandbox honours the key too; `OpenedSession::$replayed` is detected on both surfaces.
- Field rules are local value objects with the API's own messages: `LibyanMobile` (`^0?9[1-9]\d{7}$`, Arabic-Indic digits folded), `BankCardNumber::forMobiCash()` (7 digits), `::forMitf()` (7/9/10 digits, `isCrossBank()` before you hit the 422).

### Errors

Every exception implements `DPay\Exceptions\DPayException`. `ApiException::$status`, `$body`, `$requestId` (quote it to support), `$problemType` (v2 RFC 7807). Mapped on the **exact** controller strings:

| API answer | Exception |
|---|---|
| 401 | `AuthenticationException` |
| 403 (`Not authorized`, `Invoice not found`, ability, verification) | `PermissionException` |
| 404 | `NotFoundException` |
| 422 with `errors` (Laravel / RFC 7807) | `ValidationException` (`fields()`, `first()`) |
| 422 `EDFali PIN is not correct`, `OTP verification failed.`, sandbox `Invalid OTP…` | `OtpRejectedException` — session still pending |
| 422 `Too many OTP attempts. Payment session locked.` | `SessionLockedException` — session failed |
| 422 `This card belongs to a different bank…` | `CrossBankCardException` |
| 422 `Unsupported payment method: sadad` / 400 `Unsupported payment method` | `UnsupportedMethodException` |
| 422 other provider text | `GatewayRejectedException` |
| 400 `Payment method is not active` / `…currently disabled` | `MethodNotActiveException` / `MethodDisabledException` |
| 400 `Amount is below the minimum deposit of N` / `…exceeds…` | `AmountOutOfRangeException` (`$limit`, `$belowMinimum`) |
| 400 `Payment session is not pending` / `…has expired` | `SessionNotPendingException` / `SessionExpiredException` |
| 400 sandbox `…simulated final failure` | `GatewayDeclinedException` — session failed |
| 409 `idempotency_key_reused` (v2 — the one answer that proves a key reuse) | `IdempotencyException` |
| 409 `checkout_not_open` | `CheckoutNotOpenException` |
| 429 | `RateLimitException` (`$retryAfter`) |
| 503 | `ProviderUnavailableException` |
| 5xx — including the legacy open 500 `Payment processing failed. Please try again.`, the API's *redacted* answer for **any** open failure (bank adapter error, storage error, and among others a cross-merchant key collision). Treat it as an outage: read the session back or re-send the **same** key; never mint a fresh one. `ServerException::mayBeIdempotencyCollision()` keeps the hint for logs. | `ServerException` |
| no HTTP answer | `ConnectionException` |
| redirect / non-JSON | `UnexpectedResponseException` |

`Messages::forException($e, 'ar')` gives the Arabic customer wording (`'en'` keeps the API text).

### Configuration

```php
Client::live($token, [
    'base_url'        => 'https://next.dpay.ly',                   // DPay hosts only (dpay.ly, next.dpay.ly, pg.dits.ly)
    'base_url_policy' => BaseUrlPolicy::allowing(['api.local'], allowHttpLocalhost: true),   // http:// only for localhost / host.docker.internal
    'timeout'         => 15.0, 'connect_timeout' => 5.0,
    'retry'           => new RetryPolicy(maxAttempts: 3),          // GET + keyed POST only, on 429/503/connection failure
    'logger'          => $psrLogger,                                 // tokens, OTPs, mobiles, cards are redacted
    'transport'       => new PsrTransport($psr18Client, $requestFactory, $streamFactory),   // a Guzzle client is refused if it follows redirects or skips TLS
]);
```

**Secrets hygiene.** The token is readable only through `$client->config->token()`; `var_dump`/`print_r`/`dd()` show it masked (`12|AbC…3c4d`), `json_encode` omits it, and `serialize()` of a `Client`, `Config` or `Verifier` throws — a queued job must resolve the client again from its `.env` key rather than carry it. The PSR-3 logger receives redacted bodies only.

### Development

```bash
composer install
composer check          # composer validate --strict · php-cs-fixer · phpstan (src: max, tests: 5) · phpunit (unit + contract)
tools/matrix.sh         # the same gates on php:8.1-cli … php:8.5-cli in Docker
composer sync-fixtures  # refresh tests/Contract/fixtures from the platform's execution-generated Postman collection
```

The **contract tier** replays every request/response pair of `platform/packages/contracts/docs/dpay-v1.postman_collection.json` through the SDK and fails when the collection gains a sample the SDK cannot classify — the signal to teach the SDK a new shape before a merchant meets it.

### Links

- API docs: https://dpay.ly/docs/api — hosted checkout: `platform/apps/api/src/modules/v2/API.md` §2.17
- Laravel package: [`dpay/laravel`](../laravel) (`DPay` facade, webhook route + middleware, events, `dpay:doctor`)
- Changelog: [CHANGELOG.md](CHANGELOG.md) · License: MIT
