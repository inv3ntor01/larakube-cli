<?php

namespace App\Commands\Trust;

use App\Data\GlobalConfigData;
use App\Traits\InteractsWithTrust;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class ResetCommand extends Command
{
    use InteractsWithTrust, LaraKubeOutput;

    protected $signature = 'trust:reset {--force : Skip confirmation}';

    protected $description = 'Regenerate the local CA and re-install it to the system trust store';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Local CA Reset');
        $this->newLine();

        $this->laraKubeError('WARNING: This will destroy and regenerate your local CA.');
        $this->line('  All per-app certs will be invalid until you run <fg=cyan>larakube up</> in each project.');
        $this->newLine();

        if (! $this->option('force')) {
            $confirm = text(
                label: "Type 'reset' to confirm:",
                required: true,
            );

            if ($confirm !== 'reset') {
                $this->laraKubeInfo('Cancelled.');

                return 0;
            }
        }

        // 1. Remove old CA from keychain
        $this->withSpin('Removing old CA from system keychain...', function () {
            $this->removeCaFromKeychain();

            return true;
        });

        // 2. Delete all local CA + cert files
        $this->withSpin('Deleting ~/.larakube/certificates/ ...', function () {
            $caDir = $this->getLocalCaDir();
            $this->deleteDirectory($caDir);

            return true;
        });

        // 3. Regenerate CA
        $this->withSpin('Generating new CA...', function () {
            $this->ensureLocalCaExists();

            return true;
        });

        // 4. Regenerate system cert
        $tld = GlobalConfigData::load()->getLocalTld();
        $this->withSpin("Generating system cert (console.{$tld}, traefik.{$tld}, …)...", function () {
            @mkdir($this->getAppCertsDir(), 0700, true);
            $this->generateSystemCert();

            return true;
        });

        // 5. Re-install CA to keychain
        $result = 0;
        $this->withSpin('Installing new CA to system keychain...', function () use (&$result) {
            $result = $this->installCaToKeychain($this->getLocalCaCertPath());

            return true;
        });

        if ($result !== 0) {
            return $result;
        }

        $this->newLine();
        $this->laraKubeInfo('✅ CA regenerated and trusted.');
        $this->line("  <fg=gray>Per-app certs (e.g. hospital.{$tld}) will be regenerated automatically on the next</>");
        $this->line('  <fg=gray>  larakube up  run in each project.</>');
        $this->newLine();
        $this->line('  Restart your browser to pick up the new CA.');

        return 0;
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach ((array) glob("{$dir}/*") as $item) {
            is_dir((string) $item) ? $this->deleteDirectory((string) $item) : @unlink((string) $item);
        }

        @rmdir($dir);
    }
}
