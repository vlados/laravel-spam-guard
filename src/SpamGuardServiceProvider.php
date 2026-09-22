<?php

declare(strict_types=1);

namespace Vlados\LaravelSpamGuard;

use Illuminate\Support\ServiceProvider;

class SpamGuardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/spam-guard.php', 'spam-guard');
        $this->app->bind(SpamGuard::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'spam-guard');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/spam-guard.php' => config_path('spam-guard.php')], 'spam-guard-config');
            $this->publishes([__DIR__.'/../lang' => lang_path('vendor/spam-guard')], 'spam-guard-translations');
        }
    }
}
