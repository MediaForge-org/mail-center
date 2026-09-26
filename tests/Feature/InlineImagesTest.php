<?php

use App\Ingestion\AttachmentExtractor;
use App\Ingestion\MessageIngestor;
use App\Jobs\ExtractMessageAttachments;
use App\Jobs\SanitizeMessageHtml;
use App\Messages\EmailHtml;
use App\Messages\InlineImages;
use App\Models\User;
use App\Storage\BlobStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZBateson\MailMimeParser\Message;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->blobRoot = sys_get_temp_dir().'/mailcenter-inline-'.bin2hex(random_bytes(6));
    config(['mailcenter.blobs.root' => $this->blobRoot]);
    $this->user = User::factory()->create();
    $this->account = makeAccount($this->user);
    $this->id = listMessage($this->user, $this->account->id, 'Inline', '2026-01-01');
    DB::table('message_bodies')->insert(['message_id' => $this->id, 'text_plain' => 'Fallback', ...(new EmailHtml)->sanitize('<p>Photo</p><img src="CID:photo%40example.test">')]);
});
afterEach(function () {
    File::deleteDirectory($this->blobRoot);
});

function inlinePart(int $messageId, string $extension = 'png', array $overrides = []): int
{
    $bytes = file_get_contents(__DIR__.'/../Fixtures/inline/pixel.'.$extension);

    return DB::table('attachments')->insertGetId(array_merge([
        'message_id' => $messageId, 'blob_sha256' => app(BlobStore::class)->put($bytes),
        'filename' => 'pixel.'.$extension, 'content_type' => 'image/'.($extension === 'jpg' ? 'jpeg' : $extension),
        'size_bytes' => strlen($bytes), 'disposition' => 'inline', 'content_id' => '<photo@example.test>',
        'mime_part_path' => '1.'.random_int(1, 1000000), 'part_order' => 0, 'created_at' => now(),
    ], $overrides));
}

it('resolves and serves only validated same-message raster bytes with unchanged CSP', function (string $extension) {
    $attachment = inlinePart($this->id, $extension);
    $url = "/api/messages/{$this->id}/inline/$attachment";
    $render = $this->actingAs($this->user)->get("/api/messages/{$this->id}/render")->assertOk()
        ->assertSee('src="'.$url.'"', false)->assertHeader('Content-Security-Policy', EmailHtml::CSP);
    expect($render->getContent())->not->toContain('data-mc-resource', 'cid:', $this->blobRoot, 'blob_sha256');
    $stored = DB::table('message_bodies')->value('html_sanitized');
    expect($stored)->toContain('data-mc-resource=')->not->toContain('/api/', 'src=', 'photo@example.test');
    $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/'.($extension === 'jpg' ? 'jpeg' : $extension))
        ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Referrer-Policy', 'no-referrer');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    expect($response->headers->get('Content-Disposition'))->toBeNull();
    expect($response->baseResponse)->toBeInstanceOf(BinaryFileResponse::class);
    ob_start();
    $response->baseResponse->sendContent();
    $bytes = ob_get_clean();
    expect($bytes)->toBe(file_get_contents(__DIR__.'/../Fixtures/inline/pixel.'.$extension));
    $response->assertHeader('Content-Length', (string) strlen($bytes));
})->with(['png', 'jpg', 'gif', 'webp']);

it('normalizes brackets and URI escapes once while preserving opaque case-sensitive identity', function () {
    expect(InlineImages::identity(' <Photo@example.test> '))->toBe(InlineImages::identity('CiD:%3CPhoto%40example.test%3E', true));
    expect(InlineImages::identity('Photo@example.test'))->not->toBe(InlineImages::identity('photo@example.test'));
    foreach (["cid:a\r\nb", 'cid:', 'cid:../../file', 'cid:%2Fapi%2Fmessages%2F1', 'cid:https://evil.test/x', 'cid:%252e%252e%252f', 'cid:x?y', 'cid:x#y', 'cid:x%00y'] as $bad) {
        expect(InlineImages::identity($bad, true))->toBeNull();
    }
});

it('blocks missing, foreign-message and ambiguous IDs including a non-inline duplicate', function () {
    $other = listMessage($this->user, $this->account->id, 'Other', '2026-01-01');
    inlinePart($other);
    $this->actingAs($this->user)->get("/api/messages/{$this->id}/render")->assertOk()->assertDontSee('src=', false);
    $attachment = inlinePart($this->id);
    inlinePart($this->id, overrides: ['disposition' => 'attachment', 'content_id' => 'photo@example.test']);
    $this->get("/api/messages/{$this->id}/render")->assertOk()->assertDontSee('src=', false);
    $this->get("/api/messages/{$this->id}/inline/$attachment")->assertNotFound();
});

