<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('requires authentication for message detail', function () {
    $this->getJson('/api/messages/1')->assertUnauthorized();
});

it('returns only reader metadata and stored plain text without mutating flags', function () {
    $user = User::factory()->create();
    $account = makeAccount($user, ['sync_enabled' => false]);
    $id = listMessage($user, $account->id, 'Subject', '2026-01-01', [
        'parse_status' => 'ok', 'remote_status' => 'removed',
        'cc' => json_encode([['name' => 'Copy', 'address' => 'cc@example.test']]),
    ]);
    DB::table('message_bodies')->insert([
        'message_id' => $id, 'text_plain' => "Hello\n<script>literal text</script>",
        'html_sanitized' => '<b>HTML secret</b>',
    ]);
    $response = $this->actingAs($user)->getJson("/api/messages/$id")->assertOk()
        ->assertJsonPath('data.subject', 'Subject')
        ->assertJsonPath('data.from_address', 'sender@example.test')
        ->assertJsonPath('data.to.0.address', 'recipient@example.test')
        ->assertJsonPath('data.cc.0.address', 'cc@example.test')
        ->assertJsonPath('data.remote_status', 'removed')
        ->assertJsonPath('data.body_status', 'available')
        ->assertJsonPath('data.text_plain', "Hello\n<script>literal text</script>");
    expect(array_keys($response->json('data')))->toEqual([
        'id', 'mail_account_id', 'subject', 'from_name', 'from_address', 'direction',
        'is_read', 'is_starred', 'is_important', 'is_done', 'has_attachments', 'remote_status',
        'to', 'cc', 'bcc', 'reply_to', 'date_header', 'received_at', 'body_status', 'text_plain', 'html_available', 'remote_content_count', 'read_writeback', 'attachments',
    ]);
    expect($response->getContent())->not->toContain('HTML secret');
    $this->assertDatabaseHas('messages', ['id' => $id, 'is_read' => false]);
});

it('rejects foreign, deleted and nonexistent messages', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $foreign = listMessage($other, makeAccount($other)->id, 'Foreign', '2026-01-01');
    $account = makeAccount($user);
    $id = listMessage($user, $account->id, 'Local', '2026-01-01');
    $this->actingAs($user)->getJson("/api/messages/$foreign")->assertNotFound();
    $this->getJson('/api/messages/9999999')->assertNotFound();
    DB::table('messages')->where('id', $id)->update(['deleted_at' => now()]);
    $this->getJson("/api/messages/$id")->assertNotFound();
    DB::table('messages')->where('id', $id)->update(['deleted_at' => null]);
    $account->update(['enabled' => false]);
    $this->getJson("/api/messages/$id")->assertOk();
    $account->update(['enabled' => true, 'deleted_at' => now()]);
    $this->getJson("/api/messages/$id")->assertNotFound();
});

it('explicitly reports missing, empty or failed plain text without using HTML', function (string $kind) {
    $user = User::factory()->create();
    $id = listMessage($user, makeAccount($user)->id, 'Missing', '2026-01-01', [
        'parse_status' => $kind === 'failed' ? 'failed' : 'ok',
    ]);
    if ($kind !== 'missing') {
        DB::table('message_bodies')->insert([
            'message_id' => $id, 'text_plain' => $kind === 'failed' ? 'Unusable' : '',
            'html_sanitized' => '<b>Not returned</b>',
        ]);
    }
    $this->actingAs($user)->getJson("/api/messages/$id")->assertOk()
        ->assertJsonPath('data.body_status', 'unavailable')
        ->assertJsonPath('data.text_plain', null)
        ->assertJsonMissingPath('data.html_sanitized');
})->with(['missing', 'empty', 'failed']);
