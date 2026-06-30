<?php

namespace App\Commands;

use App\Traits\DetectsWsl;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithOs;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class K9sCommand extends Command
{
    use DetectsWsl, InteractsWithEnvironments, InteractsWithOs, InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext;

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

        if (shell_exec('which k9s') === null && ! is_file(home_path('.larakube/bin/k9s')) && ! $this->setupK9s()) {
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

        $this->executeK9s($namespace, $contextFlag);

        return 0;
    }

    /**
     * Resolve the k9s binary path — first from PATH, then from the managed location.
     */
    protected function resolveK9sBin(): ?string
    {
        $which = trim((string) shell_exec('command -v k9s 2>/dev/null'));
        if ($which !== '' && @is_executable($which)) {
            return $which;
        }

        $managed = home_path('.larakube/bin/k9s');
        if (@is_executable($managed)) {
            return $managed;
        }

        return null;
    }

    /**
     * Execute k9s
     */
    protected function executeK9s(string $namespace, string $contextFlag): void
    {
        $k9s = $this->resolveK9sBin() ?: 'k9s';
        passthru(escapeshellarg($k9s).$contextFlag.' -n '.escapeshellarg($namespace));
    }

    /**
     * Offer to install k9s when it's missing, then confirm it's usable.
     * Mirrors InteractsWithTrust::setupDnsmasq(). Returns true once k9s is
     * usable; false if the user declined or the install needs a new shell.
     */
    protected function setupK9s(): bool
    {
        $this->newLine();
        $this->line('  <fg=yellow>💡 Recommended:</> Install <fg=cyan>k9s</> for the best Kubernetes browsing experience.');
        $this->newLine();

        if (! confirm('Install k9s now?', true)) {
            $manual = 'https://k9scli.io/topics/install/';
            $this->line("  <fg=gray>Skipped — install it later: {$manual}</>");

            return false;
        }

        if (! $this->installK9s()) {
            return false;
        }

        // Managed install goes to ~/.larakube/bin/k9s — no shell restart needed.
        if ($this->resolveK9sBin() !== null) {
            $this->laraKubeInfo('k9s installed successfully.');

            return true;
        }

        // brew/snap may not be on the current shell's PATH hash yet.
        $this->laraKubeWarn('k9s installed — open a new terminal and re-run `larakube k9s`.');

        return false;
    }

    /**
     * Install k9s via GitHub releases into ~/.larakube/bin (no sudo, no snap).
     * This is the most stable approach for Linux/WSL2 — avoids snap which is
     * often unavailable or broken on WSL2 and doesn't require adding repos.
     */
    protected function installK9s(): bool
    {
        $version = 'v0.32.5';
        $machine = php_uname('m');
        $arch = in_array($machine, ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64';
        $os = 'linux';

        if ($this->isDarwin()) {
            // macOS: prefer Homebrew (familiar, keeps things up-to-date).
            $brew = trim((string) shell_exec('command -v brew 2>/dev/null'));
            if ($brew === '') {
                $this->warn('  Homebrew not found. Install it from https://brew.sh then run: brew install k9s');

                return false;
            }

            $this->laraKubeInfo('Installing k9s via Homebrew...');
            passthru('brew install k9s', $code);

            return $code === 0;
        }

        if ($this->isLinux()) {
            $where = $this->isWsl() ? 'WSL2' : 'Linux';
            $binDir = home_path('.larakube/bin');
            $bin = $binDir.'/k9s';
            @mkdir($binDir, 0755, true);

            $label = "k9s {$version}";
            $this->laraKubeInfo("Installing {$label} for {$where}...");

            $url = "https://github.com/derailed/k9s/releases/download/{$version}/k9s_{$os}_{$arch}.tar.gz";
            exec('curl -fsSL '.escapeshellarg($url).' | tar -xz -C '.escapeshellarg($binDir).' k9s 2>/dev/null', $_, $code);

            if ($code !== 0 || ! is_file($bin) || ! is_executable($bin)) {
                $this->laraKubeWarn("Failed to download {$label}.");
                $this->laraKubeLine('  👉 Install manually: https://k9scli.io/topics/install/');

                return false;
            }

            @chmod($bin, 0755);

            // Verify the binary runs and reports the expected version.
            $verOut = (string) shell_exec(escapeshellarg($bin).' version --short 2>/dev/null');
            if (str_contains(strtolower($verOut), strtolower(str_replace('v', '', $version)))) {
                $this->laraKubeInfo("✅ k9s ready at {$bin}");

                return true;
            }

            @unlink($bin);
            $this->laraKubeWarn("Installed {$label} but binary didn't match — reinstalling failed.");

            return false;
        }

        $this->warn('  k9s install not supported on this platform. See https://k9scli.io/topics/install/');

        return false;
    }
}
