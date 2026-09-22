<?php

namespace Vlados\LaravelSpamGuard\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class DeliverSubmission implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(public int $submissionId) {}

    public function handle(): void {}
}
