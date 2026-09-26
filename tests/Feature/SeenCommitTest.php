<?php

use App\Jobs\PushRemoteFlagChangesJob;
use App\Models\User;
use App\Organization\OrganizationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(DatabaseMigrations::class);

it('dispatches only after the local intent commits and never dispatches a rollback', function () {
    Queue::fake();
    $user = User::factory()->create();
    $account = makeAccount($user, ['write_back_seen' => true, 'seen_mirror_generation' => 1]);
    $id = listMessage($user, $account->id, 'Commit', '2026-01-01');
    DB::beginTransaction();
    app(OrganizationService::class)->setRead($user->id, $id, true);
    Queue::assertNotPushed(PushRemoteFlagChangesJob::class);
    DB::commit();
    Queue::assertPushed(PushRemoteFlagChangesJob::class, fn ($job) => $job->accountId === $account->id);
    expect(DB::table('remote_flag_changes')->count())->toBe(1);
    Queue::fake();
    DB::beginTransaction();
    app(OrganizationService::class)->setRead($user->id, $id, false);
    DB::rollBack();
    Queue::assertNotPushed(PushRemoteFlagChangesJob::class);
    expect(DB::table('messages')->value('is_read'))->toBeTrue();
    expect(DB::table('remote_flag_changes')->count())->toBe(1);
});
