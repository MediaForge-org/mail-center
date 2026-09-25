<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $mail_account_id
 * @property string $raw_name
 * @property string $name
 * @property string $role
 * @property bool $selectable
 * @property bool $sync_enabled
 * @property int|null $uidvalidity
 * @property int $sync_high_uid
 * @property int|null $backfill_low_uid
 * @property CarbonInterface|null $backfill_completed_at
 * @property CarbonInterface|null $last_flag_scan_at
 * @property CarbonInterface|null $last_synced_at
 */
class RemoteFolder extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attributes' => 'array', 'selectable' => 'boolean', 'sync_enabled' => 'boolean',
            'backfill_completed_at' => 'datetime', 'last_flag_scan_at' => 'datetime',
            'last_reconciled_at' => 'datetime', 'last_synced_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }
}
