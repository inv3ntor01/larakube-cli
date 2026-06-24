<?php

namespace App\Traits;

trait InteractsWithJsonFile
{
    /** Read and decode a JSON file into an array, or null if missing/invalid. */
    protected static function readJsonFile(string $path): ?array
    {
        if (! file_exists($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return json_last_error() === JSON_ERROR_NONE && is_array($data) ? $data : null;
    }

    /** Write to a temp file in the same directory then rename, so a crash mid-write can't leave the file truncated/corrupt. */
    protected static function atomicWriteJson(string $path, array $data, ?int $mode = null): void
    {
        $tmp = $path.'.tmp'.getmypid();
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($mode !== null) {
            @chmod($tmp, $mode);
        }

        rename($tmp, $path);
    }
}
