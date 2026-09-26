<?php

use App\Messages\EmailHtml;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('retains layout and safe links but removes hostile elements and all resource sources', function () {
    $html = '<div id="x" class="x" style="background:url(https://evil.test)"><p onclick="bad()">Text <b>bold</b></p><table><tr><td>Cell</td></tr></table>'
        .'<script>bad()</script><iframe src="https://evil.test"></iframe><object data="x"></object><embed src="x">'
        .'<form action="x"><input><button>Send</button></form><svg onload="bad()"><script>bad()</script></svg><math><mi>x</mi></math>'
        .'<meta http-equiv="refresh" content="0;url=https://evil.test"><link href="x"><base href="x"><style>p{color:red}</style>'
        .'<a href="javascript:alert(1)">bad</a><a href="data:text/html,evil">bad</a><a href="/api/accounts">relative</a>'
        .'<img src="https://evil.test/pixel" srcset="https://evil.test/2 2x" onerror="bad()">'
        .'<img src="cid:part"><img src="data:image/svg+xml,evil">'
        .'<a href="https://example.test" target="_top" ping="https://evil.test">HTTPS</a>'
        .'<a href="http://example.test">HTTP</a><a href="mailto:a@example.test">Mail</a></div>';
    $result = (new EmailHtml)->sanitize($html);
    $clean = $result['html_sanitized'];
    foreach (['<script', '<iframe', '<object', '<embed', '<form', '<input', '<button', '<svg', '<math', '<meta', '<link', '<base', '<style', 'onclick', 'onerror', 'javascript:', 'data:', 'src=', 'srcset', 'style=', 'class=', 'id=', 'ping=', 'evil.test', 'href="/'] as $bad) {
        expect($clean)->not->toContain($bad);
    }
    expect(html_entity_decode($clean))->toContain('<b>bold</b>', '<td>Cell</td>', 'href="https://example.test"', 'href="http://example.test"', 'href="mailto:a@example.test"', 'target="_blank"', 'rel="noopener noreferrer nofollow"');
    expect($result['remote_content_count'])->toBe(1);
});

it('serves only current stored HTML with sandbox headers and no request-time parsing', function () {
    $user = User::factory()->create();
    $id = listMessage($user, makeAccount($user)->id, 'HTML', '2026-01-01');
    // Helper blob is deliberately nonexistent: a successful render cannot require parsing it.
    DB::table('message_bodies')->insert(['message_id' => $id, 'text_plain' => 'Fallback', ...(new EmailHtml)->sanitize('<p>Safe</p>')]);
    $this->get("/api/messages/$id/render")->assertUnauthorized();
    $this->actingAs(User::factory()->create())->get("/api/messages/$id/render")->assertNotFound();
    $response = $this->actingAs($user)->get("/api/messages/$id/render")->assertOk()
        ->assertHeader('Content-Security-Policy', EmailHtml::CSP)
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertSee('<p>Safe</p>', false);
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    expect($response->headers->get('X-Frame-Options'))->not->toBe('DENY');
    $this->getJson("/api/messages/$id")->assertJsonPath('data.html_available', true)->assertJsonMissingPath('data.html_sanitized');
    DB::table('message_bodies')->where('message_id', $id)->update(['sanitizer_version' => 0, 'html_sanitized' => '<script>stale</script>']);
    $this->get("/api/messages/$id/render")->assertNotFound();
    $this->getJson("/api/messages/$id")->assertJsonPath('data.html_available', false)->assertJsonPath('data.text_plain', 'Fallback');
});

it('denies render access for locally deleted messages and disabled or deleted accounts', function () {
    $user = User::factory()->create();
    $account = makeAccount($user);
    $id = listMessage($user, $account->id, 'HTML', '2026-01-01');
    DB::table('message_bodies')->insert(['message_id' => $id, ...(new EmailHtml)->sanitize('<p>Safe</p>')]);
    $this->actingAs($user);
    DB::table('messages')->where('id', $id)->update(['deleted_at' => now()]);
    $this->get("/api/messages/$id/render")->assertNotFound();
    DB::table('messages')->where('id', $id)->update(['deleted_at' => null]);
    $account->update(['enabled' => false]);
    $this->get("/api/messages/$id/render")->assertNotFound();
    $account->update(['enabled' => true, 'deleted_at' => now()]);
    $this->get("/api/messages/$id/render")->assertNotFound();
});
