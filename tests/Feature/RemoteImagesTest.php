<?php

use App\Messages\EmailHtml;
use App\Messages\RemoteImages\Consent;
use App\Messages\RemoteImages\Fetcher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = makeAccount($this->user);
    $this->id = listMessage($this->user, $this->account->id, 'Remote', '2026-01-01');
    DB::table('messages')->where('id', $this->id)->update(['from_address' => 'Sender@Example.test']);
    $this->url = 'https://images.example.test/pixel?secret=tracking';
    $this->key = hash('sha256', $this->url);
    DB::table('message_bodies')->insert(['message_id' => $this->id, 'text_plain' => 'Fallback', ...(new EmailHtml)->sanitize('<p>Safe</p><img src="'.$this->url.'">')]);
});

function remoteToken($test, ?string $grant): string
{
    return app(Consent::class)->token($test->user->id, $test->id, $test->key, $grant);
}

it('stores neutral markers and private mappings without fetching or emitting remote URLs', function () {
    $this->mock(Fetcher::class)->shouldNotReceive('fetch');
    $body = DB::table('message_bodies')->where('message_id', $this->id)->first();
    expect($body->sanitizer_version)->toBe(EmailHtml::VERSION);
    expect($body->html_sanitized)->toContain('data-mc-remote="'.$this->key.'"')->not->toContain('secret', 'https:', '/api/');
    expect(json_decode($body->remote_resources, true))->toBe([$this->key => $this->url]);
    $this->actingAs($this->user)->get("/api/messages/{$this->id}/render")->assertOk()->assertDontSee('src=', false)->assertDontSee('data-mc-remote', false);
    $this->getJson("/api/messages/{$this->id}")->assertJsonPath('data.remote_images_always', false)->assertJsonMissingPath('data.remote_resources');
    $this->get("/api/messages/{$this->id}/render?images=allowed")->assertForbidden();
    $this->get('/api/image-proxy?token='.urlencode(remoteToken($this, null)))->assertNotFound();
    $this->get('/api/image-proxy?url='.urlencode($this->url))->assertNotFound();
});

it('uses session-bound opening grants, rechecks ownership and revokes immediately', function () {
    $this->actingAs($this->user);
    $grant = $this->postJson("/api/messages/{$this->id}/remote-images", ['mode' => 'once'])->assertOk()->json('grant');
    expect(DB::table('remote_content_allowlist')->count())->toBe(0);
    $render = $this->get("/api/messages/{$this->id}/render?images=allowed&grant=$grant")->assertOk()->assertHeader('Content-Security-Policy', EmailHtml::CSP);
    expect($render->getContent())->toContain('/api/image-proxy?token=')->not->toContain('images.example.test', 'tracking', 'data-mc-remote');
    $bytes = file_get_contents(__DIR__.'/../Fixtures/inline/pixel.png');
    $this->mock(Fetcher::class)->shouldReceive('fetch')->once()->with($this->url)->andReturn(['bytes' => $bytes, 'mime' => 'image/png']);
    $url = '/api/image-proxy?token='.urlencode(remoteToken($this, $grant));
    $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Content-Length', (string) strlen($bytes))
        ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Referrer-Policy', 'no-referrer');
    expect($response->getContent())->toBe($bytes);
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    foreach (['Set-Cookie', 'Location', 'Content-Disposition'] as $header) {
        // Session middleware may refresh its own cookie; upstream headers are never returned.
        if ($header !== 'Set-Cookie') {
            expect($response->headers->has($header))->toBeFalse();
        }
    }
    $other = listMessage($this->user, $this->account->id, 'Other', '2026-01-01');
    DB::table('message_bodies')->insert(['message_id' => $other, ...(new EmailHtml)->sanitize('<img src="'.$this->url.'">')]);
    $wrong = app(Consent::class)->token($this->user->id, $other, $this->key, $grant);
    $this->get('/api/image-proxy?token='.urlencode($wrong))->assertNotFound();
    DB::table('messages')->where('id', $this->id)->update(['deleted_at' => now()]);
    $this->get($url)->assertNotFound();
    DB::table('messages')->where('id', $this->id)->update(['deleted_at' => null]);
    $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
    $this->postJson("/api/messages/{$this->id}/remote-images", ['mode' => 'always'])->assertNotFound();
    $this->actingAs($this->user)->deleteJson('/api/remote-image-consent', ['grant' => $grant])->assertNoContent();
    $this->get($url)->assertNotFound();
});

