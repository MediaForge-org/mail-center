<?php

namespace App\Messages;

use Dom\HTMLDocument;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class EmailHtml
{
    public const VERSION = 1;

    public const CSP = "sandbox allow-same-origin allow-popups allow-popups-to-escape-sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; font-src 'none'; script-src 'none'; form-action 'none'; frame-ancestors 'self'; base-uri 'none'";

    /** @return array{html_sanitized: ?string, sanitizer_version: int, remote_content_count: int} */
    public function sanitize(?string $html): array
    {
        $result = ['html_sanitized' => null, 'sanitizer_version' => self::VERSION, 'remote_content_count' => 0];
        if ($html === null || $html === '' || strlen($html) > 1024 * 1024) {
            return $result;
        }
        $config = (new HtmlSanitizerConfig)->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks(false)->allowMediaSchemes([])->withMaxInputLength(1024 * 1024);
        foreach (['div', 'span', 'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'u', 's', 'blockquote', 'pre', 'code', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption'] as $tag) {
            $config = $config->allowElement($tag, []);
        }
        $config = $config->allowElement('a', ['href', 'title'])
            ->forceAttribute('a', 'target', '_blank')
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->allowElement('img', ['alt', 'title']);

        // Parse only to count removed image sources; this parser never fetches resources.
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        foreach ($document->getElementsByTagName('img') as $image) {
            $src = trim($image->getAttribute('src'));
            if (str_starts_with($src, '//') || in_array(strtolower((string) parse_url($src, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                $result['remote_content_count']++;
            }
        }
        $result['html_sanitized'] = (new HtmlSanitizer($config))->sanitize($html);

        return $result;
    }
}
