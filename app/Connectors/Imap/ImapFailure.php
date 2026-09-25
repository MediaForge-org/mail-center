<?php

namespace App\Connectors\Imap;

use RuntimeException;
use Throwable;

/** A classified connector failure. Messages are sanitized and safe to store and display. */
class ImapFailure extends RuntimeException
{
    public const AUTH = 'auth_failed';

    public const TLS = 'tls_invalid';

    public const TRANSIENT = 'connection_failed';

    public const FOLDER = 'folder_unavailable';

    public const MESSAGE = 'message_error';

    public const DESTINATION = 'destination_rejected';

    public function __construct(public readonly string $category, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function safeMessage(string $category): string
    {
        return match ($category) {
            self::AUTH => 'The server rejected the username or password.',
            self::TLS => 'The server certificate could not be verified.',
            self::FOLDER => 'A mailbox folder could not be opened.',
            self::DESTINATION => 'This IMAP host or port is not allowed.',
            default => 'Could not connect to the mail server.',
        };
    }
}
