<?php

namespace App\Commands\Plex;

use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class PlexExportCommand extends Command
{
    use InteractsWithPlex, LaraKubeOutput;

    protected $signature = 'plex:export
        {--output= : Write the spec to a file instead of stdout}';

    protected $description = 'Export the live Commons spec (for disaster recovery / GitOps)';

    public function handle(): int
    {
        $spec = $this->getCommonsSpec();

        if ($spec === null) {
            $this->laraKubeError('No Commons found on the current cluster. Run plex:init first.');

            return 1;
        }

        $json = (string) json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $output = $this->option('output');

        if ($output) {
            file_put_contents($output, $json.PHP_EOL);
            $this->laraKubeInfo("Commons spec written to {$output}");
            $this->line('  Rebuild on a fresh cluster with: <fg=yellow>larakube plex:init --from '.$output.'</>');

            return 0;
        }

        // Raw JSON to stdout so it can be piped/redirected cleanly.
        $this->line($json);

        return 0;
    }
}
