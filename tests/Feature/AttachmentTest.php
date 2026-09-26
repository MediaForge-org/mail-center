<?php

use App\Ingestion\AttachmentExtractor;
use App\Ingestion\MessageIngestor;
use App\Jobs\ExtractMessageAttachments;
use App\Models\User;
use App\Storage\BlobStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->blobRoot = sys_get_temp_dir().'/mailcenter-attachments-'.bin2hex(random_bytes(6));
    config(['mailcenter.blobs.root' => $this->blobRoot]);
    $this->user = User::factory()->create();
    $this->account = makeAccount($this->user);
});
afterEach(function () {
    File::deleteDirectory($this->blobRoot);
});

function attachmentRaw(): string
{
    return "From: sender@example.test\r\nSubject: Files\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=files\r\n\r\n"
        ."--files\r\nContent-Type: text/plain\r\n\r\nHello\r\n"
        ."--files\r\nContent-Type: text/plain; charset=iso-8859-1\r\nContent-Disposition: attachment; filename*=UTF-8''r%C3%A9sum%C3%A9.txt\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode("original\xE9\x00bytes")."\r\n"
        ."--files\r\nContent-Type: image/png\r\nContent-Disposition: inline; filename=picture.png\r\nContent-ID: <picture>\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('image bytes')."\r\n"
        ."--files\r\nContent-Type: application/octet-stream\r\nContent-Disposition: attachment\r\n\r\n\r\n--files--\r\n";
}

function storedAttachment(User $user, int $accountId, string $bytes, string $filename = 'report.txt', string $type = 'text/plain'): array
{
    $id = listMessage($user, $accountId, 'Attachment', '2026-01-01');
    $sha = app(BlobStore::class)->put($bytes);
    $attachment = DB::table('attachments')->insertGetId([
        'message_id' => $id, 'blob_sha256' => $sha, 'filename' => $filename,
        'content_type' => $type, 'size_bytes' => strlen($bytes), 'disposition' => 'attachment',
        'mime_part_path' => '1.2', 'part_order' => 0, 'created_at' => now(),
    ]);

    return [$id, $attachment, $sha];
}

it('extracts decoded original bytes, inline identity, Unicode names and empty files during ingestion', function () {
    $folder = $this->account->remoteFolders()->create(['raw_name' => 'INBOX', 'name' => 'INBOX', 'role' => 'inbox']);
    $id = app(MessageIngestor::class)->ingest($this->account, $folder, 1, 100, attachmentRaw(), [], null);
    $rows = DB::table('attachments')->where('message_id', $id)->orderBy('part_order')->get();
    expect($rows)->toHaveCount(3);
    expect($rows[0]->filename)->toBe('résumé.txt');
    expect(file_get_contents(app(BlobStore::class)->verifiedPath($rows[0]->blob_sha256)))->toBe("original\xE9\x00bytes");
    expect($rows[1]->disposition)->toBe('inline')->and($rows[1]->content_id)->toBe('picture');
    expect($rows[2]->filename)->toBe('attachment')->and($rows[2]->size_bytes)->toBe(0);
    expect($rows->pluck('mime_part_path')->all())->toBe(['1.2', '1.3', '1.4']);
    app(AttachmentExtractor::class)->extract($id, attachmentRaw());
    expect(DB::table('attachments')->count())->toBe(3);
    $this->assertDatabaseHas('messages', ['id' => $id, 'has_attachments' => true]);
});

