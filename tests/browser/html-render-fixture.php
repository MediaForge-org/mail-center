<?php

use App\Messages\EmailHtml;
use App\Messages\InlineImages;
use App\Storage\BlobStore;
use Dom\HTMLDocument;

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
if (in_array('--fidelity', $argv, true)) {
    $html = file_get_contents(__DIR__.'/../Fixtures/html/account-notification.html');
} else {
    $html .= '<div style="background-image:url('.$remote.');position:fixed;z-index:999999;color:red" onclick="bad()">CSS probe</div>'
        .'<div style="font-family:url('.$remote.');width:expression(alert(1));-moz-binding:url('.$remote.')">More CSS</div>';
}
$stored = (new EmailHtml)->sanitize($html);
$source = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
foreach ($source->getElementsByTagName('img') as $image) {
    $src = $image->getAttribute('src');
    $image->removeAttribute('src');
    if ($src === 'cid:photo0@example.test') {
        $image->setAttribute('src', '/api/messages/42/inline/1');
    }
}
$resolved = (new InlineImages($blobs))->resolve($stored['html_sanitized'], 42, $parts);
$before = HTMLDocument::createFromString($resolved, LIBXML_NOERROR, 'UTF-8');
foreach ($before->getElementsByTagName('*') as $element) {
    foreach (['style', 'cellpadding', 'cellspacing', 'colspan', 'rowspan'] as $name) {
        $element->removeAttribute($name);
    }
}
echo json_encode([
    'html' => $resolved, 'source' => $source->saveHtml(), 'before' => $before->body->innerHTML, 'css' => EmailHtml::RENDER_CSS,
    'csp' => EmailHtml::CSP, 'images' => $bytes, 'remote_count' => $stored['remote_content_count'],
], JSON_THROW_ON_ERROR);
