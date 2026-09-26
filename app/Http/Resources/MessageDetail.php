<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;

final class MessageDetail
{
    /** @return array<string, mixed> */
    public static function fromRow(object $row): array
    {
        $data = [];
        foreach (['id', 'mail_account_id', 'subject', 'from_name', 'from_address', 'direction', 'is_read', 'is_starred', 'is_important', 'is_done', 'has_attachments', 'remote_status'] as $field) {
            $data[$field] = $row->{$field};
        }
        foreach (['to', 'cc', 'bcc', 'reply_to'] as $field) {
            $data[$field] = json_decode($row->{$field}, true) ?: [];
        }
        foreach (['date_header', 'received_at'] as $field) {
            $data[$field] = $row->{$field} === null ? null : CarbonImmutable::parse($row->{$field})->utc()->toIso8601String();
        }
        $available = $row->parse_status !== 'failed' && $row->text_plain !== null && $row->text_plain !== '';
        $data['body_status'] = $available ? 'available' : 'unavailable';
        $data['text_plain'] = $available ? $row->text_plain : null;

        return $data;
    }
}
