<?php

use Vlados\LaravelSpamGuard\Decision;
use Vlados\LaravelSpamGuard\Verdict;

it('separates automatic admission from review and blocking', function (float $probability, string $expected) {
    expect(Verdict::classified($probability, threshold: 0.9)->decision()->value)->toBe($expected);
})->with([[0.0, 'allow'], [0.199, 'allow'], [0.2, 'review'], [0.899, 'review'], [0.9, 'block'], [1.0, 'block']]);

it('always sends unavailable results to review', function () {
    expect(Verdict::unavailable('timeout', threshold: 0.9)->decision())->toBe(Decision::Review);
});

it('supports an application chosen review threshold', function () {
    expect(Verdict::classified(0.3, threshold: 0.9)->decision(reviewThreshold: 0.4))->toBe(Decision::Allow);
});

it('can disable automatic admission entirely', function () {
    expect(Verdict::classified(0.0, threshold: 0.9)->decision(reviewThreshold: 0.0))->toBe(Decision::Review)
        ->and(Verdict::classified(0.99, threshold: 0.9)->decision(reviewThreshold: 0.0))->toBe(Decision::Block);
});

it('rejects invalid or inverted review thresholds', function (float $threshold) {
    Verdict::classified(0.1, threshold: 0.9)->decision(reviewThreshold: $threshold);
})->with([-0.1, 1.0, INF, NAN])->throws(InvalidArgumentException::class);
