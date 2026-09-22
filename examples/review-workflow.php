<?php

use Illuminate\Support\Facades\DB;
use Vlados\LaravelSpamGuard\Decision;
use Vlados\LaravelSpamGuard\Facades\SpamGuard;

return static function (array $validated, callable $queueDelivery): array {
    $verdict = SpamGuard::check(['description' => $validated['description']]);
    $decision = $verdict->decision(reviewThreshold: 0.2);

    return DB::transaction(function () use ($validated, $verdict, $decision, $queueDelivery) {
        $status = match ($decision) {
            Decision::Allow => 'approved',
            Decision::Review => 'pending_review',
            Decision::Block => 'blocked',
        };
        $id = DB::table('contact_submissions')->insertGetId([
            'description' => $validated['description'],
            'status' => $status,
            'spam_probability' => $verdict->probability,
            'spam_error' => $verdict->error,
        ]);

        if ($decision === Decision::Allow) {
            DB::afterCommit(fn () => $queueDelivery($id));
        }

        return ['id' => $id, 'status' => $status];
    });
};
