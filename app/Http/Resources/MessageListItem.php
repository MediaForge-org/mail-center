<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;

final class MessageListItem
{
    /** @return array<string, mixed> */
    public static function fromRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'mail_account_id' => (int) $row->mail_account_id,
            'subject' => $row->subject,
            'from_name' => $row->from_name,
            'from_address' => $row->from_address,
            'to' => json_decode($row->to, true) ?: [],
            'snippet' => $row->snippet,
            'sort_date' => CarbonImmutable::parse($row->sort_date)->utc()->toIso8601String(),
            'received_at' => $row->received_at === null ? null : CarbonImmutable::parse($row->received_at)->utc()->toIso8601String(),
            'is_read' => $row->is_read,
            'is_starred' => $row->is_starred,
            'is_important' => $row->is_important,
            'is_done' => $row->is_done,
            'has_attachments' => $row->has_attachments,
            'direction' => $row->direction,
        ];
    }
}
