<?php

declare(strict_types=1);

namespace Vlados\LaravelSpamGuard;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/** @internal */
final readonly class Configuration
{
    public float $threshold;

    public bool $failOpen;

    public string $context;

    public string $model;

    public float $timeout;

    public float $connectTimeout;

    public int $maxStateChars;

    public function __construct(Repository $config)
    {
        $threshold = $config->get('spam-guard.threshold');
        if (! self::isNumber($threshold) || $threshold < 0 || $threshold > 1) {
            throw new InvalidArgumentException('spam-guard.threshold must be a finite number between 0 and 1.');
        }
        $this->threshold = (float) $threshold;

        $failOpen = $config->get('spam-guard.fail_open');
        if (! is_bool($failOpen)) {
            throw new InvalidArgumentException('spam-guard.fail_open must be a boolean.');
        }
        $this->failOpen = $failOpen;
        $this->context = self::text($config->get('spam-guard.context'), 'spam-guard.context');
        $this->model = self::text($config->get('spam-guard.model'), 'spam-guard.model');

        $timeout = $config->get('spam-guard.timeout');
        $connectTimeout = $config->get('spam-guard.connect_timeout');
        if (! self::isNumber($timeout) || ! self::isNumber($connectTimeout) || $timeout <= 0 || $connectTimeout <= 0 || $connectTimeout > $timeout) {
            throw new InvalidArgumentException('Spam Guard timeouts must be positive finite numbers, with connect_timeout <= timeout.');
        }
        $this->timeout = (float) $timeout;
        $this->connectTimeout = (float) $connectTimeout;

        $limit = $config->get('spam-guard.max_state_chars');
        if (! is_int($limit) || $limit < 1) {
            throw new InvalidArgumentException('spam-guard.max_state_chars must be a positive integer.');
        }
        $this->maxStateChars = $limit;
    }

    public static function text(mixed $value, string $name): string
    {
        if (! is_string($value) || trim($value) === '' || ! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException($name.' must be a nonempty UTF-8 string.');
        }

        return $value;
    }

    private static function isNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite($value);
    }
}