it('normalizes exact sender preferences per user and makes revocation invalidate outstanding tokens', function () {
    $this->actingAs($this->user)->postJson("/api/messages/{$this->id}/remote-images", ['mode' => 'always'])->assertOk()->assertJsonPath('always', true);
    expect(DB::table('remote_content_allowlist')->value('address'))->toBe('sender@example.test');
    expect(app(Consent::class)->allowlisted($this->user->id, 'Other@example.test'))->toBeFalse();
    expect(app(Consent::class)->allowlisted(User::factory()->create()->id, 'sender@example.test'))->toBeFalse();
    $this->getJson("/api/messages/{$this->id}")->assertJsonPath('data.remote_images_always', true);
    // Default GET stays blocked even for an allowlisted sender; the reader explicitly selects mode.
    $this->get("/api/messages/{$this->id}/render")->assertDontSee('/api/image-proxy');
    $this->get("/api/messages/{$this->id}/render?images=allowed")->assertOk()->assertSee('/api/image-proxy');
    $token = remoteToken($this, null);
    $this->postJson("/api/messages/{$this->id}/remote-images", ['mode' => 'block'])->assertJsonPath('always', false);
    $this->get('/api/image-proxy?token='.urlencode($token))->assertNotFound();
});

it('rejects expired, modified, wrong-resource and unconsented tokens before networking', function () {
    $this->mock(Fetcher::class)->shouldNotReceive('fetch');
    $this->actingAs($this->user);
    $token = remoteToken($this, null);
    $payload = json_decode(Crypt::decryptString($token), true);
    $payload['expires'] = time() - 1;
    $expired = Crypt::encryptString(json_encode($payload));
    foreach ([$expired, $token.'x', '', 'garbage', $token] as $bad) {
        $this->get('/api/image-proxy?token='.urlencode($bad))->assertNotFound();
    }
    $this->postJson("/api/messages/{$this->id}/remote-images", ['mode' => 'always']);
    $wrong = app(Consent::class)->token($this->user->id, $this->id, str_repeat('a', 64), null);
    $this->get('/api/image-proxy?token='.urlencode($wrong))->assertNotFound();
    DB::table('message_bodies')->where('message_id', $this->id)->update(['sanitizer_version' => EmailHtml::VERSION - 1]);
    $this->get('/api/image-proxy?token='.urlencode($token))->assertNotFound();
    $this->getJson("/api/messages/{$this->id}")->assertJsonPath('data.html_available', false)->assertJsonPath('data.text_plain', 'Fallback');
});

it('fails upstream errors generically without exposing or logging the target', function () {
    $this->actingAs($this->user)->postJson("/api/messages/{$this->id}/remote-images", ['mode' => 'always']);
    $this->mock(Fetcher::class)->shouldReceive('fetch')->andThrow(new RuntimeException($this->url));
    $this->get('/api/image-proxy?token='.urlencode(remoteToken($this, null)))->assertNotFound()->assertDontSee('secret')->assertDontSee('images.example.test');
});

it('requires authentication for proxy and preference writes', function () {
    $this->get('/api/image-proxy?token=x')->assertUnauthorized();
    $this->postJson("/api/messages/{$this->id}/remote-images", ['mode' => 'once'])->assertUnauthorized();
});

it('keeps repeated resource counts accurate and rejects forged remote markers', function () {
    $result = (new EmailHtml)->sanitize('<img src="https://public.test/x"><img src="https://public.test/x"><img data-mc-remote="forged"><svg><img src="https://public.test/z"></svg>');
    // HTML5 parsing moves img out of SVG; all three surviving images remain blocked.
    expect($result['remote_content_count'])->toBe(3);
    expect(json_decode($result['remote_resources'], true))->toHaveCount(2);
    expect($result['html_sanitized'])->not->toContain('forged');
});
