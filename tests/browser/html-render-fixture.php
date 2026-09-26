<?php

use App\Messages\EmailHtml;

require __DIR__.'/../../vendor/autoload.php';

$sanitizer = new EmailHtml;
$html = '<p>Allowed content</p><a href="https://example.test">Safe link</a>'
    .'<script>document.title="EXECUTED";top.location="/trap"</script>'
    .'<img src="http://127.0.0.1:1/trap" onerror="document.title=\'EXECUTED\'">'
    .'<iframe src="/trap"></iframe><form action="/trap"><input autofocus></form>'
    .'<style>@import url("/trap");</style><meta http-equiv="refresh" content="0;url=/trap">';
echo json_encode(['html' => $sanitizer->sanitize($html)['html_sanitized'], 'csp' => EmailHtml::CSP], JSON_THROW_ON_ERROR);
