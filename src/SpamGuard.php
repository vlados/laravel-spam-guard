<?php

declare(strict_types=1);

namespace Vlados\LaravelSpamGuard;

use Illuminate\Contracts\Config\Repository;

class SpamGuard
{
    public function __construct(protected Repository $config, protected TypeSafeClient $client) {}

    public function check(mixed $state, ?string $context = null): Verdict
    {
        $configuration = new Configuration($this->config);
        $context = Configuration::text($context ?? $configuration->context, 'context');
        State::validate($state, $configuration->maxStateChars);

        return $this->evaluate($state, $context, $configuration);
    }

    protected function evaluate(string|array $state, string $context, Configuration $configuration): Verdict
    {
        return $this->client->check($state, $context, $configuration);
    }
}
