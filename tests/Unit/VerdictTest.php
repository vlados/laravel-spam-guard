<?php

use Vlados\LaravelSpamGuard\Verdict;

it('keeps the probability and includes the threshold boundary', function () {
    $verdict = Verdict::classified(0.9, threshold: 0.9);

    expect($verdict->isAvailable())->toBeTrue()
        ->and($verdict->isSpam())->toBeTrue()
        ->and($verdict->probability)->toBe(0.9)
        ->and($verdict->error)->toBeNull()
        ->and(Verdict::classified(0.899, threshold: 0.9)->isSpam())->toBeFalse();
});

it('keeps unavailable distinct from legitimate', function () {
    $verdict = Verdict::unavailable('timeout', threshold: 0.9, durationMs: 2000);

    expect($verdict->isAvailable())->toBeFalse()
        ->and($verdict->isSpam())->toBeNull()
        ->and($verdict->probability)->toBeNull()
        ->and($verdict->inputTokens)->toBeNull()
        ->and($verdict->model)->toBeNull()
        ->and($verdict->error)->toBe('timeout')
        ->and($verdict->durationMs)->toBe(2000.0);
});

it('rejects invalid probabilities', function (float $probability) {
    Verdict::classified($probability, threshold: 0.9);
})->with([-0.1, 1.01, INF, NAN])->throws(InvalidArgumentException::class);

it('rejects invalid thresholds', function (float $threshold) {
    Verdict::classified(0.1, threshold: $threshold);
})->with([-0.1, 1.01, INF, NAN])->throws(InvalidArgumentException::class);
