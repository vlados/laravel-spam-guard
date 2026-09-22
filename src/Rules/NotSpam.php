<?php

declare(strict_types=1);

namespace Vlados\LaravelSpamGuard\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Vlados\LaravelSpamGuard\Configuration;
use Vlados\LaravelSpamGuard\Exceptions\InvalidState;
use Vlados\LaravelSpamGuard\Exceptions\StateTooLarge;
use Vlados\LaravelSpamGuard\SpamGuard;

class NotSpam implements DataAwareRule, ValidationRule
{
    private array $data = [];

    private array $fields = [];

    private ?string $formContext = null;

    public static function make(): static
    {
        return new static;
    }

    public function context(string $context): static
    {
        $this->formContext = Configuration::text($context, 'context');

        return $this;
    }

    /** @param list<string> $fields Explicit scalar field paths; wildcards are not supported. */
    public function withFields(array $fields): static
    {
        foreach ($fields as $field) {
            if (! is_string($field) || trim($field) === '' || str_contains($field, '*')) {
                throw new InvalidArgumentException('Sibling fields must be nonempty explicit paths without wildcards.');
            }
        }

        $this->fields = array_values(array_unique($fields));

        return $this;
    }

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $configuration = new Configuration(app('config'));
        if (! is_string($value)) {
            $fail('spam-guard::validation.invalid')->translate();

            return;
        }

        $state = [$attribute => $value];
        foreach ($this->fields as $field) {
            if ($field === $attribute || ! Arr::has($this->data, $field)) {
                continue;
            }

            $sibling = Arr::get($this->data, $field);
            if (! is_scalar($sibling) && $sibling !== null) {
                $fail('spam-guard::validation.invalid')->translate();

                return;
            }

            $state[$field] = $sibling;
        }

        try {
            $verdict = app(SpamGuard::class)->check($state, $this->formContext);
        } catch (StateTooLarge) {
            $fail('spam-guard::validation.too_long')->translate(['max' => $configuration->maxStateChars]);

            return;
        } catch (InvalidState) {
            $fail('spam-guard::validation.invalid')->translate();

            return;
        }

        if (! $verdict->isAvailable()) {
            if (! $configuration->failOpen) {
                $fail('spam-guard::validation.unavailable')->translate();
            }

            return;
        }

        if ($verdict->isSpam()) {
            $fail('spam-guard::validation.spam')->translate();
        }
    }
}
