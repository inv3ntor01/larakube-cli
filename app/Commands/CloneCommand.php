<?php

namespace App\Commands;

use App\Data\GlobalConfigData;
use App\Traits\ClonesRepositories;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class CloneCommand extends Command
{
    use ClonesRepositories, InteractsWithProjectConfig, LaraKubeOutput;

    protected $signature = 'clone
        {repo : Repository URL, SSH URL, or user/repo shorthand}
        {directory? : Target directory (defaults to repo name)}
        {--branch= : Branch to clone}
        {--provider=github : Git host for user/repo shorthand (github, gitlab, bitbucket)}
        {--no-install : Skip composer install}';

    protected $description = 'Clone a Laravel repository and prepare it for LaraKube CLI in one command';

    public function handle(): int
    {
        $this->renderHeader();

        $repo = (string) $this->argument('repo');
        $provider = (string) ($this->option('provider') ?: 'github');

        if (! in_array($provider, ['github', 'gitlab', 'bitbucket'], true)) {
            $this->laraKubeError("Unknown provider '{$provider}'. Use: github, gitlab, or bitbucket.");

            return 1;
        }

        $url = $this->resolveRepoUrl($repo, $provider);
        $directory = (string) ($this->argument('directory') ?: $this->deriveDirectoryName($url));
        $branch = $this->option('branch');
        $targetPath = getcwd().'/'.$directory;

        // Guard: directory already exists
        if (is_dir($targetPath)) {
            $this->laraKubeError("Directory '{$directory}' already exists.");
            $this->line('  <fg=gray>cd into it and run:</> <fg=yellow>larakube init</>');

            return 1;
        }

        // ── Step 1: Clone ──────────────────────────────────────────────────────

        $this->laraKubeInfo("Cloning {$url}…");
        $this->newLine();

        $cloneCode = $this->runGitClone($url, $targetPath, $branch);

        if ($cloneCode !== 0) {
            $this->laraKubeError('git clone failed. Check the URL and your network/credentials.');

            return 1;
        }

        $this->newLine();
        $this->laraKubeInfo("Cloned into {$directory}/");

        // ── Step 2: Detect project state ───────────────────────────────────────

        $hasComposerJson = file_exists($targetPath.'/composer.json');

        if (! $hasComposerJson) {
            $this->newLine();
            $this->laraKubeWarn("This repo doesn't look like a PHP/Laravel project (no composer.json found).");

            if (! confirm('Continue anyway?', false)) {
                return 0;
            }
        }

        // ── Step 3: .env bootstrap ────────────────────────────────────────────

        try {
            $envResult = $this->bootstrapDotEnv($targetPath);
        } catch (RuntimeException $e) {
            $this->newLine();
            $this->laraKubeError($e->getMessage());
            $this->line('  <fg=gray>Create a .env.example in the repo and try again, or add a .env manually.</>');

            return 1;
        }

        $this->newLine();

        if ($envResult === 'copied') {
            $this->laraKubeInfo('.env created from .env.example with a fresh APP_KEY.');

            // Patch APP_URL and ASSET_URL so they point to the local LaraKube domain
            $tld = GlobalConfigData::load()->getLocalTld();
            $appUrl = "https://{$directory}.{$tld}";
            $this->patchDotEnv($targetPath, [
                'APP_URL' => $appUrl,
                'ASSET_URL' => $appUrl,
            ]);
            $this->line("  <fg=gray>APP_URL / ASSET_URL set to:</> <fg=cyan>{$appUrl}</>");
        } else {
            $this->line('  <fg=gray>.env already exists — left untouched.</>');
        }

        // ── Step 4: composer install ───────────────────────────────────────────

        if (! $this->option('no-install') && $hasComposerJson) {
            $this->newLine();
            $this->laraKubeInfo('Running composer install…');
            $this->newLine();

            $installCode = $this->runComposerInstall($targetPath);

            if ($installCode !== 0) {
                $this->laraKubeWarn('composer install exited with errors. You may need to fix dependencies manually.');
            }
        }

        // ── Step 5: Init ──────────────────────────────────────────────────────

        $hasLaraKubeJson = file_exists($targetPath.'/.larakube.json');

        if ($hasLaraKubeJson) {
            $this->newLine();
            $this->laraKubeInfo('Existing LaraKube CLI config found (.larakube.json) — skipping init wizard.');

            // Detect Plex-managed services for the local environment and offer to join
            $projectConfig = $this->getProjectConfig($targetPath);
            if ($projectConfig) {
                $plexServices = $projectConfig->getPlex('local');

                if (! empty($plexServices)) {
                    $this->newLine();
                    $this->laraKubeWarn('This project uses shared Plex commons for: '.implode(', ', $plexServices).'.');
                    $this->line('  <fg=gray>Run <fg=yellow>plex:join local</> to reconnect your tenant credentials (requires K3D to be running).</>');
                    $this->newLine();

                    if (confirm('Join the local Plex commons now?', false)) {
                        chdir($targetPath);
                        $this->call('plex:join', ['environment' => 'local']);
                    }
                }
            }
        } else {
            $this->newLine();
            $this->laraKubeInfo('Running larakube init to configure this project…');
            $this->newLine();

            chdir($targetPath);
            $initCode = $this->call('init');

            if ($initCode !== 0) {
                $this->laraKubeWarn('Init did not complete successfully. Re-run: larakube init');
            }
        }

        // ── Done ──────────────────────────────────────────────────────────────

        $this->newLine();
        $this->laraKubeInfo("Ready! cd {$directory} && larakube up");

        return 0;
    }
}
