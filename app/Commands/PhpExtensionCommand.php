<?php

namespace App\Commands;

use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class PhpExtensionCommand extends Command
{
    use InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'php:ext {extension : The name of the PHP extension to add (e.g. gd, imagick, bcmath)}';

    /**
     * The console command description.
     */
    protected $description = 'Add a PHP extension to your project';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->ensureIsProject()) {
            return 1;
        }

        $extension = strtolower($this->argument('extension'));
        $projectPath = getcwd();
        $configPath = $projectPath.'/.larakube.json';
        $dockerfilePath = $projectPath.'/Dockerfile.php';

        // 1. Update .larakube.json
        $config = json_decode(file_get_contents($configPath), true);
        $extensions = $config['additionalExtensions'] ?? [];

        if (in_array($extension, $extensions)) {
            $this->laraKubeInfo("Extension '{$extension}' is already in your configuration.");
        } else {
            $extensions[] = $extension;
            $config['additionalExtensions'] = array_unique($extensions);
            file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->laraKubeInfo("Added '{$extension}' to .larakube.json");
        }

        // 2. Update Dockerfile.php
        if (file_exists($dockerfilePath)) {
            $dockerfile = file_get_contents($dockerfilePath);

            // Find the install-php-extensions line
            if (preg_match('/RUN install-php-extensions (.*)/', $dockerfile, $matches)) {
                $currentExts = array_filter(explode(' ', trim($matches[1])));
                if (! in_array($extension, $currentExts)) {
                    $currentExts[] = $extension;
                    $newExts = implode(' ', array_unique($currentExts));
                    $dockerfile = str_replace($matches[0], "RUN install-php-extensions {$newExts}", $dockerfile);
                    file_put_contents($dockerfilePath, $dockerfile);
                    $this->laraKubeInfo("Updated Dockerfile.php with '{$extension}'");
                }
            } else {
                // If the line is commented or missing, we might need a more complex injection,
                // but for standard LaraKube projects, it should be there.
                warning("Could not find an active 'RUN install-php-extensions' line in Dockerfile.php. Please add it manually.");
            }
        }

        $this->line('');
        info("Success! Run 'larakube up' to rebuild your image with the new extension.");

        return 0;
    }
}
