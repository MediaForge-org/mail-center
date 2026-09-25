<?php

namespace App\Ingestion;

use App\Models\MailAccount;
use App\Models\RemoteFolder;
use App\Storage\BlobStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;
use ZBateson\MailMimeParser\Message;

class MessageIngestor
{
    public function __construct(private readonly BlobStore $blobs) {}

    public function ingest(MailAccount $account, RemoteFolder $folder, int $uid, int $uidvalidity, string $raw, array $flags, ?string $internalDate): int
    {
        $sha = $this->blobs->put($raw);
        $parsed = $this->parse($raw);
        $received = $this->date($internalDate);
        $date = $this->date($parsed['date']);
        $now = now()->utc();

        return DB::transaction(function () use ($account, $folder, $uid, $uidvalidity, $raw, $flags, $sha, $parsed, $received, $date, $now) {
            $key = 'raw-v1:'.$sha;
            $existing = DB::table('messages')->where('mail_account_id', $account->id)->where('dedupe_key', $key)->first();
            if ($existing) {
                $messageId = $existing->id;
            } else {
                $messageId = DB::table('messages')->insertGetId([
                    'user_id' => $account->user_id, 'mail_account_id' => $account->id,
                    'dedupe_key' => $key, 'message_id_header' => $parsed['message_id'],
                    'in_reply_to' => $parsed['in_reply_to'], 'references' => json_encode($parsed['references']),
                    'subject' => $parsed['subject'], 'from_name' => $parsed['from_name'],
                    'from_address' => $parsed['from_address'], 'to' => json_encode($parsed['to']),
                    'cc' => json_encode($parsed['cc']), 'bcc' => json_encode($parsed['bcc']),
                    'reply_to' => json_encode($parsed['reply_to']), 'date_header' => $date,
                    'received_at' => $received, 'sort_date' => $received ?? $date ?? $now,
                    'direction' => $folder->role === 'sent' ? 'outbound' : 'inbound',
                    'snippet' => mb_substr(trim(preg_replace('/\s+/u', ' ', $parsed['text']) ?? ''), 0, 280),
                    'size_bytes' => strlen($raw), 'has_attachments' => $parsed['attachments'],
                    'raw_blob_sha256' => $sha, 'parse_status' => $parsed['status'],
                    'remote_seen' => in_array('\\Seen', $flags, true),
                    'remote_flagged' => in_array('\\Flagged', $flags, true),
                    'is_read' => in_array('\\Seen', $flags, true),
                    'is_starred' => in_array('\\Flagged', $flags, true),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('message_bodies')->insert([
                    'message_id' => $messageId, 'text_plain' => $parsed['text'],
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            DB::table('message_locations')->updateOrInsert(
                ['remote_folder_id' => $folder->id, 'uidvalidity' => $uidvalidity, 'uid' => $uid],
                ['message_id' => $messageId, 'mail_account_id' => $account->id,
                    'flags' => json_encode(array_values($flags)), 'first_seen_at' => $now,
                    'last_seen_at' => $now, 'removed_at' => null, 'removed_reason' => null]
            );
            $this->refreshRemoteSummary($messageId);

            return $messageId;
        });
    }

    public function refreshRemoteSummary(int $messageId): void
    {
        $locations = DB::table('message_locations')->where('message_id', $messageId)->whereNull('removed_at')->get();
        $seen = false;
        $flagged = false;
        foreach ($locations as $location) {
            $flags = json_decode($location->flags, true) ?: [];
            $seen = $seen || in_array('\\Seen', $flags, true);
            $flagged = $flagged || in_array('\\Flagged', $flags, true);
        }
        DB::table('messages')->where('id', $messageId)->update([
            'remote_status' => $locations->isEmpty() ? 'unknown' : 'present',
            'remote_seen' => $seen, 'remote_flagged' => $flagged,
            'remote_missing_since' => $locations->isEmpty() ? DB::raw('remote_missing_since') : null,
            'remote_removed_at' => $locations->isEmpty() ? DB::raw('remote_removed_at') : null,
            'updated_at' => now(),
        ]);
    }

    private function parse(string $raw): array
    {
        $data = [
            'status' => 'failed', 'message_id' => null, 'in_reply_to' => null, 'references' => [],
            'subject' => '', 'from_name' => '', 'from_address' => '', 'to' => [], 'cc' => [],
            'bcc' => [], 'reply_to' => [], 'date' => null, 'text' => '', 'attachments' => false,
        ];
        try {
            $message = Message::from($raw, true);
            $data['subject'] = mb_substr((string) $message->getSubject(), 0, 2000);
            $data['message_id'] = $this->identifier($message->getHeaderValue('Message-ID'));
            $data['in_reply_to'] = $this->identifier($message->getHeaderValue('In-Reply-To'));
            preg_match_all('/<([^<>\s]{1,255})>/', (string) $message->getHeaderValue('References'), $matches);
            $data['references'] = array_slice($matches[1], -50);
            $from = $this->addresses($message->getHeaderValue('From'));
            $data['from_name'] = $from[0]['name'] ?? '';
            $data['from_address'] = $from[0]['address'] ?? '';
            foreach (['To' => 'to', 'Cc' => 'cc', 'Bcc' => 'bcc', 'Reply-To' => 'reply_to'] as $header => $field) {
                $data[$field] = $this->addresses($message->getHeaderValue($header));
            }
            $data['date'] = $message->getHeaderValue('Date');
            $data['text'] = mb_substr((string) $message->getTextContent(), 0, 1024 * 1024);
            $data['attachments'] = $message->getAttachmentCount() > 0;
            $data['status'] = 'ok';
        } catch (Throwable) {
            // The complete raw MIME remains available for a later bounded re-parse.
        }

        return $data;
    }

    private function identifier(?string $value): ?string
    {
        $id = trim((string) $value, "<> \t\r\n");

        return $id !== '' && strlen($id) <= 255 ? $id : null;
    }

    private function addresses(?string $value): array
    {
        $result = [];
        foreach (array_slice(explode(',', (string) $value), 0, 100) as $part) {
            if (preg_match('/^(.*?)<([^<>]+)>$/', trim($part), $matches)) {
                $name = trim($matches[1], ' "');
                $address = trim($matches[2]);
            } else {
                $name = '';
                $address = trim($part);
            }
            if (filter_var($address, FILTER_VALIDATE_EMAIL)) {
                $result[] = ['name' => mb_substr($name, 0, 255), 'address' => mb_substr($address, 0, 254)];
            }
        }

        return $result;
    }

    private function date(?string $value): ?Carbon
    {
        try {
            // Normalize to UTC: the query builder writes only the wall-clock string, so a retained
            // offset (e.g. +0200) would be silently reinterpreted as UTC by timestamptz columns.
            return $value ? Carbon::parse($value)->utc() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
