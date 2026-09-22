<?php

declare(strict_types=1);

namespace Vlados\LaravelSpamGuard;

use JsonException;
use Vlados\LaravelSpamGuard\Exceptions\InvalidState;
use Vlados\LaravelSpamGuard\Exceptions\StateTooLarge;

/** @internal */
final class State
{
    public const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public static function validate(mixed $state, int $limit): void
    {
        if (! is_string($state) && ! is_array($state)) {
            throw new InvalidState('Spam state must be a string or an array of JSON values.');
        }

        self::validateValue($state);

        try {
            $json = json_encode($state, self::JSON_FLAGS, 64);
        } catch (JsonException) {
            throw new InvalidState('Spam state must contain valid UTF-8 and finite JSON values.');
        }

        if (mb_strlen($json, 'UTF-8') > $limit) {
            throw new StateTooLarge('Composed spam state exceeds the configured character limit.');
        }
    }

    private static function validateValue(mixed $value, int $depth = 0): void
    {
        if ($depth > 64 || is_object($value) || is_resource($value)) {
            throw new InvalidState('Spam state cannot contain objects, resources, or more than 64 nested levels.');
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                self::validateValue($item, $depth + 1);
            }
        }
    }
}
