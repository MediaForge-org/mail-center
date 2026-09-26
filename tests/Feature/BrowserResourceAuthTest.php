<?php

use App\Messages\EmailHtml;
use App\Models\User;
use App\Storage\BlobStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('uses the web session boundary for every browser resource and revokes access on logout', function () {
    $root = sys_get_temp_dir().'/mailcenter-session-test-'.bin2hex(random_bytes(6));
    config(['mailcenter.blobs.root' => $root]);
    try {
        $user = User::factory()->create();
        $id = listMessage($user, makeAccount($user)->id, 'Session', '2026-01-01');
        DB::table('message_bodies')->insert(['message_id' => $id, ...(new EmailHtml)->sanitize('<img src="cid:photo">')]);
        $bytes = file_get_contents(__DIR__.'/../Fixtures/inline/pixel.png');
        $attachment = DB::table('attachments')->insertGetId([
            'message_id' => $id, 'blob_sha256' => app(BlobStore::class)->put($bytes), 'filename' => 'photo.png',
            'content_type' => 'image/png', 'size_bytes' => strlen($bytes), 'disposition' => 'inline',
            'content_id' => 'photo', 'mime_part_path' => '1.2', 'part_order' => 0, 'created_at' => now(),
        ]);
        $urls = ["/api/messages/$id/render", "/api/messages/$id/attachments/$attachment", "/api/messages/$id/inline/$attachment"];
        foreach ($urls as $url) {
            $route = Route::getRoutes()->match(Request::create($url));
            expect($route->gatherMiddleware())->toContain('web', 'auth:web')->not->toContain('auth:sanctum');
            $this->get($url)->assertUnauthorized();
        }
        $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        // Real-browser no-referrer coverage is in session-resource-smoke.py; no actingAs here.
        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }
        $this->postJson('/logout')->assertNoContent();
        auth()->forgetGuards();
        foreach ($urls as $url) {
            $this->get($url)->assertUnauthorized();
        }
    } finally {
        File::deleteDirectory($root);
    }
});