it('enforces authentication, ownership, exact message membership and deletion semantics', function () {
    $attachment = inlinePart($this->id);
    $url = "/api/messages/{$this->id}/inline/$attachment";
    $this->get($url)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
    $other = listMessage($this->user, $this->account->id, 'Other', '2026-01-01');
    $this->actingAs($this->user)->get("/api/messages/$other/inline/$attachment")->assertNotFound();
    $this->get("/api/messages/{$this->id}/inline/999999999")->assertNotFound();
    DB::table('messages')->where('id', $this->id)->update(['deleted_at' => now()]);
    $this->get($url)->assertNotFound();
    DB::table('messages')->where('id', $this->id)->update(['deleted_at' => null]);
    $this->account->update(['enabled' => false]);
    $this->get($url)->assertOk();
    $this->account->delete();
    $this->get($url)->assertNotFound();
});

it('refuses active types, mismatched types, non-inline and CID-less parts', function (array $overrides) {
    $attachment = inlinePart($this->id, overrides: $overrides);
    $this->actingAs($this->user)->get("/api/messages/{$this->id}/inline/$attachment")->assertNotFound();
    $this->get("/api/messages/{$this->id}/render")->assertOk()->assertDontSee('src=', false);
})->with([
    [['content_type' => 'image/svg+xml']], [['content_type' => 'text/html']], [['content_type' => 'application/xml']],
    [['content_type' => 'application/pdf']], [['content_type' => 'application/octet-stream']], [['content_type' => 'image/jpeg']],
    [['disposition' => 'attachment']], [['content_id' => null]], [['size_bytes' => 0]], [['size_bytes' => InlineImages::MAX_BYTES + 1]],
]);

it('refuses spoofed bytes, oversized dimensions and corrupt or missing blobs', function () {
    $attachment = inlinePart($this->id);
    $url = "/api/messages/{$this->id}/inline/$attachment";
    $this->actingAs($this->user);
    foreach (['<svg xmlns="http://www.w3.org/2000/svg"/>', '<html>active</html>', 'not an image', ''] as $bytes) {
        DB::table('attachments')->where('id', $attachment)->update(['blob_sha256' => app(BlobStore::class)->put($bytes), 'size_bytes' => strlen($bytes)]);
        $this->get($url)->assertNotFound();
    }
    $bytes = file_get_contents(__DIR__.'/../Fixtures/inline/pixel.png');
    $bytes = substr_replace($bytes, pack('NN', 9000, 9000), 16, 8);
    DB::table('attachments')->where('id', $attachment)->update(['blob_sha256' => app(BlobStore::class)->put($bytes), 'size_bytes' => strlen($bytes)]);
    $this->get($url)->assertNotFound();
    $sha = app(BlobStore::class)->put(file_get_contents(__DIR__.'/../Fixtures/inline/pixel.png'));
    DB::table('attachments')->where('id', $attachment)->update(['blob_sha256' => $sha, 'size_bytes' => filesize(__DIR__.'/../Fixtures/inline/pixel.png')]);
    $path = app(BlobStore::class)->verifiedPath($sha);
    file_put_contents($path, 'corrupt');
    $this->get($url)->assertNotFound();
    unlink($path);
    $this->get($url)->assertNotFound();
});

it('cannot promote sender paths, URLs or forged internal markers into image routes', function () {
    inlinePart($this->id);
    $marker = InlineImages::identity('photo@example.test');
    $html = '<img data-mc-resource="'.$marker.'" src="https://attacker.test/pixel">'
        .'<img src="http://attacker.test/pixel"><img src="//attacker.test/pixel">'
        .'<img src="/api/messages/1/inline/1"><img src="cid:../../etc/passwd"><img src="cid:%2fapi%2fmessages%2f1">'
        .'<img src="data:image/png;base64,evil"><img srcset="https://attacker.test/a 1x" data-mc-resource="'.$marker.'">';
    $safe = (new EmailHtml)->sanitize($html);
    expect($safe['remote_content_count'])->toBe(3);
    DB::table('message_bodies')->where('message_id', $this->id)->update($safe);
    $response = $this->actingAs($this->user)->get("/api/messages/{$this->id}/render")->assertOk();
    expect($response->getContent())->not->toContain('src=', 'srcset=', 'attacker.test', '/etc/passwd', 'data-mc-resource');
});

