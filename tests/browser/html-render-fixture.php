<?php

use App\Messages\EmailHtml;
use App\Messages\InlineImages;
use App\Storage\BlobStore;

require __DIR__.'/../../vendor/autoload.php';

// Database-free fixture: production sanitizer/resolver and real raster validation.
$files = [];
$parts = [];
$bytes = [];
foreach (['png', 'jpg'] as $index => $extension) {
    $path = __DIR__.'/../Fixtures/inline/pixel.'.$extension;
    $sha = hash_file('sha256', $path);
    $files[$sha] = $path;
    $bytes[$index + 1] = base64_encode(file_get_contents($path));
    $parts[] = (object) [
        'id' => $index + 1, 'message_id' => 42, 'blob_sha256' => $sha,
        'content_id' => '<photo'.$index.'@example.test>', 'disposition' => 'inline',
        'content_type' => $extension === 'png' ? 'image/png' : 'image/jpeg', 'size_bytes' => filesize($path),
    ];
}
$blobs = new class($files) extends BlobStore
{
    public function __construct(private array $files) {}

    public function verifiedPath(string $sha): ?string
    {
        $path = $this->files[$sha] ?? null;

        return $path !== null && hash_file('sha256', $path) === $sha ? $path : null;
    }
};
$remote = htmlspecialchars($argv[1] ?? 'http://127.0.0.1:1/trap', ENT_QUOTES);
$html = '<p>Allowed content</p><a href="https://example.test">Safe link</a>'
    .'<img alt="PNG" src="cid:photo0%40example.test"><img alt="JPEG" src="cid:photo1@example.test">'
    .'<script>document.title="EXECUTED";top.location="/trap"</script>'
    .'<img src="'.$remote.'" onerror="document.title=\'EXECUTED\'">'
    .'<img src="https://attacker.invalid/pixel"><img src="cid:../../trap">'
    .'<iframe src="/trap"></iframe><form action="/trap"><input autofocus></form>'
    .'<style>@import url("/trap");</style><meta http-equiv="refresh" content="0;url=/trap">';
$stored = (new EmailHtml)->sanitize($html);
echo json_encode([
    'html' => (new InlineImages($blobs))->resolve($stored['html_sanitized'], 42, $parts),
    'csp' => EmailHtml::CSP, 'images' => $bytes, 'remote_count' => $stored['remote_content_count'],
], JSON_THROW_ON_ERROR);
