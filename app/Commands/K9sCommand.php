<?php

namespace App\Commands;

use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithOs;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class K9sCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithOs, InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'k9s {environment=local : The environment to monitor (defaults to local)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Launch K9s terminal UI pre-scoped to your project namespace';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        if (shell_exec('which k9s') === null && ! $this->setupK9s()) {
            return 1;
        }

        // Defensive: signature default is 'local', but internal $this->call()
        // invocations may pass null explicitly (e.g. from ConsoleCommand).
        $environment = $this->argument('environment') ?: 'local';

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);
        $namespace = $this->getNamespace($environment, $config->getName());

        // Browse the ENV's OWN context (never switch the global one). Local uses
        // the current context; a cloud env targets its saved context — a managed
        // kube-context (DOKS/EKS/…) OR a VPS's larakube-<ip>. We don't prompt here
        // — k9s is read-only browsing — just hint if unset.
        $contextFlag = '';
        $context = $this->environmentContextOrCurrent($config, $environment);
        if ($context) {
            $contextFlag = ' --context '.escapeshellarg($context);
        } elseif ($environment !== 'local') {
            $this->laraKubeWarn("No deploy target saved for '{$environment}' — opening k9s on your current context. Run `larakube cloud:configure:base {$environment}` to record it.");
        }

        $this->laraKubeInfo("Launching K9s for project <fg=cyan;options=bold>{$config->getName()}</> in namespace: <fg=yellow;options=bold>{$namespace}</>...");

        // Execute k9s
        passthru("k9s{$contextFlag} -n {$namespace}");

        return 0;
    }

    /**
     * Offer to install k9s when it's missing, then confirm it's on PATH.
     * Mirrors InteractsWithTrust::setupDnsmasq(). Returns true once k9s is
     * usable; false if the user declined or the install needs a new shell.
     */
    protected function setupK9s(): bool
    {
        $this->newLine();
        $this->line('  <fg=yellow>💡 Recommended:</> Install <fg=cyan>k9s</> for the best Kubernetes browsing experience.');
        $this->newLine();

        if (! confirm('Install k9s now?', true)) {
            $manual = $this->isLinux() ? 'sudo snap install k9s' : 'brew install k9s';
            $this->line("  <fg=gray>Skipped — install it later with: {$manual}</>");

            return false;
        }

        if (! $this->installK9s()) {
            return false;
        }

        // brew/snap may not be on the current shell's PATH hash yet.
        if (shell_exec('which k9s') === null) {
            $this->laraKubeWarn('k9s installed — open a new terminal and re-run `larakube k9s`.');

            return false;
        }

        $this->laraKubeInfo('k9s installed successfully.');

        return true;
    }

    /**
     * Install k9s via the platform package manager.
     * Mirrors InteractsWithTrust::installDnsmasq().
     */
    protected function installK9s(): bool
    {
        if ($this->isDarwin()) {
            if (trim((string) shell_exec('which brew 2>/dev/null')) === '') {
                $this->warn('  Homebrew not found. Install it from https://brew.sh then run: brew install k9s');

                return false;
            }
            passthru('brew install k9s', $code);

            return $code === 0;
        }

        if ($this->isLinux()) {
            // k9s isn't in the default apt/dnf repos; snap is the portable path
            // and matches what we recommend elsewhere.
            if (trim((string) shell_exec('which snap 2>/dev/null')) === '') {
                $this->warn('  snap not found. Install k9s manually: https://k9scli.io/topics/install/');

                return false;
            }
            passthru('sudo snap install k9s', $code);

            return $code === 0;
        }

        $this->warn('  k9s install not supported on this platform. See https://k9scli.io/topics/install/');

        return false;
    }
}
