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
            $this->info('  🪟 WSL2 detected. Installing to Windows Root Store...');
            $winPath = trim(shell_exec("wslpath -w $tempCa"));
            passthru("certutil.exe -addstore -f \"Root\" \"$winPath\"");
        } elseif ($os === 'Darwin') {
            $this->info('  🔒 macOS detected. Installing to System Keychain...');
            passthru("sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain \"{$tempCa}\"");
        } elseif ($os === 'Linux') {
            if (file_exists('/usr/local/share/ca-certificates/')) {
                $this->info('  🔒 Linux (Debian/Ubuntu) detected. Installing to ca-certificates...');
                $target = '/usr/local/share/ca-certificates/larakube-local-ca.crt';
                passthru("sudo cp \"{$tempCa}\" \"{$target}\"");
                passthru('sudo update-ca-certificates');
            } elseif (file_exists('/etc/pki/ca-trust/source/anchors/')) {
                $this->info('  🔒 Linux (Fedora/RHEL) detected. Installing to ca-trust...');
                $target = '/etc/pki/ca-trust/source/anchors/larakube-local-ca.crt';
                passthru("sudo cp \"{$tempCa}\" \"{$target}\"");
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
