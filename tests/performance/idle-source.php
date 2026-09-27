<?php

// Loopback-only deterministic IMAP IDLE fixture. Production TLS/SSRF policy is never changed.
$server = stream_socket_server('tcp://127.0.0.1:11430', $errno, $error);
$clients = $folders = $idling = [];
$sequence = 1;
while (true) {
    $read = [$server, ...$clients];
    $write = $except = [];
    if (! stream_select($read, $write, $except, 1)) {
        continue;
    }
    foreach ($read as $socket) {
        if ($socket === $server) {
            $client = stream_socket_accept($server, 0);
            stream_set_timeout($client, 1);
            $clients[(int) $client] = $client;
            fwrite($client, "* OK Synthetic IMAP ready\r\n");

            continue;
        }
        $key = (int) $socket;
        $line = fgets($socket);
        if ($line === false) {
            unset($clients[$key], $folders[$key], $idling[$key]);
            fclose($socket);

            continue;
        }
        $line = trim($line);
        if (str_starts_with($line, 'notify:')) {
            $folder = substr($line, 7);
            foreach ($folders as $id => $watched) {
                if ($watched === $folder && isset($idling[$id])) {
                    @fwrite($clients[$id], '* '.(++$sequence)." EXISTS\r\n");
                }
            }

            continue;
        }
        if ($line === 'DONE') {
            fwrite($socket, ($idling[$key] ?? 'TAG0')." OK IDLE completed\r\n");
            unset($idling[$key]);

            continue;
        }
        [$tag, $command, $args] = array_pad(explode(' ', $line, 3), 3, '');
        switch (strtoupper($command)) {
            case 'LOGIN': fwrite($socket, "$tag OK Logged in\r\n");
                break;
            case 'CAPABILITY': fwrite($socket, "* CAPABILITY IMAP4rev1 IDLE\r\n$tag OK Capabilities\r\n");
                break;
            case 'EXAMINE':
                $folders[$key] = trim($args, '"');
                fwrite($socket, "* 0 EXISTS\r\n* OK [UIDVALIDITY 1000]\r\n* OK [UIDNEXT 1]\r\n$tag OK [READ-ONLY] Examined\r\n");
                break;
            case 'IDLE': $idling[$key] = $tag;
                fwrite($socket, "+ idling\r\n");
                break;
            case 'LOGOUT': fwrite($socket, "* BYE Goodbye\r\n$tag OK Logout\r\n");
                break;
            default: fwrite($socket, "$tag BAD Unsupported command\r\n");
        }
    }
}
