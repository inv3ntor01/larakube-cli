<?php

namespace App\Commands;

use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class ContextBackupCommand extends Command
{
    use LaraKubeOutput;

    protected $signature = 'context:backup';

    protected $description = 'Snapshot your ~/.kube/config (run before anything that might rewrite it, e.g. `orb reset`)';

    public function handle(): int
    {
        $this->renderHeader();

        $home = $_SERVER['HOME'] ?? getenv('HOME');
        $config = $home.'/.kube/config';

        if (! is_file($config)) {
            $this->laraKubeWarn('No ~/.kube/config to back up.');

            return 0;
        }

        $dir = $home.'/.larakube/kube-backups';
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        // Date in the filename so you know when it was taken at a glance.
        $dest = $dir.'/config-'.date('Y-m-d_His').'.bak';
        copy($config, $dest);
        @chmod($dest, 0600);

        $this->laraKubeInfo('✅ Backed up your kubeconfig.');
        $this->line('  <fg=cyan>'.$dest.'</>');
        $this->line('  <fg=gray>Restore anytime with</> <fg=yellow>larakube context:restore</>');

        return 0;
    }
}
