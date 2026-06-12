<?php

namespace App\Commands;

use App\Traits\DetectsWsl;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesLocalCa;
use LaravelZero\Framework\Commands\Command;

class UntrustCommand extends Command
{
    use DetectsWsl, LaraKubeOutput, ManagesLocalCa;

    protected $signature = 'untrust';

    protected $description = 'Remove the LaraKube Local CA from your system trust store';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Local HTTPS Cleanup');

        if (file_exists('/.dockerenv')) {
            $this->error('  ✖ The "untrust" command must be run directly on your HOST (or WSL2) machine.');

            return 1;
        }

        $os = PHP_OS_FAMILY;

        if ($this->isWsl()) {
            $this->info('  🪟 WSL2 detected. Removing from Windows Root Store...');
            passthru('certutil.exe -delstore "Root" "LaraKube Local CA"');
        } elseif ($os === 'Darwin') {
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

        $this->line('');
        $this->laraKubeInfo('✅ LaraKube Local CA removed. Restart your browser to apply.');

        return 0;
    }
}
