<?php

declare(strict_types=1);

namespace Vlados\LaravelSpamGuard\Testing;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use OutOfBoundsException;
use PHPUnit\Framework\Assert;
use Vlados\LaravelSpamGuard\Configuration;
use Vlados\LaravelSpamGuard\SpamGuard;
use Vlados\LaravelSpamGuard\TypeSafeClient;
use Vlados\LaravelSpamGuard\Verdict;

class SpamGuardFake extends SpamGuard
{
    private array $calls = [];

    private ?array $verdicts;

    /** @param list<Verdict>|null $verdicts Null accepts every check; an array is a finite sequence. */
    public function __construct(Repository $config, TypeSafeClient $client, ?array $verdicts = null)
    {
        parent::__construct($config, $client);

        foreach ($verdicts ?? [] as $verdict) {
            if (! $verdict instanceof Verdict) {
                throw new InvalidArgumentException('Fake responses must be Verdict instances.');
            }
        }

        $this->verdicts = $verdicts === null ? null : array_values($verdicts);
    }

    protected function evaluate(string|array $state, string $context, Configuration $configuration): Verdict
    {
        $this->calls[] = ['state' => $state, 'context' => $context];

        if ($this->verdicts === null) {
            return Verdict::classified(0.0, threshold: $configuration->threshold);
        }

        if ($this->verdicts === []) {
            throw new OutOfBoundsException('SpamGuard fake verdict sequence is exhausted.');
        }

        return array_shift($this->verdicts);
    }

    public function assertChecked(callable $callback): void
    {
        foreach ($this->calls as $call) {
            if ($callback($call['state'], $call['context'])) {
                Assert::assertTrue(true);

                return;
            }
        }

        Assert::fail('No spam check matched the given callback.');
    }

    public function assertCheckedTimes(int $times): void
    {
        Assert::assertCount($times, $this->calls, 'Unexpected number of spam checks.');
    }

    public function assertNothingChecked(): void
    {
        $this->assertCheckedTimes(0);
    }
}
