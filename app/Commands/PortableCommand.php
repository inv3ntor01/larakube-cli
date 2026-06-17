<?php

namespace App\Commands;

use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\File;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class PortableCommand extends Command
{
    use InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'portable
                            {--force : Overwrite existing files without prompting}
                            {--script-only : Generate only larakube.sh, skip the LOCAL_DEV.md guide (use when the workflow is already documented in your README)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a CLI-free larakube.sh wrapper (and optional guide) so teammates can run this project locally without installing LaraKube';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $projectPath = getcwd();
        $wrote = 0;

        foreach ($this->files() as $name => $meta) {
            $dest = "{$projectPath}/{$name}";

            if (File::exists($dest) && ! $this->option('force')) {
                // Auto-decline in CI/test environments to enable non-interactive testing
                $autoDecline = getenv('LARAKUBE_TEST_SKIP_PROMPTS') || app()->runningUnitTests();
                if ($autoDecline || ! confirm("{$name} already exists. Overwrite it?", default: false)) {
                    $this->laraKubeInfo("Skipped {$name} (kept your version).");

                    continue;
                }
            }

            File::put($dest, File::get($meta['stub']));
            @chmod($dest, $meta['mode']);
            $this->laraKubeInfo("Wrote {$name}");
            $wrote++;
        }

        if ($wrote === 0) {
            $this->laraKubeInfo('Nothing written.');

            return 0;
        }

        $this->newLine();
        $this->laraKubeInfo('Portable local-dev tooling ready. 🎉');
        $this->line('  Commit this so teammates can run the project WITHOUT installing the LaraKube CLI:');
        $this->line('    <fg=yellow>./larakube.sh list</>   — see available commands');
        if (! $this->option('script-only')) {
            $this->line('    <fg=yellow>LOCAL_DEV.md</>         — how-to guide');
        }

        return 0;
    }

    /**
     * Files to drop into the project, mapped to their stub + permissions.
     *
     * @return array<string, array{stub: string, mode: int}>
     */
    protected function files(): array
    {
        $files = [
            'larakube.sh' => ['stub' => base_path('stubs/portable/larakube.sh.stub'), 'mode' => 0755],
        ];

        // The guide is redundant when the project already documents the
        // workflow (e.g. in its README), so it can be skipped.
        if (! $this->option('script-only')) {
            $files['LOCAL_DEV.md'] = ['stub' => base_path('stubs/portable/LOCAL_DEV.md.stub'), 'mode' => 0644];
        }

        return $files;
    }
}
