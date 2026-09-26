<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $user_id
 * @property array{host?: string, port?: int, security?: string, username?: string} $incoming
 * @property array<int, string> $aliases
 * @property array<string, mixed> $sync_state
 * @property bool $write_back_seen
 * @property int $seen_mirror_generation
 * @property string|null $seen_writeback_error
 * @property bool $enabled
 * @property bool $sync_enabled
 * @property int $sync_interval_seconds
 * @property string $sync_status
 * @property int $consecutive_failures
 * @property CarbonInterface|null $next_sync_at
 * @property CarbonInterface|null $last_sync_started_at
 * @property CarbonInterface|null $last_sync_finished_at
 * @property CarbonInterface|null $last_successful_sync_at
 * @property CarbonInterface|null $last_error_at
 * @property string|null $last_error_code
 * @property string|null $last_error_message
 */
class MailAccount extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'incoming' => 'array', 'aliases' => 'array', 'capabilities' => 'array',
            'write_back_seen' => 'boolean', 'seen_mirror_generation' => 'integer',
            'sync_state' => 'array', 'enabled' => 'boolean', 'sync_enabled' => 'boolean',
            'next_sync_at' => 'datetime', 'last_sync_started_at' => 'datetime',
            'last_sync_finished_at' => 'datetime', 'last_successful_sync_at' => 'datetime',
            'last_error_at' => 'datetime', 'deleted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<MailAccountCredential, $this> */
    public function credentials(): HasMany
    {
        return $this->hasMany(MailAccountCredential::class);
    }

    /** @return HasMany<RemoteFolder, $this> */
    public function remoteFolders(): HasMany
    {
        return $this->hasMany(RemoteFolder::class);
    }
}
