<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;

trait InteractsWithInternalDatabase
{
    use InteractsWithGlobalConfig;

    /**
     * Ensure the internal SQLite database is fully migrated and ready.
     */
    protected function ensureDatabaseIsReady(): void
    {
        $dbPath = config('database.connections.sqlite.database');

        // 1. Ensure Directory & File Exist
        if (! file_exists($dbPath)) {
            @mkdir(dirname($dbPath), 0755, true);
            touch($dbPath);
        }

        // 2. Run Migrations as a blocking system call
        // This ensures the database is physically altered on disk
        $bin = $this->getLaraKubeBinaryForDb();
        system("{$bin} migrate --force > /dev/null 2>&1");
        
        // 3. Clear the Laravel DB state to pick up changes
        DB::purge('sqlite');
    }

    /**
     * Get the resolved LaraKube binary path specifically for DB operations.
     */
    protected function getLaraKubeBinaryForDb(): string
    {
        if (file_exists('/larakube/larakube')) {
            return 'php /larakube/larakube';
        }

        $self = $_SERVER['argv'][0] ?? 'larakube';
        if (file_exists($self)) {
            return realpath($self);
        }

        return $self;
    }
}
