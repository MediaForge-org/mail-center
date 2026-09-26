<?php

namespace App\Messages;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class MessageFilter
{
    public function __construct(public MessageView $view = MessageView::All, public ?int $accountId = null) {}

    public static function base(int $userId, bool $includeDisabled = false): Builder
    {
        return DB::table('messages')
            ->join('mail_accounts', 'mail_accounts.id', '=', 'messages.mail_account_id')
            ->where('messages.user_id', $userId)->where('mail_accounts.user_id', $userId)
            ->when(! $includeDisabled, fn ($query) => $query->where('mail_accounts.enabled', true))
            ->whereNull('mail_accounts.deleted_at')->whereNull('messages.deleted_at');
    }

    public function query(int $userId): Builder
    {
        $query = self::base($userId, $this->accountId !== null);
        if ($this->accountId !== null) {
            $query->where('messages.mail_account_id', $this->accountId);
        }
        $this->view->apply($query, $userId);

        return $query;
    }

    public function cursorScope(): string
    {
        return $this->accountId === null ? $this->view->cursorScope() : 'account:'.$this->accountId.':'.$this->view->value.':v1';
    }
}
