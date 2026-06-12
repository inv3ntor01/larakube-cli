<?php

namespace App\Traits;

use function Laravel\Prompts\confirm;

trait InteractsWithTrust
{
    use DetectsWsl, LaraKubeOutput, ManagesLocalCa;

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
                $this->line('  👉 In a Windows terminal (no admin needed) run:');
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

    protected function removeCaFromKeychain(): void
    {
        $os = PHP_OS_FAMILY;

        if ($this->isWsl()) {
            $this->info('  🪟 WSL2 detected. Removing from Windows Root Store...');
            passthru('certutil.exe -delstore "Root" "LaraKube Local CA"');

            return;
        }

        if ($os === 'Darwin') {
            $this->info('  🔓 macOS detected. Removing from System Keychain...');
            $caPath = $this->getLocalCaCertPath();

            if (file_exists($caPath)) {
                $tmpCa = (string) tempnam(sys_get_temp_dir(), 'larakube-untrust');
                file_put_contents($tmpCa, file_get_contents($caPath));
                $fingerprint = trim((string) shell_exec("openssl x509 -noout -fingerprint -sha1 -in {$tmpCa} | cut -d'=' -f2 | sed 's/://g'"));
                @unlink($tmpCa);

                if ($fingerprint) {
                    passthru("sudo security delete-certificate -Z {$fingerprint} /Library/Keychains/System.keychain 2>/dev/null || sudo security delete-certificate -c \"LaraKube Local CA\" /Library/Keychains/System.keychain");
                } else {
                    passthru('sudo security delete-certificate -c "LaraKube Local CA" /Library/Keychains/System.keychain');
                }
            } else {
                passthru('sudo security delete-certificate -c "LaraKube Local CA" /Library/Keychains/System.keychain');
            }
        } elseif ($os === 'Linux') {
            if (file_exists('/usr/local/share/ca-certificates/larakube-local-ca.crt')) {
                $this->info('  🔓 Linux (Debian/Ubuntu) detected. Removing ca-certificate...');
                passthru('sudo rm -f /usr/local/share/ca-certificates/larakube-local-ca.crt');
                passthru('sudo update-ca-certificates --fresh');
            } elseif (file_exists('/etc/pki/ca-trust/source/anchors/larakube-local-ca.crt')) {
                $this->info('  🔓 Linux (Fedora/RHEL) detected. Removing ca-trust...');
                passthru('sudo rm -f /etc/pki/ca-trust/source/anchors/larakube-local-ca.crt');
                passthru('sudo update-ca-trust extract');
            }
        } else {
            $this->warn("  ⚠ Automatic trust removal is not supported for {$os}.");
        }
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
        // bind-interfaces + listen-address=127.0.0.1 prevents dnsmasq from trying to
        // bind on OrbStack/Docker bridge interfaces (which fail with Permission denied).
        // Port 53 requires root; we start the service via sudo below.
        $conf = "listen-address=127.0.0.1\nbind-interfaces\naddress=/.kube/127.0.0.1\n";

        if ($os === 'Darwin') {
            $brewPrefix = trim((string) shell_exec('brew --prefix 2>/dev/null')) ?: '/usr/local';
            $confDir = $brewPrefix.'/etc/dnsmasq.d';
            @mkdir($confDir, 0755, true);
            file_put_contents($confDir.'/larakube.conf', $conf);

            passthru('sudo mkdir -p /etc/resolver');
            $tmpResolver = (string) tempnam(sys_get_temp_dir(), 'lk-resolver');
            file_put_contents($tmpResolver, "nameserver 127.0.0.1\n");
            passthru('sudo cp '.escapeshellarg($tmpResolver).' /etc/resolver/kube');
            @unlink($tmpResolver);

            // Stop any user-level instance first (can't bind port 53 without root).
            // Suppress output — it's fine if there's nothing to stop.
            shell_exec('brew services stop dnsmasq 2>/dev/null');
            // Must run as root so dnsmasq can bind port 53.
            passthru('sudo brew services restart dnsmasq');
        } elseif ($os === 'Linux') {
            $tmpConf = (string) tempnam(sys_get_temp_dir(), 'lk-dnsmasq');
            file_put_contents($tmpConf, $conf);
            passthru('sudo mkdir -p /etc/dnsmasq.d');
            passthru('sudo cp '.escapeshellarg($tmpConf).' /etc/dnsmasq.d/larakube.conf');
            @unlink($tmpConf);
            passthru('sudo systemctl restart dnsmasq');
        }

        $this->laraKubeInfo('dnsmasq configured: *.kube → 127.0.0.1');
    }

    protected function isCaTrusted(): bool
    {
        $os = PHP_OS_FAMILY;

        if ($this->isWsl()) {
            $output = shell_exec('certutil.exe -user -verifystore Root "LaraKube Local CA" 2>/dev/null');

            return $output !== null && str_contains((string) $output, 'LaraKube Local CA');
        }

        if ($os === 'Darwin') {
            return ! empty(shell_exec('security find-certificate -c "LaraKube Local CA" /Library/Keychains/System.keychain 2>/dev/null'));
        }

        if ($os === 'Linux') {
            return file_exists('/usr/local/share/ca-certificates/larakube-local-ca.crt')
                || file_exists('/etc/pki/ca-trust/source/anchors/larakube-local-ca.crt');
        }

        return false;
    }

    protected function isDnsmasqConfigured(): bool
    {
        $os = PHP_OS_FAMILY;

        if ($os === 'Darwin') {
            $brewPrefix = trim((string) shell_exec('brew --prefix 2>/dev/null')) ?: '/usr/local';

            if (! file_exists($brewPrefix.'/etc/dnsmasq.d/larakube.conf') || ! file_exists('/etc/resolver/kube')) {
                return false;
            }
        } elseif ($os === 'Linux') {
            if (! file_exists('/etc/dnsmasq.d/larakube.conf')) {
                return false;
            }
        } else {
            return false;
        }

        // Files exist — verify dnsmasq is actually running by probing DNS.
        $result = shell_exec('dscacheutil -q host -a name larakube-probe.kube 2>/dev/null');
        if ($os === 'Darwin') {
            return $result !== null && str_contains((string) $result, '127.0.0.1');
        }

        // Linux: use getent which respects /etc/resolv.conf + nsswitch
        $result = shell_exec('getent hosts larakube-probe.kube 2>/dev/null');

        return $result !== null && str_contains((string) $result, '127.0.0.1');

        return false;
    }
}
