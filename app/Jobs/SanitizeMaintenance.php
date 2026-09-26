<?php

namespace App\Jobs;

use App\Messages\EmailHtml;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SanitizeMaintenance implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public int $runId)
    {
        $this->onConnection('maintenance')->onQueue('maintenance');
    }

    public function handle(EmailHtml $sanitizer): void
    {
        $token = (string) Str::uuid();
        $run = DB::transaction(function () use ($token) {
            $run = DB::table('maintenance_runs')->where('id', $this->runId)->lockForUpdate()->first();
            if ($run === null || ! in_array($run->status, ['pending', 'processing'], true)
                || ($run->lease_expires_at !== null && now()->lt($run->lease_expires_at))) {
                return null;
            }
            if ($run->target_version !== EmailHtml::VERSION) {
                DB::table('maintenance_runs')->where('id', $run->id)->update(['status' => 'superseded', 'updated_at' => now()]);

                return null;
            }
            DB::table('maintenance_runs')->where('id', $run->id)->update(['status' => 'processing', 'lease_token' => $token, 'lease_expires_at' => now()->addSeconds(3660)]);

            return $run;
        });
        if ($run === null) {
            return;
        }
        $rows = DB::table('message_bodies')->where('message_id', '>', $run->cursor)->where('sanitizer_version', '<>', EmailHtml::VERSION)
            ->orderBy('message_id')->limit(25)->pluck('message_id');
        $stats = json_decode($run->stats, true);
        foreach ($rows as $id) {
            (new SanitizeMessageHtml($id))->handle($sanitizer);
            $stats['processed']++;
            if (DB::table('message_bodies')->where('message_id', $id)->where('sanitizer_version', '<>', EmailHtml::VERSION)->exists()) {
                $stats['failed']++;
            }
        }
        // A crash before this checkpoint safely repeats idempotent work; lease fences stale workers.
        $pending = $rows->count() === 25;
        $changed = DB::table('maintenance_runs')->where('id', $run->id)->where('lease_token', $token)->update([
            'cursor' => $rows->last() ?? $run->cursor, 'stats' => json_encode($stats),
            'status' => $pending ? 'pending' : ($stats['failed'] ? 'completed_with_errors' : 'completed'),
            'lease_token' => null, 'lease_expires_at' => null, 'next_attempt_at' => now(), 'updated_at' => now(),
        ]);
        if ($changed && $pending) {
            self::dispatch($run->id);
        }
    }
}
