<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Vlados\LaravelSpamGuard\Facades\SpamGuard;
use Vlados\LaravelSpamGuard\Rules\NotSpam;
use Vlados\LaravelSpamGuard\Rules\Turnstile;

beforeEach(function () {
    config()->set('spam-guard.turnstile.secret_key', 'turnstile-secret');
    config()->set('spam-guard.turnstile.hostname', 'example.com');
});

it('verifies the opted in challenge with a bounded request containing only the token and secret', function () {
    config()->set('spam-guard.api_key', null);
    Http::fake(function (Request $request, array $options) {
        expect($options['timeout'])->toBe(5.0)
            ->and($options['connect_timeout'])->toBe(2.0)
            ->and($options['allow_redirects'])->toBeFalse();

        return Http::response(['success' => true, 'hostname' => 'example.com', 'action' => 'contact', 'error-codes' => []]);
    });

    $validator = Validator::make([
        'cf-turnstile-response' => 'challenge-token', 'email' => 'private@example.com',
    ], ['cf-turnstile-response' => Turnstile::make('contact')]);

    expect($validator->passes())->toBeTrue();
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
        && $request->method() === 'POST'
        && $request->data() === ['secret' => 'turnstile-secret', 'response' => 'challenge-token']);
});

it('leaves content only checks independent of Turnstile configuration', function () {
    config()->set('spam-guard.turnstile', ['secret_key' => null, 'hostname' => null, 'timeout' => -1]);
    Http::fake(['api.typesafe.ai/*' => Http::response(responseBody())]);

    expect(Validator::make(['message' => 'An enquiry'], ['message' => new NotSpam])->passes())->toBeTrue();
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.typesafe.ai/v1/systemone');
});

it('requires a well formed challenge even without a required rule', function (array $data) {
    $validator = Validator::make($data, ['cf-turnstile-response' => new Turnstile('contact')]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('cf-turnstile-response'))->toBe(trans('spam-guard::validation.bot'));
    Http::assertNothingSent();
})->with([
    'missing' => [[]], 'empty' => [['cf-turnstile-response' => '']],
    'null' => [['cf-turnstile-response' => null]], 'spaces' => [['cf-turnstile-response' => '   ']],
    'array' => [['cf-turnstile-response' => ['token']]], 'integer' => [['cf-turnstile-response' => 123]],
    'oversized' => [['cf-turnstile-response' => str_repeat('a', 2049)]],
    'invalid encoding' => [['cf-turnstile-response' => "\xB1\x31"]],
]);

it('rejects unsuccessful expired and replayed challenges', function (string $code) {
    Http::fake(['*' => Http::response(['success' => false, 'error-codes' => [$code]])]);
    $validator = Validator::make(['token' => 'challenge-token'], ['token' => new Turnstile('contact')]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('token'))->toBe(trans('spam-guard::validation.bot'));
    Http::assertSentCount(1);
})->with(['invalid-input-response', 'missing-input-response', 'timeout-or-duplicate']);

it('rejects tokens issued for another hostname or action', function (array $response) {
    Http::fake(['*' => Http::response($response + ['success' => true])]);
    $validator = Validator::make(['token' => 'challenge-token'], ['token' => new Turnstile('contact')]);

    expect($validator->errors()->first('token'))->toBe(trans('spam-guard::validation.bot'));
})->with([
    [['hostname' => 'attacker.example', 'action' => 'contact']],
    [['hostname' => 'example.com.attacker.example', 'action' => 'contact']],
    [['hostname' => 'example.com', 'action' => 'login']],
]);

