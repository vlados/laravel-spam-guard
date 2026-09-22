<?php

declare(strict_types=1);

namespace Vlados\LaravelSpamGuard\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;
use Vlados\LaravelSpamGuard\TurnstileClient;

class Turnstile implements ValidationRule
{
    public bool $implicit = true;

    public function __construct(private string $action)
    {
        if (! preg_match('/\A[A-Za-z0-9_-]{1,32}\z/', $action)) {
            throw new InvalidArgumentException('Turnstile action must contain 1 to 32 letters, digits, underscores or hyphens.');
        }
    }

    public static function make(string $action): static
    {
        return new static($action);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '' || ! mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > 2048) {
            $fail('spam-guard::validation.bot')->translate();

            return;
        }

        $verified = app(TurnstileClient::class)->verify($value, $this->action);

        if ($verified !== true) {
            $fail($verified === null ? 'spam-guard::validation.bot_unavailable' : 'spam-guard::validation.bot')->translate();
        }
    }
}
