<?php

use App\Messages\EmailCss;
use App\Messages\EmailHtml;

it('preserves parsed email typography, spacing, layout and button styling', function () {
    $safe = (new EmailCss)->sanitize('color:#202124;background-color:rgb(248,249,250);font-family:Arial,"Google Sans",sans-serif;font-size:24px;font-weight:600;font-style:italic;line-height:1.5;text-align:center;text-decoration:none;vertical-align:middle;margin:20px auto;padding:16px 24px;width:600px;max-width:100%;min-width:20px;height:auto;max-height:800px;border:1px solid #dadce0;border-radius:8px;border-collapse:collapse;border-spacing:0;display:inline-block');
    foreach (['color:', 'background-color:', 'font-family:', 'font-size:24px', 'font-weight:600', 'font-style:italic', 'line-height:1.5', 'text-align:center', 'text-decoration:none', 'vertical-align:middle', 'margin:20px auto', 'padding:16px 24px', 'width:600px', 'max-width:100%', 'min-width:20px', 'height:auto', 'max-height:800px', 'border:', 'border-radius:8px', 'border-collapse:collapse', 'border-spacing:0', 'display:inline-block'] as $declaration) {
        expect($safe)->toContain($declaration);
    }
});

it('rejects unsafe CSS syntax, AST values and properties', function (string $css) {
    $safe = (new EmailCss)->sanitize($css);
    expect($safe)->toBe('');
})->with([
    'background-image:url(https://attacker.invalid/pixel)',
    'color:url(https://attacker.invalid/pixel)',
    'font-family:url(https://attacker.invalid/font)',
    '@import "https://attacker.invalid/style";',
    '@font-face{font-family:evil;src:url(https://attacker.invalid/font)}',
    'width:expression(alert(1))',
    'background:url(javascript:alert(1))',
    'background-image:url(data:image/svg+xml,evil)',
    'position:fixed;top:0;left:0;z-index:999999',
    'position:sticky;transform:scale(50);opacity:0',
    'behavior:url(x);-moz-binding:url(x);-webkit-mask-image:url(x)',
    '--color:red;color:var(--color)', 'width:calc(100% - 2px)',
    'color:rgb(var(--r),0,0)', 'width:999999px;padding:-200px',
    'display:none;visibility:hidden', 'color:red;}body{background:red',
    'color:"unterminated', 'color:rgb(1,2,', 'font-family:"bad;position:fixed"',
    'background-image:u\\72l(https://attacker.invalid)', 'co\\6cor:red',
]);

it('retains good declarations beside rejected ones and never copies comments or important', function () {
    $safe = (new EmailCss)->sanitize('color:red!important;/* tracking */ background-image:url(https://attacker.invalid);padding:12px;position:fixed;display:block');
    expect($safe)->toBe('color:red;padding:12px;display:block');
});

it('preserves safe legacy table formatting while dropping active HTML and style blocks', function () {
    $html = (new EmailHtml)->sanitize('<body bgcolor="#f8f9fa"><style>@import "https://attacker.invalid";</style><table width="600" align="center" cellpadding="0" cellspacing="0"><tr><td bgcolor="#ffffff" valign="top" colspan="2" style="padding:32px;color:#202124" onclick="bad()">Safe <a href="https://example.test" style="display:inline-block;padding:12px 24px;background-color:#1a73e8;color:white;border-radius:4px">Review activity</a></td></tr></table><img width="48" height="48" src="cid:logo"><script>bad()</script></body>')['html_sanitized'];
    foreach (['style=', 'width:600px', 'margin-left:auto', 'cellpadding="0"', 'colspan="2"', 'padding:32px', 'background-color:', 'display:inline-block', 'width:48px', 'data-mc-resource'] as $expected) {
        expect($html)->toContain($expected);
    }
    foreach (['<style', '<script', 'onclick', 'attacker.invalid', 'src=', 'bgcolor=', 'valign='] as $bad) {
        expect($html)->not->toContain($bad);
    }
    expect(EmailHtml::VERSION)->toBe(4);
});
