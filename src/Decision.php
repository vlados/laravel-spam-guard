<?php

declare(strict_types=1);

namespace Vlados\LaravelSpamGuard;

enum Decision: string
{
    case Allow = 'allow';
    case Review = 'review';
    case Block = 'block';
}
