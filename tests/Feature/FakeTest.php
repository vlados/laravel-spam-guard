<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\AssertionFailedError;
use Vlados\LaravelSpamGuard\Facades\SpamGuard;
use Vlados\LaravelSpamGuard\Rules\NotSpam;
use Vlados\LaravelSpamGuard\SpamGuard as Service;
use Vlados\LaravelSpamGuard\Verdict;

it('fakes the facade and injected service with the configured threshold and no credentials', function () {
    config()->set(['spam-guard.api_key' => null, 'spam-guard.threshold' => 0.7]);
    $fake = SpamGuard::fake();
    SpamGuard::assertNothingChecked();
    expect(app(Service::class))->toBe($fake);
    expect(SpamGuard::check('one')->isSpam())->toBeFalse();
    expect(app(Service::class)->check(['description' => 'two'])->threshold)->toBe(0.7);
    expect(Validator::make(['description' => 'three'], ['description' => new NotSpam])->passes())->toBeTrue();
    SpamGuard::assertCheckedTimes(3);
    SpamGuard::assertChecked(fn ($state, $context) => $state === ['description' => 'two'] && $context === 'Public contact form');
    Http::assertNothingSent();
});

it('consumes verdicts in order and preserves unavailable policy', function (bool $failOpen) {
    config()->set('spam-guard.fail_open', $failOpen);
    SpamGuard::fake([Verdict::classified(0.98), Verdict::unavailable('timeout')]);
    expect(Validator::make(['description' => 'one'], ['description' => new NotSpam])->fails())->toBeTrue();
    expect(Validator::make(['description' => 'two'], ['description' => new NotSpam])->passes())->toBe($failOpen);
    SpamGuard::assertCheckedTimes(2);
    Http::assertNothingSent();
})->with([true, false]);

it('throws on sequence exhaustion even when fail open', function () {
    SpamGuard::fake([]);
    Validator::make(['description' => 'hello'], ['description' => new NotSpam])->passes();
})->throws(OutOfBoundsException::class);

it('resets the fake and its call history', function () {
    SpamGuard::fake([Verdict::classified(1)]);
    SpamGuard::check('one');
    SpamGuard::fake();
    SpamGuard::assertNothingChecked();
    expect(SpamGuard::check('two')->isSpam())->toBeFalse();
});

it('makes failed fake assertions fail the test', function (string $assertion) {
    SpamGuard::fake();
    if ($assertion === 'nothing') {
        SpamGuard::check('one');
        SpamGuard::assertNothingChecked();
    } elseif ($assertion === 'times') {
        SpamGuard::assertCheckedTimes(1);
    } else {
        SpamGuard::assertChecked(fn () => true);
    }
})->with(['nothing', 'times', 'matching'])->throws(AssertionFailedError::class);

it('keeps the same state guards in the fake', function () {
    SpamGuard::fake();
    config()->set('spam-guard.max_state_chars', 5);
    expect(fn () => SpamGuard::check('too long'))->toThrow(InvalidArgumentException::class);
    SpamGuard::assertNothingChecked();
});

it('rejects invalid fake sequences', function () {
    SpamGuard::fake([0.5]);
})->throws(InvalidArgumentException::class);

it('uses the configured threshold in verdict factories', function () {
    config()->set('spam-guard.threshold', 0.7);
    expect(Verdict::classified(0.7)->isSpam())->toBeTrue()
        ->and(Verdict::unavailable('timeout')->threshold)->toBe(0.7)
        ->and(Verdict::classified(0.7, threshold: 0.8)->isSpam())->toBeFalse();
});
