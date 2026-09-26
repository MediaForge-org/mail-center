<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

it('requires authentication and handles an empty mailbox', function () {
    $this->getJson('/api/messages')->assertUnauthorized();
    $this->actingAs(User::factory()->create())->getJson('/api/messages')->assertOk()
        ->assertExactJson(['data' => [], 'next_cursor' => null]);
});

it('isolates owners, excludes local deletion and disabled or deleted accounts, and keeps remote removed mail', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $aliceAccount = makeAccount($alice);
    $bobAccount = makeAccount($bob);
    $disabled = makeAccount($alice, ['email_address' => 'disabled@example.test', 'enabled' => false]);
    $deletedAccount = makeAccount($alice, ['email_address' => 'deleted@example.test']);
    $deletedAccount->delete();

    $visible = listMessage($alice, $aliceAccount->id, 'Visible', '2026-09-26 12:00:00+00', ['remote_status' => 'removed']);
    listMessage($alice, $aliceAccount->id, 'Locally deleted', '2026-09-26 13:00:00+00', ['deleted_at' => now()]);
    listMessage($alice, $disabled->id, 'Disabled', '2026-09-26 14:00:00+00');
    listMessage($alice, $deletedAccount->id, 'Deleted account', '2026-09-26 15:00:00+00');
    listMessage($bob, $bobAccount->id, 'Bob secret', '2026-09-26 16:00:00+00');

    $response = $this->actingAs($alice)->getJson('/api/messages')->assertOk();
    expect(array_column($response->json('data'), 'id'))->toBe([$visible]);
    $this->getJson('/api/messages?user_id='.$bob->id.'&mail_account_id='.$bobAccount->id)
        ->assertUnprocessable()->assertJsonValidationErrors('query');
});

it('orders newest first with an id tie breaker and traverses pages without duplicates', function () {
    $user = User::factory()->create();
    $account = makeAccount($user);
    $old = listMessage($user, $account->id, 'Old', '2026-09-24 12:00:00+00');
    $tieLow = listMessage($user, $account->id, 'Tie low', '2026-09-25 12:00:00+00');
    $tieHigh = listMessage($user, $account->id, 'Tie high', '2026-09-25 12:00:00+00');
    $new = listMessage($user, $account->id, 'New', '2026-09-26 12:00:00+00');

    $this->actingAs($user);
    $first = $this->getJson('/api/messages?limit=2')->assertOk();
    expect(array_column($first->json('data'), 'id'))->toBe([$new, $tieHigh]);
    $second = $this->getJson('/api/messages?limit=2&cursor='.urlencode($first->json('next_cursor')))->assertOk();
    expect(array_column($second->json('data'), 'id'))->toBe([$tieLow, $old]);
    expect($second->json('next_cursor'))->toBeNull();
    expect(array_unique(array_merge(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id'))))->toHaveCount(4);
});

it('rejects invalid page sizes and malformed or cross-user cursors', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $account = makeAccount($alice);
    listMessage($alice, $account->id, 'One', '2026-09-26 12:00:00+00');
    listMessage($alice, $account->id, 'Two', '2026-09-25 12:00:00+00');

    $this->actingAs($alice);
    foreach (['0', '101', 'abc', '1.5'] as $limit) {
        $this->getJson('/api/messages?limit='.$limit)->assertUnprocessable()->assertJsonValidationErrors('limit');
    }
    $this->getJson('/api/messages?cursor=broken')->assertUnprocessable()->assertJsonValidationErrors('cursor');
    $this->getJson('/api/messages?cursor='.str_repeat('a', 2049))->assertUnprocessable()->assertJsonValidationErrors('cursor');
    $cursor = $this->getJson('/api/messages?limit=1')->json('next_cursor');
    $this->actingAs($bob)->getJson('/api/messages?cursor='.urlencode($cursor))->assertUnprocessable()->assertJsonValidationErrors('cursor');
    $forged = Crypt::encryptString(json_encode(['v' => 1, 'user_id' => $bob->id, 'filter' => 'all-mail:v1', 'sort_date' => 'invalid', 'id' => 1]));
    $this->getJson('/api/messages?cursor='.urlencode($forged))->assertUnprocessable()->assertJsonValidationErrors('cursor');
    $invalidDate = Crypt::encryptString(json_encode(['v' => 1, 'user_id' => $bob->id, 'filter' => 'all-mail:v1', 'sort_date' => '2026-02-30 12:00:00.000000+00:00', 'id' => 1]));
    $this->getJson('/api/messages?cursor='.urlencode($invalidDate))->assertUnprocessable()->assertJsonValidationErrors('cursor');
});

it('returns only the list field allowlist', function () {
    $user = User::factory()->create();
    $account = makeAccount($user);
    listMessage($user, $account->id, 'Subject', '2026-09-26 12:00:00+00', [
        'message_id_header' => 'private-header', 'snippet' => 'Safe preview',
    ]);

    $item = $this->actingAs($user)->getJson('/api/messages')->assertOk()->json('data.0');
    expect(array_keys($item))->toEqualCanonicalizing([
        'id', 'mail_account_id', 'subject', 'from_name', 'from_address', 'to', 'snippet',
        'sort_date', 'received_at', 'is_read', 'is_starred', 'is_important', 'is_done',
        'has_attachments', 'direction',
    ]);
    expect($item['to'])->toBe([['name' => 'Recipient', 'address' => 'recipient@example.test']]);
    expect(json_encode($item))->not->toContain('private-header')->not->toContain('raw_blob_sha256')->not->toContain('dedupe_key');
});
