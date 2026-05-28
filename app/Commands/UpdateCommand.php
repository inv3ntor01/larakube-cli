<?php

namespace App\Commands;

use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;

class UpdateCommand extends Command
{
    use LaraKubeOutput;

    protected $signature = 'update';

    protected $description = 'Update the LaraKube CLI to the latest version';

    public function handle(): int
    {
        $this->renderHeader();

        $currentVersion = config('app.version');
        $this->laraKubeInfo("Current version: <fg=yellow>$currentVersion</>");

        $this->laraKubeInfo('Checking for latest version...');

        $response = Http::withHeaders(['User-Agent' => 'LaraKube-CLI'])
            ->get('https://api.github.com/repos/luchavez-technologies/larakube-cli/releases/latest');

        if ($response->failed()) {
            $this->laraKubeError('Failed to fetch the latest version from GitHub.');

            return 1;
        }

        $latestVersion = $response->json('tag_name');

        if ($latestVersion === $currentVersion || $currentVersion === 'unreleased') {
            $this->laraKubeInfo('✅ You are already using the latest version!');

            return 0;
        }

        $this->laraKubeInfo("A new version is available: <fg=green>$latestVersion</>");

        if (! $this->confirm('Do you want to update now?', true)) {
            return 0;
        }

        // 1. Detect OS and Architecture
        $os = strtolower(PHP_OS_FAMILY) === 'darwin' ? 'mac' : 'linux';
        $machine = php_uname('m');

        $arch = match ($machine) {
            'x86_64' => 'x64',
            'arm64', 'aarch64' => 'arm',
            default => null,
        };

        if (! $arch) {
            $this->laraKubeError("Unsupported architecture: $machine");

            return 1;
        }

        $binaryName = "larakube-$os-$arch";
        $downloadUrl = "https://github.com/luchavez-technologies/larakube-cli/releases/download/$latestVersion/$binaryName";

        $this->laraKubeInfo("Downloading $binaryName for $os ($arch)...");

        $tempPath = '/tmp/larakube';

        try {
            $binaryContent = file_get_contents($downloadUrl);
            if ($binaryContent === false) {
                throw new \Exception('Download failed.');
            }
            file_put_contents($tempPath, $binaryContent);
        } catch (\Exception $e) {
            $this->laraKubeError("Failed to download binary from $downloadUrl");

            return 1;
        }

        $this->laraKubeInfo('🚚 Installing latest version to /usr/local/bin/larakube (requires sudo)...');

        // Atomic swap via sudo
        $installCmd = "sudo mv $tempPath /usr/local/bin/larakube && sudo chmod +x /usr/local/bin/larakube";
        passthru($installCmd, $exitCode);

        if ($exitCode !== 0) {
            $this->laraKubeError('Installation failed. Please check your permissions.');

            return 1;
        }

        $this->laraKubeInfo("✅ LaraKube updated successfully to $latestVersion!");

        return 0;
    }
}
