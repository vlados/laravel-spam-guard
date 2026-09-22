<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Vlados\LaravelSpamGuard\Facades\SpamGuard;
use Vlados\LaravelSpamGuard\Rules\NotSpam;
use Vlados\LaravelSpamGuard\SpamGuardServiceProvider;

require __DIR__.'/vendor/autoload.php';

function verify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function runChecks(): void
{
    foreach (['bootstrap/cache', 'storage/framework/views', 'storage/framework/cache', 'storage/logs', 'config', 'lang'] as $directory) {
        if (! is_dir(__DIR__.'/'.$directory)) {
            mkdir(__DIR__.'/'.$directory, 0777, true);
        }
    }

    putenv('TYPESAFE_API_KEY=fixture-key');
    $app = require __DIR__.'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

    verify(in_array(SpamGuardServiceProvider::class, $app->make(PackageManifest::class)->providers(), true), 'Package discovery failed.');
    verify(config('spam-guard.threshold') === 0.9, 'Default config was not discovered.');
    Http::preventStrayRequests();
    Http::fake(['api.typesafe.ai/*' => Http::response(['model' => 'jev-1.13.0', 'answers' => ['spam' => ['type' => 'noul', 'noul' => 0.95]], 'usage' => ['input_tokens' => 100]])]);
    verify(SpamGuard::check('hello')->isSpam() === true, 'HTTP classification failed.');
    verify(Validator::make(['message' => 'hello'], ['message' => new NotSpam])->fails(), 'Validation rule failed.');
    verify(trans('spam-guard::validation.spam', [], 'bg') === 'Съобщението изглежда като спам.', 'Translation loading failed.');

    verify($kernel->call('vendor:publish', ['--tag' => 'spam-guard-config', '--force' => true]) === 0, 'Config publishing failed.');
    verify(is_file(__DIR__.'/config/spam-guard.php'), 'Published config is missing.');
    verify($kernel->call('vendor:publish', ['--tag' => 'spam-guard-translations', '--force' => true]) === 0, 'Translation publishing failed.');
    verify(is_file(__DIR__.'/lang/vendor/spam-guard/bg/validation.php'), 'Published translations are missing.');
    verify($kernel->call('config:cache') === 0, 'Config caching failed.');

    $cachedApp = require __DIR__.'/bootstrap/app.php';
    $cachedKernel = $cachedApp->make(Kernel::class);
    $cachedKernel->bootstrap();
    verify($cachedApp->configurationIsCached(), 'Cached config was not loaded.');
    verify(config('spam-guard.threshold') === 0.9, 'Cached package config is missing.');
    SpamGuard::fake();
    verify(Validator::make(['message' => 'hello'], ['message' => new NotSpam])->passes(), 'Cached application rule failed.');
    $cachedKernel->call('config:clear');

    echo 'Laravel '.$cachedApp->version().': discovery, HTTP, validation, translations, publishing and config cache passed.'.PHP_EOL;
}

try {
    runChecks();
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage().PHP_EOL);
    exit(1);
}
