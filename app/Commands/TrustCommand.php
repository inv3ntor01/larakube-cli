<?php

namespace App\Commands;

use App\Traits\DetectsWsl;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesLocalCa;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class TrustCommand extends Command
{
    use DetectsWsl, LaraKubeOutput, ManagesLocalCa;

    protected $signature = 'trust {ca-file? : Path to a CA file to trust (e.g. from an air-gapped bundle)}';

    protected $description = 'Install the LaraKube Local CA into your system trust store for seamless HTTPS';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Local HTTPS Setup');

        if (file_exists('/.dockerenv')) {
            $this->error('  ✖ The "trust" command must be run directly on your HOST (or WSL2) machine.');

            return 1;
        }

        $customCa = $this->argument('ca-file');

        if ($customCa) {
            if (! file_exists($customCa)) {
                $this->error("  ✖ CA file not found: {$customCa}");

                return 1;
            }
            $this->info("  📦 Trusting custom CA from {$customCa}");

            return $this->installCaToKeychain($customCa);
        }

        $this->info('  🔑 Generating persistent LaraKube Local CA (stored at ~/.larakube/)...');
        $this->ensureLocalCaExists();
        $this->info('  ✅ CA ready.');

        $result = $this->installCaToKeychain($this->getLocalCaCertPath());
        if ($result !== 0) {
            return $result;
        }

        $this->setupDnsmasq();

        $this->line('');
        $this->laraKubeInfo('✅ LaraKube Local CA is trusted! Restart your browser to apply.');

        return 0;
    }

    protected function installCaToKeychain(string $caPath): int
    {
        $tmpCa = (string) tempnam(sys_get_temp_dir(), 'larakube-ca');
        file_put_contents($tmpCa, file_get_contents($caPath));

        $os = PHP_OS_FAMILY;

        if ($this->isWsl()) {
            $home = (string) (getenv('HOME') ?: sys_get_temp_dir());
            @mkdir($home.'/.larakube', 0755, true);
            $caFile = $home.'/.larakube/larakube-local-ca.crt';
            file_put_contents($caFile, file_get_contents($caPath));
            @unlink($tmpCa);

            $winPath = trim((string) shell_exec('wslpath -w '.escapeshellarg($caFile).' 2>/dev/null'));
            passthru('certutil.exe -user -addstore -f "Root" "'.$winPath.'" 2>/dev/null', $code);

            $this->line('');
            if ($code !== 0) {
                $this->laraKubeWarn('Could not add the CA to the Windows user store automatically.');
                $this->line("  👉 In a Windows terminal (no admin needed) run:");
                $this->line("       certutil -user -addstore -f Root \"{$winPath}\"");
                $this->line('     …or double-click that .crt → Install Certificate → Current User → Trusted Root Certification Authorities.');

                return 1;
            }

            $this->laraKubeInfo('✅ CA trusted (Windows current-user store). Restart your browser.');
            $this->line('  <fg=gray>Firefox uses its own trust store — import the CA there separately if needed.</>');

            return 0;
        }

        if ($os === 'Darwin') {
            $this->info('  🔒 Installing to macOS System Keychain...');
            passthru('sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain '.escapeshellarg($tmpCa));
        } elseif ($os === 'Linux') {
            if (file_exists('/usr/local/share/ca-certificates/')) {
                $this->info('  🔒 Installing to ca-certificates (Debian/Ubuntu)...');
                passthru('sudo cp '.escapeshellarg($tmpCa).' /usr/local/share/ca-certificates/larakube-local-ca.crt');
                passthru('sudo update-ca-certificates');
            } elseif (file_exists('/etc/pki/ca-trust/source/anchors/')) {
                $this->info('  🔒 Installing to ca-trust (Fedora/RHEL)...');
                passthru('sudo cp '.escapeshellarg($tmpCa).' /etc/pki/ca-trust/source/anchors/larakube-local-ca.crt');
                passthru('sudo update-ca-trust extract');
            }
        } else {
            $this->warn("  ⚠ Automatic trust installation not supported for {$os}.");
            $this->info("  👉 Manually trust: {$caPath}");
        }

        @unlink($tmpCa);

        return 0;
    }

    protected function setupDnsmasq(): void
    {
        $dnsmasqBin = trim((string) shell_exec('which dnsmasq 2>/dev/null'));

        if ($dnsmasqBin === '') {
            $this->newLine();
            $this->line('  <fg=yellow>💡 Optional:</> Install <fg=cyan>dnsmasq</> for automatic wildcard DNS resolution.');
            $this->line('  <fg=gray>Without it, larakube up manages /etc/hosts entries for each hostname.</>');
            $this->newLine();

            if (! confirm('Install dnsmasq now?', false)) {
                $this->line('  <fg=gray>Skipped — larakube up will manage /etc/hosts for you.</>');

                return;
            }

            if (! $this->installDnsmasq()) {
                return;
            }
        }

        $this->configureDnsmasq();
    }

    protected function installDnsmasq(): bool
    {
        $os = PHP_OS_FAMILY;

        if ($os === 'Darwin') {
            $brewBin = trim((string) shell_exec('which brew 2>/dev/null'));
            if ($brewBin === '') {
                $this->warn('  Homebrew not found. Install it from https://brew.sh then run: brew install dnsmasq');

                return false;
            }
            passthru('brew install dnsmasq', $code);

            return $code === 0;
        }

        if ($os === 'Linux') {
            if (file_exists('/usr/bin/apt-get')) {
                passthru('sudo apt-get install -y dnsmasq', $code);
            } elseif (file_exists('/usr/bin/dnf')) {
                passthru('sudo dnf install -y dnsmasq', $code);
            } else {
                $this->warn('  Could not detect package manager. Install dnsmasq manually.');

                return false;
            }

            return $code === 0;
        }

        $this->warn('  dnsmasq install not supported on this platform.');

        return false;
    }

    protected function configureDnsmasq(): void
    {
        $os = PHP_OS_FAMILY;
        $conf = "address=/.dev.test/127.0.0.1\n";

        if ($os === 'Darwin') {
            $brewPrefix = trim((string) shell_exec('brew --prefix 2>/dev/null')) ?: '/usr/local';
            $confDir = $brewPrefix.'/etc/dnsmasq.d';
            @mkdir($confDir, 0755, true);
            file_put_contents($confDir.'/larakube.conf', $conf);

            // Tell macOS to route .dev.test queries through dnsmasq
            passthru('sudo mkdir -p /etc/resolver');
            $tmpResolver = (string) tempnam(sys_get_temp_dir(), 'lk-resolver');
            file_put_contents($tmpResolver, "nameserver 127.0.0.1\n");
            passthru('sudo cp '.escapeshellarg($tmpResolver).' /etc/resolver/dev.test');
            @unlink($tmpResolver);

            passthru('brew services restart dnsmasq');
        } elseif ($os === 'Linux') {
            $tmpConf = (string) tempnam(sys_get_temp_dir(), 'lk-dnsmasq');
            file_put_contents($tmpConf, $conf);
            passthru('sudo mkdir -p /etc/dnsmasq.d');
            passthru('sudo cp '.escapeshellarg($tmpConf).' /etc/dnsmasq.d/larakube.conf');
            @unlink($tmpConf);
            passthru('sudo systemctl restart dnsmasq');
        }

        $this->laraKubeInfo('dnsmasq configured: *.dev.test → 127.0.0.1');
    }
}
