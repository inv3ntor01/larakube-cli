<?php

namespace App\Commands;

use App\Traits\DetectsWsl;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class TrustCommand extends Command
{
    use DetectsWsl, LaraKubeOutput;

    protected $signature = 'trust {--refresh : Force download of the latest CA from Server Side Up}';

    protected $description = 'Install the LaraKube Local CA into your system trust store for seamless HTTPS';

    public function handle()
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Local HTTPS Setup');

        // Safety Guard: Don't run inside a container
        if (file_exists('/.dockerenv')) {
            $this->error('  ✖ The "trust" command must be run directly on your HOST (or WSL2) machine.');

            return 1;
        }

        $caPath = base_path('resources/views/traefik/certificates/local-ca.pem');
        $caUrl = 'https://serversideup.net/ca/ssu-ca.pem';
        $useBundled = file_exists($caPath) && ! $this->option('refresh');

        // Check if bundled CA is expired
        if ($useBundled) {
            $expiration = shell_exec("openssl x509 -enddate -noout -in {$caPath} 2>/dev/null");
            if ($expiration) {
                $expiryDate = strtotime(str_replace('notAfter=', '', trim($expiration)));
                if ($expiryDate < time()) {
                    $this->warn('  ⚠ Bundled CA has expired. Attempting to download the latest version...');
                    $useBundled = false;
                }
            }
        }

        if ($useBundled) {
            $this->info('  📦 Using bundled Local CA certificate.');
            $caContent = file_get_contents($caPath);
        } else {
            $this->info('  🌐 Downloading latest Local CA from Server Side Up...');
            $caContent = @file_get_contents($caUrl);

            if (! $caContent) {
                $this->error('  ✖ Failed to download the latest CA certificate. Please check your internet connection.');

                return 1;
            }
        }

        // Copy to temp file because system commands cannot read from inside a PHAR
        $tempCa = tempnam(sys_get_temp_dir(), 'larakube-ca');
        file_put_contents($tempCa, $caContent);

        $os = PHP_OS_FAMILY;

        if ($this->isWsl()) {
            $this->info('  🪟 WSL2 detected. Installing to the Windows current-user trust store...');

            // Persist the CA where Windows can read it (the temp file is unlinked,
            // and the bundled CA lives inside the PHAR), so both certutil and the
            // manual fallback below can reference the same path.
            $home = getenv('HOME') ?: sys_get_temp_dir();
            @mkdir($home.'/.larakube', 0755, true);
            $caFile = $home.'/.larakube/larakube-local-ca.crt';
            file_put_contents($caFile, $caContent);
            @unlink($tempCa);

            $winPath = trim((string) shell_exec('wslpath -w '.escapeshellarg($caFile).' 2>/dev/null'));

            // -user → the CURRENT USER's Root store: trusted by Chrome/Edge, needs
            // NO admin/UAC. This replaces the old `-addstore Root` (machine store)
            // that required elevation and just flashed a window and failed.
            passthru('certutil.exe -user -addstore -f "Root" "'.$winPath.'" 2>/dev/null', $trustCode);

            $this->line('');
            if ($trustCode !== 0) {
                $this->laraKubeWarn('Could not add the CA to the Windows user store automatically.');
                $this->line('  👉 In a Windows terminal (no admin needed) run:');
                $this->line("       certutil -user -addstore -f Root \"{$winPath}\"");
                $this->line('     …or double-click that .crt → Install Certificate → Current User → Trusted Root Certification Authorities.');

                return 1;
            }

            $this->laraKubeInfo('✅ LaraKube Local CA trusted (Windows current-user store). Restart your browser.');
            $this->line('  <fg=gray>Note: Firefox uses its own trust store — import the CA there separately if you use Firefox.</>');

            return 0;
        }

        if ($os === 'Darwin') {
            $this->info('  🔒 macOS detected. Installing to System Keychain...');
            passthru('sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain '.escapeshellarg($tempCa));
        } elseif ($os === 'Linux') {
            if (file_exists('/usr/local/share/ca-certificates/')) {
                $this->info('  🔒 Linux (Debian/Ubuntu) detected. Installing to ca-certificates...');
                $target = '/usr/local/share/ca-certificates/larakube-local-ca.crt';
                passthru('sudo cp '.escapeshellarg($tempCa).' '.escapeshellarg($target));
                passthru('sudo update-ca-certificates');
            } elseif (file_exists('/etc/pki/ca-trust/source/anchors/')) {
                $this->info('  🔒 Linux (Fedora/RHEL) detected. Installing to ca-trust...');
                $target = '/etc/pki/ca-trust/source/anchors/larakube-local-ca.crt';
                passthru('sudo cp '.escapeshellarg($tempCa).' '.escapeshellarg($target));
                passthru('sudo update-ca-trust extract');
            }
        } else {
            $this->warn("  ⚠ Automatic trust installation is not supported for {$os}.");
            $this->info("  👉 Manually install: {$caPath}");
        }

        @unlink($tempCa);
        $this->line('');
        $this->laraKubeInfo('✅ LaraKube Local CA is now trusted!');
        $this->info('Restart your browser to apply the changes.');

        return 0;
    }
}
