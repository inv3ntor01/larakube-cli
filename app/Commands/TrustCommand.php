<?php

namespace App\Commands;

use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class TrustCommand extends Command
{
    use LaraKubeOutput;

    protected $signature = 'trust';

    protected $description = 'Install the LaraKube Local CA into your system trust store for seamless HTTPS';

    public function handle()
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Local HTTPS Setup');

        // Safety Guard: Don't run inside a container
        if (file_exists('/.dockerenv')) {
            $this->error('  ✖ The "trust" command must be run directly on your HOST machine.');
            $this->info('    This command interacts with your system Keychain/Trust Store.');

            return 1;
        }

        $os = PHP_OS_FAMILY;
        $caPath = base_path('resources/stubs/traefik/dev/certificates/local-ca.pem');

        if (! file_exists($caPath)) {
            $this->error('  ✖ Local CA certificate not found in stubs.');

            return 1;
        }

        // Copy to temp file because system commands cannot read from inside a PHAR
        $tempCa = tempnam(sys_get_temp_dir(), 'larakube-ca');
        file_put_contents($tempCa, file_get_contents($caPath));

        if ($os === 'Darwin') {
            $this->info('  🔒 macOS detected. Installing to System Keychain...');
            $command = "sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain \"{$tempCa}\"";
            passthru($command);
        } elseif ($os === 'Linux') {
            $this->info('  🔒 Linux detected. Installing to ca-certificates...');
            $target = '/usr/local/share/ca-certificates/larakube-local-ca.crt';
            passthru("sudo cp \"{$tempCa}\" \"{$target}\"");
            passthru('sudo update-ca-certificates');
        } else {
            $this->warn("  ⚠ Automatic trust installation is not supported for {$os}.");
            $this->info('  👉 Manually install this certificate to trust *.dev.test:');
            $this->line("     {$caPath}");
            @unlink($tempCa);

            return 0;
        }

        @unlink($tempCa);

        $this->line('');
        $this->laraKubeInfo('✅ LaraKube Local CA is now trusted!');
        $this->info('Restart your browser to apply the changes.');

        return 0;
    }
}
