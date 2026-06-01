<?php

namespace App\Commands;

use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class UninstallCommand extends Command
{
    use LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'uninstall';

    /**
     * The console command description.
     */
    protected $description = 'Remove the LaraKube CLI application from your system';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube CLI Uninstaller');

        $binaryPath = $_SERVER['argv'][0] ?? '/usr/local/bin/larakube';

        // Safety Guard: Don't run inside a container
        if (file_exists('/.dockerenv')) {
            $this->error('  ✖ The "uninstall" command must be run directly on your HOST machine.');

            return 1;
        }

        $this->warn("  ⚠ This will delete the LaraKube CLI binary from: {$binaryPath}");
        $this->warn("  ⚠ It will also recommend running 'untrust' to clean up SSL certs.");
        $this->newLine();

        if (! confirm('Are you sure you want to uninstall the LaraKube CLI?', false)) {
            $this->laraKubeInfo('Uninstall cancelled.');

            return 0;
        }

        // 1. Recommend untrust
        $this->info('  👉 Recommendation: Run "larakube untrust" BEFORE this command if you want to clean up system SSL trust.');

        // 2. Perform uninstallation
        $this->withSpin('Removing LaraKube CLI binary...', function () use ($binaryPath) {
            if (is_writable($binaryPath)) {
                @unlink($binaryPath);
            } else {
                exec('sudo rm -f '.escapeshellarg($binaryPath));
            }

            return true;
        });

        $this->laraKubeInfo('LaraKube CLI has been removed from your system.');
        $this->info('To fully clean up, you may also delete ~/.larakube if it exists.');

        return 0;
    }
}