it('fails closed with a separate outage error on malformed or unavailable verification', function (mixed $response, int $status) {
    config()->set('spam-guard.fail_open', true);
    Http::fake(['*' => Http::response($response, $status)]);
    $validator = Validator::make(['token' => 'challenge-token'], ['token' => new Turnstile('contact')]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('token'))->toBe(trans('spam-guard::validation.bot_unavailable'))
        ->not->toBe(trans('spam-guard::validation.bot'));
    Http::assertSentCount(1);
})->with([
    'http failure' => [[], 503], 'redirect' => [[], 302], 'rate limit' => [[], 429],
    'malformed json' => ['{', 200], 'null json' => ['null', 200],
    'missing success' => [[], 200], 'string success' => [['success' => 'true'], 200],
    'numeric success' => [['success' => 1], 200],
    'missing hostname' => [['success' => true, 'action' => 'contact'], 200],
    'missing action' => [['success' => true, 'hostname' => 'example.com'], 200],
    'wrong hostname type' => [['success' => true, 'hostname' => [], 'action' => 'contact'], 200],
    'contradictory errors' => [['success' => true, 'hostname' => 'example.com', 'action' => 'contact', 'error-codes' => ['internal-error']], 200],
    'provider error' => [['success' => false, 'error-codes' => ['internal-error']], 200],
    'invalid secret' => [['success' => false, 'error-codes' => ['invalid-input-secret']], 200],
    'missing secret' => [['success' => false, 'error-codes' => ['missing-input-secret']], 200],
    'bad request' => [['success' => false, 'error-codes' => ['bad-request']], 200],
    'unknown error' => [['success' => false, 'error-codes' => ['new-error']], 200],
    'missing errors' => [['success' => false], 200],
    'malformed errors' => [['success' => false, 'error-codes' => 'invalid-input-response'], 200],
    'mixed errors' => [['success' => false, 'error-codes' => ['invalid-input-response', 'internal-error']], 200],
]);

it('handles a connection failure without logging tokens secrets or exception text', function () {
    Http::fake(fn () => throw new ConnectionException('challenge-token turnstile-secret private@example.com'));
    Log::spy();
    $validator = Validator::make(['token' => 'challenge-token'], ['token' => new Turnstile('contact')]);

    expect($validator->errors()->first('token'))->toBe(trans('spam-guard::validation.bot_unavailable'));
    Log::shouldHaveReceived('log')->once()->with('warning', 'Bot verification unavailable.', Mockery::on(
        fn ($context) => array_keys($context) === ['reason', 'http_status']
            && $context['reason'] === 'connection' && $context['http_status'] === null
    ));
});

it('does not swallow programming errors during bot verification', function () {
    Http::fake(fn () => throw new LogicException('bug'));
    Validator::make(['token' => 'challenge-token'], ['token' => new Turnstile('contact')])->passes();
})->throws(LogicException::class, 'bug');

it('rejects invalid local Turnstile configuration before sending a token', function (string $key, mixed $value) {
    config()->set('spam-guard.turnstile.'.$key, $value);
    try {
        Validator::make(['token' => 'challenge-token'], ['token' => new Turnstile('contact')])->passes();
    } finally {
        Http::assertNothingSent();
    }
})->with([
    ['secret_key', null], ['secret_key', ''], ['secret_key', "secret\nkey"],
    ['hostname', null], ['hostname', ''], ['hostname', 'https://example.com'], ['hostname', 'example.com:443'],
    ['timeout', 0], ['timeout', INF], ['timeout', '5'], ['connect_timeout', 6], ['connect_timeout', -1],
])->throws(InvalidArgumentException::class);

it('requires an application controlled valid action', function (string $action) {
    new Turnstile($action);
})->with(['', 'contact form', str_repeat('a', 33)])->throws(InvalidArgumentException::class);

it('translates challenge and outage failures', function (array $response, string $expected) {
    app()->setLocale('bg');
    Http::fake(['*' => Http::response($response)]);
    $validator = Validator::make(['token' => 'challenge-token'], ['token' => new Turnstile('contact')]);

    expect($validator->errors()->first('token'))->toBe($expected);
})->with([
    [['success' => false, 'error-codes' => ['invalid-input-response']], 'Моля, потвърдете проверката срещу ботове и опитайте отново.'],
    [['success' => false, 'error-codes' => ['internal-error']], 'Проверката срещу ботове не е достъпна в момента. Моля, опитайте отново.'],
]);

it('does not invoke paid spam classification when the bot validation stage fails', function () {
    SpamGuard::fake();
    Http::fake(['*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);
    $validator = Validator::make(['token' => 'challenge-token'], ['token' => new Turnstile('contact')]);

    try {
        $validator->validate();
        SpamGuard::check(['description' => 'An enquiry']);
    } catch (ValidationException) {
        SpamGuard::assertNothingChecked();

        return;
    }

    $this->fail('Bot validation must stop the submission before classification.');
});
