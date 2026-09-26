<?php

namespace App\Messages;

use Dom\HTMLDocument;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\Message\IMessagePart;
use ZBateson\MailMimeParser\Message\IMultiPart;

final class EmailHtml
{
    public const VERSION = 2;

    public const CSP = "sandbox allow-same-origin allow-popups allow-popups-to-escape-sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; font-src 'none'; script-src 'none'; form-action 'none'; frame-ancestors 'self'; base-uri 'none'";

    /** Count even non-attachment body parts: duplicate MIME identities must stay blocked. */
    public function sanitizeMessage(IMessage $message): array
    {
        $counts = [];
        $parts = 0;
        $walk = function (IMessagePart $part, int $depth) use (&$walk, &$counts, &$parts): void {
            if (++$parts > 500 || $depth > 20) {
                throw new \RuntimeException('MIME structure exceeds resource limit.');
            }
            $key = InlineImages::identity($part->getContentId());
            if ($key !== null) {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
            if ($part instanceof IMultiPart) {
                foreach ($part->getChildParts() as $child) {
                    $walk($child, $depth + 1);
                }
            }
        };
        $walk($message, 0);

        return $this->sanitize($message->getHtmlContent(), array_keys(array_filter($counts, fn ($count) => $count === 1)));
    }

    /** @return array{html_sanitized: ?string, sanitizer_version: int, remote_content_count: int} */
    public function sanitize(?string $html, ?array $mimeIdentities = null): array
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
            ->allowElement('img', ['alt', 'title', InlineImages::ATTRIBUTE]);

        // Replace CID sources with neutral identities before allowlist sanitization. No resource fetches.
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        foreach ($document->getElementsByTagName('img') as $image) {
            $src = trim((string) $image->getAttribute('src'));
            $image->removeAttribute(InlineImages::ATTRIBUTE);
            $identity = InlineImages::identity($src, uri: true);
            if ($identity !== null && ($mimeIdentities === null || in_array($identity, $mimeIdentities, true))) {
                $image->setAttribute(InlineImages::ATTRIBUTE, $identity);
            }
            if (str_starts_with($src, '//') || in_array(strtolower((string) parse_url($src, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                $result['remote_content_count']++;
            }
        }
        $result['html_sanitized'] = (new HtmlSanitizer($config))->sanitize($document->saveHtml());

        return $result;
    }
}
