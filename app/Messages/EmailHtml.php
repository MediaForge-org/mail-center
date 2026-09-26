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
    public const VERSION = 4;

    public const RENDER_CSS = 'html{overflow-x:auto}body{margin:0;font:14px/1.5 Arial,sans-serif;overflow-wrap:anywhere}img{max-width:100%!important;height:auto}table{max-width:100%!important;min-width:0!important}pre{white-space:pre-wrap;overflow-wrap:anywhere}';

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

    /** @return array{html_sanitized: ?string, sanitizer_version: int, remote_content_count: int, remote_resources: string} */
    public function sanitize(?string $html, ?array $mimeIdentities = null): array
    {
        $result = ['html_sanitized' => null, 'sanitizer_version' => self::VERSION, 'remote_content_count' => 0, 'remote_resources' => '{}'];
        if ($html === null || $html === '' || strlen($html) > 1024 * 1024) {
            return $result;
        }
        $config = (new HtmlSanitizerConfig)->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks(false)->allowMediaSchemes([])->withMaxInputLength(1024 * 1024);
        foreach (['div', 'span', 'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'u', 's', 'blockquote', 'pre', 'code', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'colgroup', 'col', 'center', 'font', 'small', 'sup', 'sub', 'section', 'article', 'header', 'footer'] as $tag) {
            $config = $config->allowElement($tag, ['style', 'dir', 'colspan', 'rowspan', 'cellpadding', 'cellspacing']);
        }
        $config = $config->allowElement('a', ['href', 'title', 'style'])
            ->forceAttribute('a', 'target', '_blank')
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->allowElement('img', ['alt', 'title', 'style', InlineImages::ATTRIBUTE, 'data-mc-remote']);

        // Replace CID sources with neutral identities before allowlist sanitization. No resource fetches.
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        $css = new EmailCss;
        // Body presentation is retained on a neutral wrapper; head/style blocks remain excluded.
        $wrapper = $document->createElement('div');
        foreach (['style', 'bgcolor', 'dir'] as $name) {
            if ($document->body->hasAttribute($name)) {
                $wrapper->setAttribute($name, $document->body->getAttribute($name));
            }
        }
        while ($document->body->firstChild !== null) {
            $wrapper->appendChild($document->body->firstChild);
        }
        $document->body->appendChild($wrapper);
        foreach ($document->getElementsByTagName('*') as $element) {
            $legacy = '';
            foreach (['width', 'height'] as $name) {
                $value = (string) $element->getAttribute($name);
                if (preg_match('/\A[0-9]{1,4}%?\z/', $value)) {
                    $legacy .= $name.':'.$value.(str_ends_with($value, '%') ? '' : 'px').';';
                }
            }
            foreach (['bgcolor' => 'background-color', 'color' => 'color', 'face' => 'font-family', 'align' => 'text-align', 'valign' => 'vertical-align'] as $name => $property) {
                $value = (string) $element->getAttribute($name);
                // Legacy attributes are single values, never declaration lists.
                if ($value !== '' && preg_match('/\A[a-zA-Z0-9# ,%-]+\z/', $value)) {
                    $legacy .= $property.':'.$value.';';
                }
            }
            if ($element->tagName === 'TABLE' || $element->tagName === 'table') {
                if (strtolower((string) $element->getAttribute('align')) === 'center') {
                    $legacy .= 'margin-left:auto;margin-right:auto;';
                }
            }
            foreach (['colspan', 'rowspan', 'cellpadding', 'cellspacing'] as $name) {
                if (! preg_match('/\A(?:0|[1-9][0-9]?)\z/', (string) $element->getAttribute($name))) {
                    $element->removeAttribute($name);
                }
            }
            if (! in_array(strtolower((string) $element->getAttribute('dir')), ['ltr', 'rtl'], true)) {
                $element->removeAttribute('dir');
            }
            $style = $css->sanitize($legacy.(string) $element->getAttribute('style'));
            $element->removeAttribute('style');
            if ($style !== '') {
                $element->setAttribute('style', $style);
            }
        }
        $resources = [];
        foreach ($document->getElementsByTagName('img') as $image) {
            $src = trim((string) $image->getAttribute('src'));
            $image->removeAttribute(InlineImages::ATTRIBUTE);
            $image->removeAttribute('data-mc-remote');
            $identity = InlineImages::identity($src, uri: true);
            if ($identity !== null && ($mimeIdentities === null || in_array($identity, $mimeIdentities, true))) {
                $image->setAttribute(InlineImages::ATTRIBUTE, $identity);
            }
            if (str_starts_with($src, '//') || in_array(strtolower((string) parse_url($src, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                // Protocol-relative images use HTTPS; no resolution/fetch occurs here.
                $url = str_starts_with($src, '//') ? 'https:'.$src : $src;
                $key = hash('sha256', $url);
                $resources[$key] = $url;
                $image->setAttribute('data-mc-remote', $key);
            }
        }
        $result['html_sanitized'] = (new HtmlSanitizer($config))->sanitize($document->saveHtml());

        // Count only markers that survived sanitization.
        $clean = HTMLDocument::createFromString($result['html_sanitized'], LIBXML_NOERROR, 'UTF-8');
        $used = [];
        foreach ($clean->getElementsByTagName('img') as $image) {
            $key = (string) $image->getAttribute('data-mc-remote');
            if (isset($resources[$key])) {
                $result['remote_content_count']++;
                $used[$key] = $resources[$key];
            }
        }
        $result['remote_resources'] = json_encode((object) $used, JSON_THROW_ON_ERROR);

        return $result;
    }
}
