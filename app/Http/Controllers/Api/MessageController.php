<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MessageListItem;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MessageController extends Controller
{
    private const FILTER_HASH = 'all-mail:v1';

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string', 'max:2048'],
        ]);
        $limit = (int) ($input['limit'] ?? 50);
        $userId = (int) $request->user()->getAuthIdentifier();
        $after = isset($input['cursor']) ? $this->decodeCursor($input['cursor'], $userId) : null;

        $query = DB::table('messages')
            ->join('mail_accounts', 'mail_accounts.id', '=', 'messages.mail_account_id')
            ->where('messages.user_id', $userId)
            ->where('mail_accounts.user_id', $userId)
            ->where('mail_accounts.enabled', true)
            ->whereNull('mail_accounts.deleted_at')
            ->whereNull('messages.deleted_at');

        if ($after !== null) {
            $query->where(function ($query) use ($after) {
                $query->where('messages.sort_date', '<', $after['sort_date'])
                    ->orWhere(function ($query) use ($after) {
                        $query->where('messages.sort_date', '=', $after['sort_date'])
                            ->where('messages.id', '<', $after['id']);
                    });
            });
        }

        $rows = $query->select([
            'messages.id', 'messages.mail_account_id', 'messages.subject',
            'messages.from_name', 'messages.from_address', 'messages.to',
            'messages.snippet', 'messages.sort_date', 'messages.received_at',
            'messages.is_read', 'messages.is_starred', 'messages.is_important',
            'messages.is_done', 'messages.has_attachments', 'messages.direction',
        ])->orderByDesc('messages.sort_date')->orderByDesc('messages.id')
            ->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);
        $last = $page->last();

        return response()->json([
            'data' => $page->map(fn ($row) => MessageListItem::fromRow($row))->values(),
            'next_cursor' => $hasMore && $last !== null ? $this->encodeCursor($last, $userId) : null,
        ]);
    }

    private function encodeCursor(object $row, int $userId): string
    {
        return Crypt::encryptString(json_encode([
            'v' => 1,
            'user_id' => $userId,
            'filter' => self::FILTER_HASH,
            'sort_date' => CarbonImmutable::parse($row->sort_date)->utc()->format('Y-m-d H:i:s.uP'),
            'id' => (int) $row->id,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{sort_date: string, id: int} */
    private function decodeCursor(string $cursor, int $userId): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $exception) {
            throw ValidationException::withMessages(['cursor' => 'Invalid cursor.']);
        }

        if (! is_array($payload) || array_keys($payload) !== ['v', 'user_id', 'filter', 'sort_date', 'id']
            || $payload['v'] !== 1 || $payload['user_id'] !== $userId
            || $payload['filter'] !== self::FILTER_HASH || ! is_string($payload['sort_date'])
            || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}\+00:00$/', $payload['sort_date'])
            || ! is_int($payload['id']) || $payload['id'] < 1) {
            throw ValidationException::withMessages(['cursor' => 'Invalid cursor.']);
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d H:i:s.uP', $payload['sort_date']);
        if ($date === null || $date->format('Y-m-d H:i:s.uP') !== $payload['sort_date']) {
            throw ValidationException::withMessages(['cursor' => 'Invalid cursor.']);
        }

        return ['sort_date' => $payload['sort_date'], 'id' => $payload['id']];
    }
}
