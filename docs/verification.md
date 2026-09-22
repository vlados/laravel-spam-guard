# Local verification — 22 September 2026

The v1 implementation was verified without live TypeSafe requests or API credentials. HTTP fixtures use Laravel's HTTP fake with stray requests prevented.

## Package suite

Each combination passed **98 tests / 222 assertions**:

| Laravel | PHP | Result |
| --- | --- | --- |
| 11.56.1 | 8.2.32 | Passed |
| 11.56.1 | 8.4.23 | Passed |
| 12.69.2 | 8.2.32 | Passed |
| 12.69.2 | 8.4.23 | Passed |
| 13.32.0 | 8.4.23 | Passed |

The suite covers threshold boundaries, failure policies, malformed remote responses, transport failures, payload-free failure logs, state bounds, sibling-field allowlisting, translations, testing fakes, Livewire submission timing, and Precognition.

Laravel 11/12 suites ran in isolated temporary copies of the package source. The main workspace retains Laravel 13 development dependencies.

## Consumer fixtures

| Laravel | PHP | Symfony Console | Result |
| --- | --- | --- | --- |
| 11.0.0 | 8.2.32 | 7.0.x | Passed |
| 12.0.0 | 8.2.32 | 7.2.x | Passed |
| 13.0.0 | 8.4.23 | 7.4.x | Passed |

These fixtures use stable-root Composer resolution with only the local package explicitly allowed at a development version. No Testbench dependency is installed. Each exercises package discovery, HTTP classification, validation, Bulgarian translations, publishing, and configuration caching.

The Laravel 11.0 fixture exposed integer-only timeout helpers. The client now supplies Guzzle timeout options directly, preserving fractional values. Older framework fixtures pin the contemporary Symfony Console line to avoid incompatibilities in historical Laravel console commands.

Historical floor fixtures and Laravel 11 test installations require Composer's security-blocking override because their framework versions have known advisories. This exception is confined to test installations. Laravel 12/13 package-suite installations retained security blocking; the main workspace dependency resolution reported no advisories.

## Other checks

- `composer test`: passed in the main workspace.
- `composer lint`: passed.
- `composer validate --strict`: passed for the package and consumer fixture manifests.
- Workflow YAML parsed successfully: eight package-suite jobs plus three consumer-floor jobs.

## Not executed

- PHP 8.3 jobs and hosted GitHub Actions. Those jobs are defined in the workflow.
- Live TypeSafe classification, Bulgarian accuracy evaluation, or latency/cost benchmarks.
- Package publication or application deployment.

This verifies integration behavior and compatibility, not model quality. The package remains unreleased.
