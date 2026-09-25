<?php

namespace App\Sync;

use RuntimeException;

/** The account was disabled/removed, or its lock was lost, while a sync was running. */
class SyncAborted extends RuntimeException {}
