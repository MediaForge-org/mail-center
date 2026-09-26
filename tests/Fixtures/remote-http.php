<?php

// Only launched by deterministic network tests, never registered with the application.
if ($_SERVER['REQUEST_URI'] === '/slow') {
    usleep(600000);
}
header('Content-Type: image/png');
header('Set-Cookie: upstream=must-not-forward');
if ($_SERVER['REQUEST_URI'] === '/big') {
    for ($n = 0; $n < 170; $n++) {
        echo str_repeat('x', 65536);
        flush();
    }
} else {
    echo file_get_contents(__DIR__.'/inline/pixel.png');
}
