# Changelog

## [1.0.0](https://github.com/vlados/laravel-spam-guard/releases/tag/v1.0.0) - 2026-09-22

Initial stable release.

- Add an explicit allow/review/block decision and a tested application-owned review workflow; unavailable results always require review in this mode.
- Strengthen mixed-content and quotation criteria using a frozen synthetic evaluation; retain the original prompt as an evaluation baseline.
- Add submit-time spam validation using TypeSafe Jev, with explicit unavailable verdicts and configurable failure policy.
- Add bounded HTTP calls, scalar sibling allowlisting, composed-state size limits, and payload-free failure logs.
- Add English/Bulgarian translations, facade testing fakes, and Livewire/Precognition integration examples.
- Add Testbench coverage and minimum-version consumer fixtures for Laravel 11–13.
