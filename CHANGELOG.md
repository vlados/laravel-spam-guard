# Changelog

## [1.1.0](https://github.com/vlados/laravel-spam-guard/releases/tag/v1.1.0) - 2026-09-22

- Add optional Turnstile validation with server-side hostname and action checks, required tokens, and separate English/Bulgarian messages for rejected and unavailable verification.
- Keep bot protection independent of content classification and always reject when challenge verification is unavailable.
- Document widget setup, staged validation before paid spam checks, submit-only integration, token renewal, and HTTP fakes.

## [1.0.0](https://github.com/vlados/laravel-spam-guard/releases/tag/v1.0.0) - 2026-09-22

Initial stable release.

- Add an explicit allow/review/block decision and a tested application-owned review workflow; unavailable results always require review in this mode.
- Strengthen mixed-content and quotation criteria using a frozen synthetic evaluation; retain the original prompt as an evaluation baseline.
- Add submit-time spam validation using TypeSafe Jev, with explicit unavailable verdicts and configurable failure policy.
- Add bounded HTTP calls, scalar sibling allowlisting, composed-state size limits, and payload-free failure logs.
- Add English/Bulgarian translations, facade testing fakes, and Livewire/Precognition integration examples.
- Add Testbench coverage and minimum-version consumer fixtures for Laravel 11–13.
