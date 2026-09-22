# Laravel Spam Guard

Check form content for spam with a Laravel validation rule, powered by TypeSafe's Jev. Describe your form, choose a probability threshold, and keep control of how outages affect submissions. No browser widget is required.

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

Attach the rule to one text field. By default, only that field's name and value leave your application. Other request fields, identity, IP addresses, and headers are never collected automatically.

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
