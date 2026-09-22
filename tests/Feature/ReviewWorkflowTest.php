<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Vlados\LaravelSpamGuard\Facades\SpamGuard;
use Vlados\LaravelSpamGuard\Tests\Fixtures\DeliverSubmission;
use Vlados\LaravelSpamGuard\Verdict;

beforeEach(function () {
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    Schema::create('contact_submissions', function (Blueprint $table) {
        $table->id();
        $table->text('description');
        $table->string('status');
        $table->double('spam_probability')->nullable();
        $table->string('spam_error')->nullable();
    });
    Queue::fake();
});

it('persists the review policy result and queues delivery only for approved messages', function (?float $probability, string $expectedStatus) {
    SpamGuard::fake([$probability === null ? Verdict::unavailable('timeout') : Verdict::classified($probability)]);
    $submit = require __DIR__.'/../../examples/review-workflow.php';
    $result = $submit(['description' => 'A synthetic submission'], function ($id) {
        expect(DB::transactionLevel())->toBe(0);
        DeliverSubmission::dispatch($id);
    });

    expect($result['status'])->toBe($expectedStatus)
        ->and(DB::table('contact_submissions')->where('id', $result['id'])->value('status'))->toBe($expectedStatus);
    if ($expectedStatus === 'approved') {
        Queue::assertPushed(DeliverSubmission::class, fn ($job) => $job->submissionId === $result['id']);
        Queue::assertPushed(DeliverSubmission::class, 1);
    } else {
        Queue::assertNothingPushed();
    }
    SpamGuard::assertCheckedTimes(1);
    Http::assertNothingSent();
})->with([[0.05, 'approved'], [0.2, 'pending_review'], [0.89, 'pending_review'], [0.9, 'blocked'], [null, 'pending_review']]);

it('does not queue delivery when persistence fails', function () {
    SpamGuard::fake();
    Schema::drop('contact_submissions');
    $submit = require __DIR__.'/../../examples/review-workflow.php';

    expect(fn () => $submit(['description' => 'A synthetic submission'], fn ($id) => DeliverSubmission::dispatch($id)))
        ->toThrow(QueryException::class);
    Queue::assertNothingPushed();
});
