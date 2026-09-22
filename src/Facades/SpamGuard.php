<?php

declare(strict_types=1);

namespace Vlados\LaravelSpamGuard\Facades;

use Illuminate\Support\Facades\Facade;
use Vlados\LaravelSpamGuard\SpamGuard as Service;
use Vlados\LaravelSpamGuard\Testing\SpamGuardFake;
use Vlados\LaravelSpamGuard\TypeSafeClient;
use Vlados\LaravelSpamGuard\Verdict;

/**
 * @method static \Vlados\LaravelSpamGuard\Verdict check(mixed $state, ?string $context = null)
 * @method static void assertChecked(callable $callback)
 * @method static void assertCheckedTimes(int $times)
 * @method static void assertNothingChecked()
 *
 * @see Service
 */
class SpamGuard extends Facade
{
    /** @param list<Verdict>|null $verdicts */
    public static function fake(?array $verdicts = null): SpamGuardFake
    {
        $fake = new SpamGuardFake(static::$app['config'], static::$app->make(TypeSafeClient::class), $verdicts);
        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return Service::class;
    }
}
