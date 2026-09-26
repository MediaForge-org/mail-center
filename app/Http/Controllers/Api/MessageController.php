<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MessageDetail;
use App\Http\Resources\MessageListItem;
use App\Messages\AttachmentMetadata;
use App\Messages\EmailHtml;
use App\Messages\MessageFilter;
use App\Messages\MessageView;
use App\Storage\BlobStore;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class MessageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (array_diff(array_keys($request->query()), ['view', 'limit', 'cursor', 'account_id']) !== []) {
            throw ValidationException::withMessages(['query' => 'Unknown message filter.']);
        }
        $input = $request->validate([
            'account_id' => ['sometimes', 'integer', 'min:1'],
            'view' => ['sometimes', Rule::in(['all', 'inbox', 'unread'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string', 'max:2048'],
        ]);
        $view = MessageView::from($input['view'] ?? 'all');
        $accountId = isset($input['account_id']) ? (int) $input['account_id'] : null;
        if ($accountId !== null && $view !== MessageView::All) {
            throw ValidationException::withMessages(['account_id' => 'Account mailboxes use the all view.']);
        }
        $filter = new MessageFilter($view, $accountId);
        $limit = (int) ($input['limit'] ?? 50);
        $userId = (int) $request->user()->getAuthIdentifier();
        if ($accountId !== null) {
            abort_unless(DB::table('mail_accounts')->where('id', $accountId)->where('user_id', $userId)->whereNull('deleted_at')->exists(), 404);
        }
        $after = isset($input['cursor']) ? $this->decodeCursor($input['cursor'], $userId, $filter) : null;
        $query = $filter->query($userId);

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
            'next_cursor' => $hasMore && $last !== null ? $this->encodeCursor($last, $userId, $filter) : null,
        ]);
    }

    public function counts(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();
        $views = [];
        foreach (MessageView::cases() as $view) {
            $row = (new MessageFilter($view))->query($userId)
                ->selectRaw('count(*) AS total, count(*) FILTER (WHERE messages.is_read = false) AS unread')->first();
            $views[$view->value] = ['total' => (int) $row->total, 'unread' => (int) $row->unread];
        }
        $accounts = DB::table('mail_accounts')->where('user_id', $userId)->whereNull('deleted_at')
            ->pluck('id')->mapWithKeys(fn ($id) => [(string) $id => ['total' => 0, 'unread' => 0]])->all();
        $rows = MessageFilter::base($userId, true)->groupBy('messages.mail_account_id')
            ->selectRaw('messages.mail_account_id, count(*) AS total, count(*) FILTER (WHERE messages.is_read = false) AS unread')->get();
        foreach ($rows as $row) {
            $accounts[(string) $row->mail_account_id] = ['total' => (int) $row->total, 'unread' => (int) $row->unread];
        }

        return response()->json(['views' => $views, 'accounts' => (object) $accounts])
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $row = $this->readableMessage($request, $id)
            ->select([
                'messages.id', 'messages.mail_account_id', 'messages.subject',
                'messages.from_name', 'messages.from_address', 'messages.to',
                'messages.cc', 'messages.bcc', 'messages.reply_to', 'messages.date_header',
                'messages.received_at', 'messages.direction', 'messages.is_read',
                'messages.is_starred', 'messages.is_important', 'messages.is_done',
                'messages.has_attachments', 'messages.remote_status', 'messages.parse_status',
                'message_bodies.text_plain', 'message_bodies.sanitizer_version', 'message_bodies.remote_content_count',
                DB::raw("(message_bodies.html_sanitized IS NOT NULL AND message_bodies.html_sanitized <> '') AS has_html"),
            ])->first();
        abort_if($row === null, 404);

        $data = MessageDetail::fromRow($row);
        $data['attachments'] = DB::table('attachments')->where('message_id', $id)
            ->orderBy('part_order')->orderBy('id')
            ->get(['id', 'filename', 'content_type', 'size_bytes', 'disposition'])
            ->map(fn ($attachment) => AttachmentMetadata::fromRow($attachment))->all();

        return response()->json(['data' => $data]);
    }

    public function downloadAttachment(Request $request, int $id, int $attachment, BlobStore $blobs): BinaryFileResponse
    {
        abort_unless($this->readableMessage($request, $id)->exists(), 404);
        $row = DB::table('attachments')->where('message_id', $id)->where('id', $attachment)->first();
        abort_if($row === null, 404);
        $path = $blobs->verifiedPath($row->blob_sha256);
        abort_if($path === null || filesize($path) !== (int) $row->size_bytes, 404);

        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => AttachmentMetadata::contentType($row->content_type),
            'Content-Length' => (string) $row->size_bytes,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ], false);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, AttachmentMetadata::filename($row->filename), 'attachment');

        return $response;
    }

    private function readableMessage(Request $request, int $id): Builder
    {
        $userId = (int) $request->user()->getAuthIdentifier();

        return DB::table('messages')
            ->join('mail_accounts', 'mail_accounts.id', '=', 'messages.mail_account_id')
            ->leftJoin('message_bodies', 'message_bodies.message_id', '=', 'messages.id')
            ->where('messages.id', $id)
            ->where('messages.user_id', $userId)
            ->where('mail_accounts.user_id', $userId)
            ->whereNull('mail_accounts.deleted_at')
            ->whereNull('messages.deleted_at');
    }

    public function render(Request $request, int $id): Response
    {
        $row = $this->readableMessage($request, $id)
            ->where('message_bodies.sanitizer_version', EmailHtml::VERSION)
            ->where('messages.parse_status', '<>', 'failed')
            ->whereNotNull('message_bodies.html_sanitized')
            ->where('message_bodies.html_sanitized', '<>', '')
            ->select('message_bodies.html_sanitized')->first();
        abort_if($row === null, 404);

        // Only versioned sanitizer output enters this standalone document.
        return response('<!doctype html><html><head><meta charset="utf-8"><title>Message</title>'
            .'<style>body{font:14px/1.6 system-ui,sans-serif;margin:16px;overflow-wrap:anywhere}pre{white-space:pre-wrap}table{max-width:100%;border-collapse:collapse}td,th{padding:4px}a{color:#315acb}</style>'
            .'</head><body>'.$row->html_sanitized.'</body></html>', 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Content-Security-Policy' => EmailHtml::CSP,
                'X-Content-Type-Options' => 'nosniff',
                'Referrer-Policy' => 'no-referrer',
                'Cache-Control' => 'private, no-store',
            ]);
    }

    private function encodeCursor(object $row, int $userId, MessageFilter $filter): string
    {
        return Crypt::encryptString(json_encode([
            'v' => 1,
            'user_id' => $userId,
            'filter' => $filter->cursorScope(),
            'sort_date' => CarbonImmutable::parse($row->sort_date)->utc()->format('Y-m-d H:i:s.uP'),
            'id' => (int) $row->id,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{sort_date: string, id: int} */
    private function decodeCursor(string $cursor, int $userId, MessageFilter $filter): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $exception) {
            throw ValidationException::withMessages(['cursor' => 'Invalid cursor.']);
        }

        if (! is_array($payload) || array_keys($payload) !== ['v', 'user_id', 'filter', 'sort_date', 'id']
            || $payload['v'] !== 1 || $payload['user_id'] !== $userId
            || $payload['filter'] !== $filter->cursorScope() || ! is_string($payload['sort_date'])
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
