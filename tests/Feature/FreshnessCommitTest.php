<?php

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

it('never loses a later commit from a transaction that started before an already observed commit', function () {
    $user = User::factory()->create();
    $a = makeAccount($user);
    $b = makeAccount($user);
    $config = config('database.connections.pgsql');
    $other = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password']);
    DB::beginTransaction(); // Older transaction, deliberately committing second.
    $other->beginTransaction();
    $other->exec("UPDATE mail_accounts SET enabled = false WHERE id = {$b->id}");
    $other->commit();
    $observed = (int) $other->query("SELECT version FROM user_change_versions WHERE user_id = {$user->id}")->fetchColumn();
    DB::table('mail_accounts')->where('id', $a->id)->update(['enabled' => false]);
    expect((int) $other->query("SELECT version FROM user_change_versions WHERE user_id = {$user->id}")->fetchColumn())->toBe($observed);
    DB::commit();
    $this->actingAs($user)->getJson("/api/changes?since={$observed}")->assertOk()->assertJsonPath('invalidate', true);
    expect((int) $other->query("SELECT version FROM user_change_versions WHERE user_id = {$user->id}")->fetchColumn())->toBeGreaterThan($observed);
});
