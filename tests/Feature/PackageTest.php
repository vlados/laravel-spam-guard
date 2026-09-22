<?php

use Illuminate\Support\ServiceProvider;
use Vlados\LaravelSpamGuard\Facades\SpamGuard;
use Vlados\LaravelSpamGuard\SpamGuardServiceProvider;
use Vlados\LaravelSpamGuard\Testing\SpamGuardFake;

it('publishes configuration and translations to Laravel destinations', function () {
    $configPaths = ServiceProvider::pathsToPublish(SpamGuardServiceProvider::class, 'spam-guard-config');
    $langPaths = ServiceProvider::pathsToPublish(SpamGuardServiceProvider::class, 'spam-guard-translations');
    expect(array_values($configPaths))->toBe([config_path('spam-guard.php')])
        ->and(array_values($langPaths))->toBe([lang_path('vendor/spam-guard')]);

    foreach (['en', 'bg'] as $locale) {
        foreach (['spam', 'unavailable', 'too_long', 'invalid'] as $key) {
            expect(trans('spam-guard::validation.'.$key, [], $locale))->not->toBe('spam-guard::validation.'.$key);
        }
    }
});

it('does not retain fake state between application instances', function () {
    SpamGuard::fake();
    SpamGuard::check('hello');
    $this->refreshApplication();
    expect(SpamGuard::getFacadeRoot())->toBeInstanceOf(Vlados\LaravelSpamGuard\SpamGuard::class)
        ->not->toBeInstanceOf(SpamGuardFake::class);
});
