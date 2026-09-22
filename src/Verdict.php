<?php

declare(strict_types=1);

namespace Vlados\LaravelSpamGuard;

use InvalidArgumentException;

final readonly class Verdict
{
    private function __construct(
        public ?float $probability,
        public float $threshold,
        public ?string $error,
        public ?string $model,
        public ?int $inputTokens,
        public float $durationMs,
    ) {}

    public static function classified(
        float $probability,
        ?float $threshold = null,
        ?string $model = null,
        ?int $inputTokens = null,
        float $durationMs = 0.0,
    ): self {
        self::validateProbability($probability);

        return new self($probability, self::resolveThreshold($threshold), null, $model, $inputTokens, $durationMs);
    }

    public static function unavailable(string $error, ?float $threshold = null, float $durationMs = 0.0): self
    {
        return new self(null, self::resolveThreshold($threshold), $error, null, null, $durationMs);
    }

    public function isAvailable(): bool
    {
        return $this->probability !== null;
    }

    public function isSpam(): ?bool
    {
        return $this->isAvailable() ? $this->probability >= $this->threshold : null;
    }

    public function decision(float $reviewThreshold = 0.2): Decision
    {
        if (! is_finite($reviewThreshold) || $reviewThreshold < 0 || $reviewThreshold > $this->threshold) {
            throw new InvalidArgumentException('The review threshold must be finite and between zero and the spam threshold.');
        }

        if (! $this->isAvailable()) {
            return Decision::Review;
        }

        if ($this->isSpam()) {
            return Decision::Block;
        }

        return $this->probability >= $reviewThreshold ? Decision::Review : Decision::Allow;
    }

    public static function resolveThreshold(?float $threshold = null): float
    {
        $threshold ??= config('spam-guard.threshold', 0.9);

        if ((! is_int($threshold) && ! is_float($threshold)) || ! is_finite($threshold) || $threshold < 0 || $threshold > 1) {
            throw new InvalidArgumentException('The spam threshold must be a finite number between 0 and 1.');
        }

        return (float) $threshold;
    }

    private static function validateProbability(float $probability): void
    {
        if (! is_finite($probability) || $probability < 0 || $probability > 1) {
            throw new InvalidArgumentException('The spam probability must be a finite number between 0 and 1.');
        }
    }
}