it('ingests inline parts and reprocesses old HTML safely through existing operator jobs', function () {
    $png = base64_encode(file_get_contents(__DIR__.'/../Fixtures/inline/pixel.png'));
    $raw = "From: sender@example.test\r\nSubject: CID\r\nMIME-Version: 1.0\r\nContent-Type: multipart/related; boundary=parts\r\n\r\n"
        ."--parts\r\nContent-Type: text/html\r\n\r\n<p>Picture</p><img src=\"cid:photo%40example.test\"><img src=\"https://attacker.test/pixel\">\r\n"
        ."--parts\r\nContent-Type: image/png\r\nContent-ID: <photo@example.test>\r\nContent-Transfer-Encoding: base64\r\n\r\n$png\r\n--parts--\r\n";
    $folder = $this->account->remoteFolders()->create(['raw_name' => 'INBOX', 'name' => 'INBOX', 'role' => 'inbox']);
    $id = app(MessageIngestor::class)->ingest($this->account, $folder, 1, 100, $raw, [], null);
    $attachment = DB::table('attachments')->where('message_id', $id)->first();
    expect($attachment->disposition)->toBe('inline');
    $this->actingAs($this->user)->get("/api/messages/$id/render")->assertOk()->assertSee("/api/messages/$id/inline/$attachment->id", false)->assertDontSee('attacker.test');
    DB::table('message_bodies')->where('message_id', $id)->update(['sanitizer_version' => 1]);
    $this->get("/api/messages/$id/render")->assertNotFound();
    $this->getJson("/api/messages/$id")->assertJsonPath('data.html_available', false);
    Queue::fake();
    $this->artisan('messages:sanitize-html')->assertSuccessful();
    Queue::assertPushed(SanitizeMessageHtml::class, fn ($job) => $job->messageId === $id);
    (new SanitizeMessageHtml($id))->handle(new EmailHtml);
    $this->get("/api/messages/$id/render")->assertOk()->assertSee("/api/messages/$id/inline/$attachment->id", false);
    // Extraction can finish later without re-sanitizing or changing the neutral references.
    DB::table('attachments')->where('message_id', $id)->delete();
    DB::table('messages')->where('id', $id)->update(['attachments_extracted_at' => null]);
    $this->get("/api/messages/$id/render")->assertOk()->assertDontSee('src=', false);
    $this->artisan('messages:extract-attachments')->assertSuccessful();
    Queue::assertPushed(ExtractMessageAttachments::class, fn ($job) => $job->messageId === $id);
    (new ExtractMessageAttachments($id))->handle(app(BlobStore::class), app(AttachmentExtractor::class));
    $this->get("/api/messages/$id/render")->assertOk()->assertSee('/inline/', false);
});

it('blocks duplicate Content-IDs on MIME body parts that are not attachment rows', function () {
    $png = base64_encode(file_get_contents(__DIR__.'/../Fixtures/inline/pixel.png'));
    $raw = "MIME-Version: 1.0\r\nContent-Type: multipart/related; boundary=parts\r\n\r\n"
        ."--parts\r\nContent-Type: text/html\r\nContent-ID: <photo@example.test>\r\nContent-Disposition: inline\r\n\r\n<img src=\"cid:photo@example.test\">\r\n"
        ."--parts\r\nContent-Type: image/png\r\nContent-ID: <photo@example.test>\r\nContent-Transfer-Encoding: base64\r\n\r\n$png\r\n--parts--\r\n";
    $safe = (new EmailHtml)->sanitizeMessage(Message::from($raw, true));
    expect($safe['html_sanitized'])->not->toContain('data-mc-resource', 'src=');
});

it('does not serve unreferenced or stale-version inline resources directly', function () {
    $attachment = inlinePart($this->id);
    $url = "/api/messages/{$this->id}/inline/$attachment";
    $this->actingAs($this->user);
    DB::table('message_bodies')->where('message_id', $this->id)->update(['sanitizer_version' => 1]);
    $this->get($url)->assertNotFound();
    DB::table('message_bodies')->where('message_id', $this->id)->update((new EmailHtml)->sanitize('<p>No image references</p>'));
    $this->get($url)->assertNotFound();
});
