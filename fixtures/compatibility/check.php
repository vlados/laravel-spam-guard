<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Vlados\LaravelSpamGuard\Facades\SpamGuard;
use Vlados\LaravelSpamGuard\Rules\NotSpam;
use Vlados\LaravelSpamGuard\Rules\Turnstile;
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
    putenv('TURNSTILE_HOSTNAME=example.com');
    putenv('TURNSTILE_SECRET_KEY=fixture-turnstile-key');
    $app = require __DIR__.'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

    verify(in_array(SpamGuardServiceProvider::class, $app->make(PackageManifest::class)->providers(), true), 'Package discovery failed.');
    verify(config('spam-guard.threshold') === 0.9, 'Default config was not discovered.');
    Http::preventStrayRequests();
    Http::fake([
        'api.typesafe.ai/*' => Http::response(['model' => 'jev-1.13.0', 'answers' => ['spam' => ['type' => 'noul', 'noul' => 0.95]], 'usage' => ['input_tokens' => 100]]),
        'challenges.cloudflare.com/turnstile/v0/siteverify' => Http::sequence()
            ->push(['success' => true, 'hostname' => 'example.com', 'action' => 'contact'])
            ->push([], 503),
    ]);
    verify(SpamGuard::check('hello')->isSpam() === true, 'HTTP classification failed.');
    verify(Validator::make(['message' => 'hello'], ['message' => new NotSpam])->fails(), 'Validation rule failed.');
    verify(trans('spam-guard::validation.spam', [], 'bg') === 'Съобщението изглежда като спам.', 'Translation loading failed.');
    verify(Validator::make([], ['cf-turnstile-response' => Turnstile::make('contact')])->fails(), 'Turnstile allowed a missing token.');
    verify(Http::recorded(fn ($request) => $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify')->isEmpty(), 'Turnstile requested verification for a missing token.');
    verify(Validator::make(['cf-turnstile-response' => 'fixture-token'], ['cf-turnstile-response' => new Turnstile('contact')])->passes(), 'Turnstile rejected verified action and hostname.');
    verify(Validator::make(['cf-turnstile-response' => 'outage-token'], ['cf-turnstile-response' => new Turnstile('contact')])->fails(), 'Turnstile allowed an unavailable verification service.');
    $turnstileConfig = config('spam-guard.turnstile');
    verify(config('spam-guard.turnstile.hostname') === 'example.com', 'Turnstile hostname config was not loaded.');
    verify(config('spam-guard.turnstile.secret_key') === 'fixture-turnstile-key', 'Turnstile secret config was not loaded.');

    verify($kernel->call('vendor:publish', ['--tag' => 'spam-guard-config', '--force' => true]) === 0, 'Config publishing failed.');
    verify(is_file(__DIR__.'/config/spam-guard.php'), 'Published config is missing.');
    $publishedConfig = require __DIR__.'/config/spam-guard.php';
    verify(($publishedConfig['turnstile'] ?? null) === $turnstileConfig, 'Published Turnstile config does not match discovered defaults.');
    verify($kernel->call('vendor:publish', ['--tag' => 'spam-guard-translations', '--force' => true]) === 0, 'Translation publishing failed.');
    verify(is_file(__DIR__.'/lang/vendor/spam-guard/bg/validation.php'), 'Published translations are missing.');
    verify($kernel->call('config:cache') === 0, 'Config caching failed.');

    $cachedApp = require __DIR__.'/bootstrap/app.php';
    $cachedKernel = $cachedApp->make(Kernel::class);
    $cachedKernel->bootstrap();
    verify($cachedApp->configurationIsCached(), 'Cached config was not loaded.');
    verify(config('spam-guard.threshold') === 0.9, 'Cached package config is missing.');
    verify(config('spam-guard.turnstile') === $turnstileConfig, 'Cached Turnstile config is missing or changed.');
    SpamGuard::fake();
    verify(Validator::make(['message' => 'hello'], ['message' => new NotSpam])->passes(), 'Cached application rule failed.');
    Http::preventStrayRequests();
    Http::fake(['challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true, 'hostname' => 'example.com', 'action' => 'contact'])]);
    verify(Validator::make(['cf-turnstile-response' => 'cached-token'], ['cf-turnstile-response' => new Turnstile('contact')])->passes(), 'Cached application Turnstile rule failed.');
    $cachedKernel->call('config:clear');

    echo 'Laravel '.$cachedApp->version().': discovery, HTTP, validation, Turnstile, translations, publishing and config cache passed.'.PHP_EOL;
}

try {
    runChecks();
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage().PHP_EOL);
    exit(1);
}
