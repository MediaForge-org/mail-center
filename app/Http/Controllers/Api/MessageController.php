<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MessageDetail;
use App\Http\Resources\MessageListItem;
use App\Messages\MessageView;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MessageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (array_diff(array_keys($request->query()), ['view', 'limit', 'cursor']) !== []) {
            throw ValidationException::withMessages(['query' => 'Unknown message filter.']);
        }
        $input = $request->validate([
            'view' => ['sometimes', Rule::in(['all', 'inbox', 'unread'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string', 'max:2048'],
        ]);
        $view = MessageView::from($input['view'] ?? 'all');
        $limit = (int) ($input['limit'] ?? 50);
        $userId = (int) $request->user()->getAuthIdentifier();
        $after = isset($input['cursor']) ? $this->decodeCursor($input['cursor'], $userId, $view) : null;

        $query = DB::table('messages')
            ->join('mail_accounts', 'mail_accounts.id', '=', 'messages.mail_account_id')
            ->where('messages.user_id', $userId)
            ->where('mail_accounts.user_id', $userId)
            ->where('mail_accounts.enabled', true)
            ->whereNull('mail_accounts.deleted_at')
            ->whereNull('messages.deleted_at');
        $view->apply($query, $userId);

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
            'next_cursor' => $hasMore && $last !== null ? $this->encodeCursor($last, $userId, $view) : null,
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();
        $row = DB::table('messages')
            ->join('mail_accounts', 'mail_accounts.id', '=', 'messages.mail_account_id')
            ->leftJoin('message_bodies', 'message_bodies.message_id', '=', 'messages.id')
            ->where('messages.id', $id)
            ->where('messages.user_id', $userId)
            ->where('mail_accounts.user_id', $userId)
            ->where('mail_accounts.enabled', true)
            ->whereNull('mail_accounts.deleted_at')
            ->whereNull('messages.deleted_at')
            ->select([
                'messages.id', 'messages.mail_account_id', 'messages.subject',
                'messages.from_name', 'messages.from_address', 'messages.to',
                'messages.cc', 'messages.bcc', 'messages.reply_to', 'messages.date_header',
                'messages.received_at', 'messages.direction', 'messages.is_read',
                'messages.is_starred', 'messages.is_important', 'messages.is_done',
                'messages.has_attachments', 'messages.remote_status', 'messages.parse_status',
                'message_bodies.text_plain',
            ])->first();
        abort_if($row === null, 404);

        return response()->json(['data' => MessageDetail::fromRow($row)]);
    }

    private function encodeCursor(object $row, int $userId, MessageView $view): string
    {
        return Crypt::encryptString(json_encode([
            'v' => 1,
            'user_id' => $userId,
            'filter' => $view->cursorScope(),
            'sort_date' => CarbonImmutable::parse($row->sort_date)->utc()->format('Y-m-d H:i:s.uP'),
            'id' => (int) $row->id,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{sort_date: string, id: int} */
    private function decodeCursor(string $cursor, int $userId, MessageView $view): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $exception) {
            throw ValidationException::withMessages(['cursor' => 'Invalid cursor.']);
        }

        if (! is_array($payload) || array_keys($payload) !== ['v', 'user_id', 'filter', 'sort_date', 'id']
            || $payload['v'] !== 1 || $payload['user_id'] !== $userId
            || $payload['filter'] !== $view->cursorScope() || ! is_string($payload['sort_date'])
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
