<?php

use Illuminate\Support\Facades\Http;
use Vlados\LaravelSpamGuard\Facades\SpamGuard;

it('counts composed JSON in Unicode characters including keys', function () {
    config()->set('spam-guard.max_state_chars', 9);
    Http::fake(['*' => Http::response(responseBody())]);

    SpamGuard::check(['a' => 'я']);
    Http::assertSentCount(1);
    expect(fn () => SpamGuard::check(['a' => 'яя']))->toThrow(InvalidArgumentException::class);
    Http::assertSentCount(1);
});

it('bounds JSON strings including escaping', function () {
    config()->set('spam-guard.max_state_chars', 4);
    Http::fake(['*' => Http::response(responseBody())]);
    SpamGuard::check('аб');
    expect(fn () => SpamGuard::check('абв'))->toThrow(InvalidArgumentException::class);
    expect(fn () => SpamGuard::check("\n\n"))->toThrow(InvalidArgumentException::class);
    Http::assertSentCount(1);
});

it('rejects non JSON data before HTTP', function (Closure $state) {
    try {
        SpamGuard::check($state());
    } finally {
        Http::assertNothingSent();
    }
})->with([
    'object' => fn () => new stdClass,
    'nested object' => fn () => ['nested' => new stdClass],
    'resource' => fn () => STDOUT,
    'nonfinite' => fn () => ['number' => INF],
    'invalid utf8' => fn () => "\xB1\x31",
    'scalar root' => fn () => 12,
    'recursive' => function () {
        $state = [];
        $state['self'] = &$state;

        return $state;
    },
])->throws(InvalidArgumentException::class);
