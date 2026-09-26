<?php

namespace App\Conversations;

use App\Organization\OrganizationService;
use Illuminate\Support\Facades\DB;

/** Header-only identity graph. No subject matching, MIME parsing or cross-account edges. */
final class Threader
{
    public static function identifier(?string $value): ?string
    {
        $value = trim((string) $value, "<> \t\r\n");

        return strlen($value) <= 255 && preg_match('/\A[^<>\s@]+@[^<>\s@]+\z/D', $value) ? $value : null;
    }

    public function assign(int $id): void
    {
        $reference = DB::table('messages')->where('id', $id)->first();
        if ($reference === null) {
            return;
        }
        DB::transaction(function () use ($id, $reference) {
            app(OrganizationService::class)->lockVersion($reference->user_id);
            DB::select('SELECT pg_advisory_xact_lock(7312, ?)', [$reference->mail_account_id]);
            $message = DB::table('messages')->where('id', $id)->first();
            $account = $message->mail_account_id;
            $ownId = self::identifier($message->message_id_header);
            $duplicates = $ownId === null ? collect() : DB::table('messages')->where('mail_account_id', $account)->where('message_id_header', $ownId)->get(['id', 'thread_id']);
            if ($duplicates->count() > 1) {
                // A late duplicate invalidates edges made through that formerly unique header.
                // Split affected components, then durably rebuild valid edges in bounded batches.
                $threads = $duplicates->pluck('thread_id')->filter()->unique()->all();
                foreach ($threads as $thread) {
                    if (DB::table('messages')->where('thread_id', $thread)->count() > 1) {
                        DB::statement('INSERT INTO thread_repairs(message_id, after_id, updated_at) SELECT id, 0, now() FROM messages WHERE thread_id = ? ON CONFLICT(message_id) DO UPDATE SET after_id = 0, updated_at = now()', [$thread]);
                        DB::table('messages')->where('thread_id', $thread)->update(['thread_id' => null]);
                        DB::table('threads')->where('id', $thread)->delete();
                    }
                }
                $message = DB::table('messages')->where('id', $id)->first();
            }
            $thread = $message->thread_id ?? DB::table('threads')->insertGetId([
                'user_id' => $message->user_id, 'mail_account_id' => $account,
                'subject_normalized' => mb_substr($message->subject, 0, 2000),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('messages')->where('id', $id)->update(['thread_id' => $thread]);
            $refs = array_values(array_unique(array_filter(array_map(self::identifier(...), [
                $message->in_reply_to, ...array_slice(json_decode($message->references, true), -20),
            ]), fn ($ref) => $ref !== null && $ref !== $ownId)));
            $unique = DB::table('messages')->where('mail_account_id', $account)->whereIn('message_id_header', $refs)
                ->groupBy('message_id_header')->havingRaw('count(*) = 1')->pluck('message_id_header')->all();
            $after = (int) (DB::table('thread_repairs')->where('message_id', $id)->value('after_id') ?? 0);
            $candidates = collect();
            if ($duplicates->count() <= 1 && ($unique !== [] || $ownId !== null)) {
                $candidates = DB::table('messages')->where('mail_account_id', $account)->where('id', '<>', $id)->where('id', '>', $after)
                    ->where(function ($q) use ($unique, $ownId) {
                        $q->whereIn('message_id_header', $unique);
                        if ($ownId !== null) {
                            $q->orWhere('in_reply_to', $ownId)->orWhereJsonContains('references', $ownId);
                        }
                    })->orderBy('id')->limit(101)->get();
            }
            foreach ($candidates->take(100) as $candidate) {
                // Ignore references outside the last twenty, and ambiguous child identities.
                $candidateRefs = array_slice(json_decode($candidate->references, true), -20);
                if (! in_array($candidate->message_id_header, $unique, true) && $candidate->in_reply_to !== $ownId && ! in_array($ownId, $candidateRefs, true)) {
                    continue;
                }
                if ($candidate->message_id_header !== null && DB::table('messages')->where('mail_account_id', $account)->where('message_id_header', $candidate->message_id_header)->count() > 1) {
                    continue;
                }
                if ($candidate->thread_id === null) {
                    DB::table('messages')->where('id', $candidate->id)->update(['thread_id' => $thread]);
                } elseif ($candidate->thread_id !== $thread) {
                    $keep = min($thread, $candidate->thread_id);
                    $drop = max($thread, $candidate->thread_id);
                    DB::table('messages')->where('thread_id', $drop)->update(['thread_id' => $keep]);
                    DB::table('threads')->where('id', $drop)->delete();
                    $thread = $keep;
                }
            }
            $summary = DB::table('messages')->where('thread_id', $thread)->selectRaw('count(*) AS total, min(sort_date) AS first, max(sort_date) AS last')->first();
            DB::table('threads')->where('id', $thread)->update([
                'message_count' => $summary->total, 'first_message_at' => $summary->first, 'last_message_at' => $summary->last, 'updated_at' => now(),
            ]);
            if ($candidates->count() > 100) {
                DB::table('thread_repairs')->updateOrInsert(['message_id' => $id], ['after_id' => $candidates[99]->id, 'updated_at' => now()]);
            } else {
                DB::table('thread_repairs')->where('message_id', $id)->delete();
            }
        }, 5);
    }
}
