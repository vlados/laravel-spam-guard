# Laravel Spam Guard

![Laravel Spam Guard — content-based spam detection powered by TypeSafe Jev, with allow, review, and block decisions](https://raw.githubusercontent.com/vlados/laravel-spam-guard/main/docs/images/banner.png)

[![Tests](https://github.com/vlados/laravel-spam-guard/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/vlados/laravel-spam-guard/actions/workflows/tests.yml)
[![Latest stable version](https://img.shields.io/packagist/v/vlados/laravel-spam-guard)](https://packagist.org/packages/vlados/laravel-spam-guard)
[![Total downloads](https://img.shields.io/packagist/dt/vlados/laravel-spam-guard)](https://packagist.org/packages/vlados/laravel-spam-guard/stats)
[![PHP version](https://img.shields.io/packagist/dependency-v/vlados/laravel-spam-guard/php)](https://github.com/vlados/laravel-spam-guard/blob/main/composer.json)
[![License](https://img.shields.io/packagist/l/vlados/laravel-spam-guard)](LICENSE)

Check form content for spam with a Laravel validation rule, powered by TypeSafe's Jev. Describe your form, choose a probability threshold, and keep control of how outages affect submissions. No browser widget is required for spam checks. Add [optional Turnstile protection](#optional-bot-protection) to verify a browser challenge separately.

```php
use Vlados\LaravelSpamGuard\Rules\NotSpam;

$validated = $request->validate([
    'description' => ['bail', 'required', 'string', 'max:5000', new NotSpam],
]);
```

This is probabilistic content filtering. Keep rate limits and inexpensive bot checks. Evaluate real submissions in shadow mode before blocking customers, especially for Bulgarian and transliterated text. See the [synthetic adversarial evaluation](docs/adversarial/expanded-report.md) for observed results and limitations; there is no representative production accuracy benchmark or guaranteed protection percentage.

## Hold suspicious submissions for review

For a workflow that withholds uncertain messages, use the facade's three-way decision after cheap validation:

```php
use Vlados\LaravelSpamGuard\Decision;
use Vlados\LaravelSpamGuard\Facades\SpamGuard;

$data = $request->validate([
    'description' => ['required', 'string', 'max:5000'],
]);
$verdict = SpamGuard::check(['description' => $data['description']]);
$decision = $verdict->decision(reviewThreshold: 0.2);
```

| Result | Decision | Application action |
| --- | --- | --- |
| Available probability below 0.2 | `Decision::Allow` | Save as approved, then queue delivery after commit |
| Probability from 0.2 up to the configured spam threshold | `Decision::Review` | Save as pending review; do not queue delivery |
| Probability at or above the spam threshold | `Decision::Block` | Save as blocked; do not queue delivery |
| Unavailable check | `Decision::Review` | Save as pending review; do not queue delivery |

`decision()` defaults to a review threshold of `0.2`; the rejection threshold comes from the verdict, normally `0.9`. The review threshold must be finite, nonnegative, and no greater than the rejection threshold. This policy always holds unavailable results, independently of the validation rule's `fail_open` option. It does not imply that a 0.2 score is a calibrated 20% real-world risk.

The package returns the decision. **Your application owns persistence, the review queue, approval, and delivery.** A validation failure alone does not create a pending record. Use the executable [review workflow example](examples/review-workflow.php), which expects an application-owned `contact_submissions` table with `id`, `description`, `status`, nullable `spam_probability`, and nullable `spam_error` columns, plus a delivery callback. It saves the status in a transaction and invokes delivery only after an approved record commits. The package tests this example against SQLite and verifies that pending/blocked records queue no delivery, including on provider outages and database failures.

The `NotSpam` rule is the simpler binary interface. It does not apply the review band or persist submissions. Use `check()->decision()` and the persistence workflow when review is required. The review settings are provisional; validate them against representative legitimate enquiries and keep rate limiting ahead of the classifier.

To disable automatic admission entirely, call `decision(reviewThreshold: 0.0)`. Every result is then held or blocked, including a probability of zero. That is a workflow control, not a claim of perfect spam classification; human approval still belongs to your application.

## Installation

Requires PHP 8.2+ with cURL, JSON, and mbstring, and Laravel 11, 12, or 13. cURL ensures the connection timeout is supported by the default HTTP transport. Laravel 13 requires PHP 8.3+. Laravel 11 compatibility does not extend its upstream security support.

```bash
composer require vlados/laravel-spam-guard
```

Add your TypeSafe API key to `.env`:

```dotenv
TYPESAFE_API_KEY=your-key
```

Laravel discovers the service provider automatically. Optionally publish the configuration:

```bash
php artisan vendor:publish --tag=spam-guard-config
```

The TypeSafe API is a separately billed service. See the [changelog](CHANGELOG.md) for release details.

## Form purpose and selected fields

Attach the rule to one text field. For content checks, only that field's name and value leave your application by default. Other request fields, identity, IP addresses, and headers are never collected automatically by the content checker.

```php
'description' => [
    'bail', 'required', 'string', 'max:5000',
    NotSpam::make()
        ->context('Customers request spare parts, compatibility, availability, prices, delivery or support.')
        ->withFields(['vehicle', 'part_number']),
],
'vehicle' => ['nullable', 'string', 'max:100'],
'part_number' => ['nullable', 'string', 'max:100'],
```

Context must be application-controlled. Never put submitted text into `context()`; submission content belongs in the state. The prompt asks the model to ignore instructions in submissions, but that is not a security boundary against prompt injection.

Sibling fields are an explicit allowlist. Missing fields are omitted; null, boolean, string, and finite numeric values are allowed. Arrays and objects are rejected even if their validators have not run. Dot paths select nested values and keep their full names in the outbound state. Wildcards are not supported. Attach the rule once to avoid duplicate calls.

`bail` stops checks after an earlier failure on the **same attribute**. It does not wait for other fields to pass. If the entire form must pass cheap validation first, use two validation stages:

```php
$data = $request->validate([
    'description' => ['required', 'string', 'max:5000'],
    'email' => ['required', 'email'],
]);

validator($data, ['description' => [new NotSpam]])->validate();
```

The rule is not implicit: use `required`, `nullable`, and `string` according to your form's needs. Laravel skips the check for empty or absent optional values.

## Optional bot protection

Add `Vlados\LaravelSpamGuard\Rules\Turnstile` to opt a form into Cloudflare Turnstile. Existing `NotSpam` and `SpamGuard` checks remain unchanged, and no new dependency is required. Passing the challenge is an additional anti-bot signal; it does not establish a person's identity or classify their content as legitimate.

Create a Turnstile widget for your site's hostname in Cloudflare, then set:

```dotenv
TURNSTILE_SITE_KEY=your-public-site-key
TURNSTILE_SECRET_KEY=your-secret-key
TURNSTILE_HOSTNAME=example.com
```

The hostname must be one exact, application-controlled hostname, without a scheme or port. Keep the secret key on the server. If you already published `config/spam-guard.php`, add this entry manually:

```php
'turnstile' => [
    'site_key' => env('TURNSTILE_SITE_KEY'),
    'secret_key' => env('TURNSTILE_SECRET_KEY'),
    'hostname' => env('TURNSTILE_HOSTNAME'),
    'timeout' => 5.0,
    'connect_timeout' => 2.0,
],
```

Include the widget inside your existing Blade form and load the script once:

```blade
<div
    id="contact-turnstile"
    class="cf-turnstile"
    data-sitekey="{{ config('spam-guard.turnstile.site_key') }}"
    data-action="contact"
></div>
@error('cf-turnstile-response')
    <p role="alert">{{ $message }}</p>
@enderror

<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
```

The widget supplies the `cf-turnstile-response` form field. Match its `data-action` to the rule's action. See Cloudflare's [widget configuration](https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/widget-configurations/) for rendering options.

Run cheap validation first, then verify the token, then call the content checker:

```php
use Vlados\LaravelSpamGuard\Rules\NotSpam;
use Vlados\LaravelSpamGuard\Rules\Turnstile;

$data = $request->validate([
    'description' => ['required', 'string', 'max:5000'],
    'email' => ['required', 'email'],
]);

$request->validate([
    'cf-turnstile-response' => [new Turnstile('contact')],
]);

validator($data, ['description' => [new NotSpam]])->validate();
```

`Turnstile::make('contact')` is equivalent. The rule is implicit: missing or empty tokens fail without needing `required`. Do not add `sometimes` or exclusion rules that skip protection. Separate stages prevent an invalid challenge from triggering a paid content check; `bail` alone only stops rules on the same attribute. Keep rate limiting ahead of these stages.

The rule verifies the token server-side and requires `success: true`, the configured hostname, and the expected action. It always rejects submissions when verification is rejected or unavailable, independently of the content checker's `fail_open` setting. English and Bulgarian messages distinguish a rejected challenge from unavailable verification.

Tokens expire after five minutes and can be verified only once. If an AJAX submission stays on the page, reset the widget before retrying after verification or a later spam-validation failure. A new attempt needs a fresh token. With [programmatic rendering](https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/#explicit-rendering), keep the ID returned by `turnstile.render()` and call `turnstile.reset(widgetId)`. See Cloudflare's [server-side validation contract](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/).

For Livewire, synchronize the widget's token into a component property and validate it only inside the submit action, after cheap validation. Clear that property and reset the widget after a consumed token. For Precognition, omit the Turnstile rule from precognitive requests and run it on the final submission only. The rule does not automatically skip either framework's intermediate requests.

## Direct checks and shadow evaluation

```php
use Vlados\LaravelSpamGuard\Facades\SpamGuard;

$verdict = SpamGuard::check(
    ['description' => $description],
    context: 'Spare-part enquiries',
);

$verdict->probability;   // ?float: raw provider result, null when unavailable
$verdict->threshold;     // float: configured decision threshold
$verdict->error;         // ?string: failure reason
$verdict->isAvailable(); // bool
$verdict->isSpam();      // ?bool: null when unavailable
$verdict->decision();    // Decision::Allow, Decision::Review, or Decision::Block
$verdict->model;         // ?string: resolved model if supplied
$verdict->inputTokens;   // ?int: provider usage if supplied
$verdict->durationMs;    // float: elapsed client time, including response parsing
```

You can inject `Vlados\LaravelSpamGuard\SpamGuard` instead of using the facade. The direct API returns evidence; it does not apply the fail-open admission policy. Check availability before acting on `isSpam()`.

For shadow evaluation, call the facade without rejecting submissions. Compare results with human labels, recording counts and metadata in your own application. Freeze the model, question, and threshold before evaluating unseen samples. Report legitimate-message false positives and include unavailable checks in end-to-end results. Free-text samples may contain personal data; choose retention and access controls deliberately.

## Configuration and outages

```php
return [
    'api_key' => env('TYPESAFE_API_KEY'),
    'context' => 'Public contact form',
    'threshold' => 0.9,
    'fail_open' => true,
    'model' => 'jev-1.13.0',
    'timeout' => 2.0,
    'connect_timeout' => 1.0,
    'max_state_chars' => 10_000,
];
```

An available probability **greater than or equal to** the threshold fails validation. `0.9` is a provisional default, not a quality guarantee. Probabilities must be finite JSON numbers in `[0, 1]`; numeric strings are rejected.

Network failures, unsuccessful HTTP responses, and malformed answers return unavailable verdicts. Under `fail_open: true`, unavailable checks pass validation. Set `fail_open` to `false` to show a separate “Unable to check” error, without labelling the message spam. Missing/invalid API keys and other invalid local configuration throw `InvalidArgumentException`; fake checks do not require credentials. An HTTP 401/403 from a configured key is an observable remote failure and follows the selected failure policy.

There are no synchronous retries, redirects, or persistent caches. Every actual resubmission makes a new call. A cURL timeout is reported as `timeout`; other transport failures, including a timeout without structured cURL diagnostics, use `connection`.

| Reason | Meaning |
| --- | --- |
| `timeout` | Transport reported cURL timeout code 28 |
| `connection` | Other connection failure |
| `authentication` | HTTP 401 or 403 |
| `rejected_request` | HTTP 422 |
| `rate_limited` | HTTP 429 |
| `overloaded` | HTTP 529 |
| `server_error` | Other HTTP 5xx |
| `http_error` | Other unsuccessful status, including redirects |
| `invalid_response` | Invalid JSON or missing/invalid Noul answer |

The package logs only `reason`, `http_status`, `duration_ms`, and `attempts`. Authentication failures use error level; other failures use warning level. It does not log submitted state, API keys, response bodies, or exception dumps. Application HTTP tracing and global logging middleware may capture more data; configure those separately.

### Input limits

The direct API accepts a UTF-8 string or an array containing JSON values. Objects, resources, invalid UTF-8, recursive/deep arrays, and nonfinite numbers are rejected. Arrays can contain nested arrays; rule-selected sibling fields must be scalar or null.

The size guard counts Unicode characters in the composed state's JSON using unescaped Unicode and slashes. Keys, punctuation, quotes, and required escapes count toward the limit. The same check runs in the fake. Nothing is silently truncated. An oversized direct check throws `InvalidArgumentException`; the rule gives a translated length error. The character guard is not a token-budget estimator; provider limits also apply to the question and context.

## Testing your application

```php
use Illuminate\Support\Facades\Http;
use Vlados\LaravelSpamGuard\Facades\SpamGuard;
use Vlados\LaravelSpamGuard\Verdict;

Http::preventStrayRequests();
SpamGuard::fake(); // Unlimited legitimate verdicts; no API key needed.

// Exercise your form, then:
SpamGuard::assertCheckedTimes(1);
SpamGuard::assertChecked(fn ($state, $context) => isset($state['description']));
```

For explicit outcomes:

```php
SpamGuard::fake([
    Verdict::classified(0.98),
    Verdict::unavailable('timeout'),
]);
```

The sequence is consumed in order. Exhaustion throws `OutOfBoundsException`, even with fail-open enabled. An empty array is an empty sequence. Factories use the configured threshold unless you provide `threshold:` explicitly. Replacing the fake resets its sequence and call history; a fresh Laravel application resets the facade binding. Call `fake()` before resolving services that retain an injected SpamGuard instance.

`SpamGuard::assertNothingChecked()` asserts that no valid check reached the fake. Fake assertions use the application's PHPUnit installation. Fakes also replace the container binding, so subsequent injected services and rules use the same fake.

Turnstile verification is separate from `SpamGuard::fake()`. Fake its HTTP response with the configured hostname and the form's expected action:

```php
config(['spam-guard.turnstile.secret_key' => 'test-secret']);
config(['spam-guard.turnstile.hostname' => 'example.com']);

Http::fake([
    'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
        'success' => true,
        'hostname' => 'example.com',
        'action' => 'contact',
    ]),
]);
```

Submit a nonempty token in your test request, and keep `Http::preventStrayRequests()` enabled. Exercise rejected and unavailable responses too; neither should reach the content checker in the staged example above.

## Submit-only Livewire checks

Use submit-time validation with no update-triggered expensive rule:

```php
use Livewire\Attributes\Validate;
use Livewire\Component;
use Vlados\LaravelSpamGuard\Rules\NotSpam;

class ContactForm extends Component
{
    #[Validate(onUpdate: false)]
    public string $description = '';

    protected function rules(): array
    {
        return ['description' => ['bail', 'required', 'string', 'max:5000', new NotSpam]];
    }

    public function submit(): void
    {
        $data = $this->validate();
        // Persist the validated submission here.
    }
}
```

Bind the form to `wire:submit="submit"`. Do not call `validateOnly()` with the spam rule from update hooks. This recipe is exercised through Livewire 4 in the test suite: typing sends zero checks; submitting sends one.

## Precognition

In a FormRequest used by a route with `HandlePrecognitiveRequests`, omit the expensive rule for precognitive requests:

```php
public function rules(): array
{
    $rules = ['bail', 'required', 'string', 'max:5000'];

    if (! $this->isPrecognitive()) {
        $rules[] = new \Vlados\LaravelSpamGuard\Rules\NotSpam;
    }

    return ['description' => $rules];
}
```

This behavior is application-controlled; the rule does not globally skip Precognition requests. Integration tests exercise the middleware and final submission separately.

## Translations

English and Bulgarian messages are included. To customize them:

```bash
php artisan vendor:publish --tag=spam-guard-translations
```

Edit `lang/vendor/spam-guard/{locale}/validation.php`. Running `lang:publish` first is unnecessary.

## Privacy and limitations

State is sent over HTTPS to TypeSafe at `https://api.typesafe.ai/v1/systemone`, together with the form purpose and spam criteria. The package does not persist submissions. Free text can still identify people even when no dedicated identity fields are selected. Application-side preprocessing is your responsibility; there is no built-in redaction engine.

Optional Turnstile protection adds Cloudflare as a separate service. Its server-side request sends the secret key and challenge token, without automatically collecting or forwarding visitor IP addresses or request headers. The browser widget also communicates with Cloudflare; the content checker's selected-field privacy boundary does not cover that browser activity. Account for the widget in your application's privacy notice and service review.

The supplied research identifies US hosting and enterprise-specific zero-data-retention terms. Do not assume a standard account has zero retention or an EU endpoint. Review TypeSafe's current [privacy policy](https://typesafe.ai/legal/privacy-policy), [DPA](https://typesafe.ai/legal/data-processing), and [legal documentation](https://docs.typesafe.ai/legal) for your account. Your application remains responsible for its privacy notice, lawful processing, minimization, retention decisions, and applicable transfer arrangements.

This package does not stop floods, guarantee prompt-injection resistance, or treat off-topic messages as necessarily spam. Bulgarian accuracy is unmeasured. Submit-time HTTP latency is added to your form request; measure actual timeout rates, false positives, token usage, and costs before deploying a blocking policy.

## Development

```bash
composer install
composer test
composer lint
```

CI defines eight Testbench jobs across Laravel 11–13 and PHP 8.2–8.4, plus three minimal consumer fixtures for framework 11.0.0, 12.0.0, and 13.0.0. The consumer fixtures exercise stable-root dependency resolution without Testbench, package discovery, HTTP checks, validation, translations, publishing, and config caching. Historical floor fixtures and the unsupported Laravel 11 jobs intentionally allow dependencies with known advisories; they are test-only and must never be deployed. Laravel 12/13 Testbench jobs retain Composer's security blocking. No API secrets or live classifications are used by tests.

The [local verification record](docs/verification.md) lists executed versions and remaining checks. The [research brief](docs/research.md) records the source material and unmeasured assumptions. The [TypeSafe API reference](https://docs.typesafe.ai/api) documents the request and response contract. Inspired by [Freek Van der Herten's Jev integration](https://freek.dev/3194-detecting-spam-and-auto-replies-with-jev-and-the-laravel-ai-sdk).

## License

MIT. See [LICENSE](LICENSE).
