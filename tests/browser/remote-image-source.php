<?php

// Controlled upstream: reachable only inside the isolated browser-test container.
file_put_contents('/tmp/mailcenter-remote-requests.jsonl', json_encode([
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'cookie' => $_SERVER['HTTP_COOKIE'] ?? null,
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'referer' => $_SERVER['HTTP_REFERER'] ?? null,
    'forwarded' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
])."\n", FILE_APPEND | LOCK_EX);
header('Content-Type: image/png');
header('Set-Cookie: upstream=not-allowed');
echo file_get_contents(__DIR__.'/../Fixtures/inline/pixel.png');