it('lists only safe fields and streams exact bytes with safe Unicode download headers', function () {
    $bytes = str_repeat("bytes\0", 400000);
    [$id, $attachment, $sha] = storedAttachment($this->user, $this->account->id, $bytes, 'résumé.txt');
    $this->actingAs($this->user)->getJson("/api/messages/$id")->assertOk()
        ->assertJsonPath('data.attachments.0.filename', 'résumé.txt')
        ->assertJsonPath('data.attachments.0.size_bytes', strlen($bytes))
        ->assertJsonCount(1, 'data.attachments');
    $metadata = $this->getJson("/api/messages/$id")->json('data.attachments.0');
    expect(array_keys($metadata))->toBe(['id', 'filename', 'content_type', 'size_bytes', 'inline', 'downloadable']);
    expect(json_encode($metadata))->not->toContain($sha, 'blobs/', 'disk');
    $response = $this->get("/api/messages/$id/attachments/$attachment")->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Content-Length', (string) strlen($bytes));
    expect($response->baseResponse)->toBeInstanceOf(BinaryFileResponse::class);
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    expect($response->headers->get('Content-Disposition'))->toContain('attachment;', "filename*=utf-8''r%C3%A9sum%C3%A9.txt");
    ob_start();
    $response->baseResponse->sendContent();
    $download = ob_get_clean();
    expect($download)->toBe($bytes);
});

it('requires authentication and rejects foreign, substituted and inaccessible attachment access', function () {
    [$id, $attachment] = storedAttachment($this->user, $this->account->id, 'data');
    $url = "/api/messages/$id/attachments/$attachment";
    $this->get($url)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
    $otherMessage = listMessage($this->user, $this->account->id, 'Other', '2026-01-01');
    $this->actingAs($this->user)->get("/api/messages/$otherMessage/attachments/$attachment")->assertNotFound();
    $this->get("/api/messages/$id/attachments/999999")->assertNotFound();
    DB::table('messages')->where('id', $id)->update(['deleted_at' => now()]);
    $this->get($url)->assertNotFound();
    DB::table('messages')->where('id', $id)->update(['deleted_at' => null]);
    $this->account->update(['enabled' => false]);
    $this->get($url)->assertNotFound();
    $this->account->update(['enabled' => true, 'deleted_at' => now()]);
    $this->get($url)->assertNotFound();
});

it('neutralizes filenames and falls back for unknown or active MIME types', function (string $type) {
    [$id, $attachment] = storedAttachment($this->user, $this->account->id, '', "../unsafe\r\nX-Evil: yes\\name.txt", $type);
    $response = $this->actingAs($this->user)->get("/api/messages/$id/attachments/$attachment")->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream')
        ->assertHeader('Content-Length', '0');
    expect($response->headers->get('Content-Disposition'))->not->toContain("\r", "\n", '../', '\\');
    expect($response->headers->has('X-Evil'))->toBeFalse();
})->with(['unknown/thing', 'text/html', 'image/svg+xml', 'application/xml', 'application/javascript']);

it('queues existing messages without request-time parsing and deduplicates extracted blobs', function () {
    $raw = attachmentRaw();
    $sha = app(BlobStore::class)->put($raw);
    $id = listMessage($this->user, $this->account->id, 'Existing', '2026-01-01', ['raw_blob_sha256' => $sha]);
    $this->actingAs($this->user)->getJson("/api/messages/$id")->assertJsonPath('data.attachments', []);
    Queue::fake();
    $this->artisan('messages:extract-attachments')->assertSuccessful();
    Queue::assertPushed(ExtractMessageAttachments::class, fn ($job) => $job->messageId === $id);
    $job = new ExtractMessageAttachments($id);
    $job->handle(app(BlobStore::class), app(AttachmentExtractor::class));
    $before = DB::table('blobs')->count();
    $job->handle(app(BlobStore::class), app(AttachmentExtractor::class));
    expect(DB::table('attachments')->count())->toBe(3);
    expect(DB::table('blobs')->count())->toBe($before);
    expect(DB::table('messages')->count())->toBe(1);
});

it('returns 404 for a missing or corrupt stored file without leaking its path', function () {
    [$id, $attachment, $sha] = storedAttachment($this->user, $this->account->id, 'bytes');
    file_put_contents(app(BlobStore::class)->verifiedPath($sha), 'corrupt');
    $this->actingAs($this->user)->get("/api/messages/$id/attachments/$attachment")->assertNotFound();
});
