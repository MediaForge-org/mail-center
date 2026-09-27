<?php

use App\Organization\DefaultFolders;
use Illuminate\Database\Migrations\Migration;

/**
 * M4.1 default custom folders (Reloads, Support, Withdrawals, Verification, Done) were never
 * seeded because folder organization was deferred through M3. Backfill idempotently for every
 * existing user without disturbing any folder they already have or any messages.folder_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        DefaultFolders::backfillAllUsers();
    }

    public function down(): void
    {
        // Irreversible by design: seeded folders are ordinary user folders after creation, and a
        // user may already have renamed, reordered or filed mail into them.
    }
};
