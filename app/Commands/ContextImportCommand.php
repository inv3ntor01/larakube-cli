<?php

namespace App\Commands;

use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class ContextImportCommand extends Command
{
    use LaraKubeOutput;

    protected $signature = 'context:import {file : Path to the kubeconfig you were given (e.g. lloyd.kubeconfig)}';

    protected $description = 'Import a kubeconfig (from `cluster:grant`) into your ~/.kube/config and switch to it';

    public function handle(): int
    {
        $this->renderHeader();

        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->laraKubeError("Kubeconfig file not found: {$file}");

            return 1;
        }

        $home = $_SERVER['HOME'] ?? getenv('HOME');
        $local = $home.'/.kube/config';

        // Which context are we importing? (Deterministic name → re-import is idempotent.)
        $incoming = trim((string) shell_exec('kubectl config view --kubeconfig='.escapeshellarg($file).' -o jsonpath='.escapeshellarg('{.current-context}').' 2>/dev/null'));

        if (file_exists($local)) {
            copy($local, $local.'.bak.'.time());

            // Merge with kubectl's own flatten engine — same-named entries are
            // overwritten (so re-importing the same file is a no-op refresh).
            $merged = shell_exec('KUBECONFIG='.escapeshellarg($local).':'.escapeshellarg($file).' kubectl config view --flatten 2>/dev/null');
            if ($merged === null || trim($merged) === '') {
                $this->laraKubeError('Failed to merge the kubeconfig. Is kubectl installed?');

                return 1;
            }
            file_put_contents($local, $merged);
        } else {
            if (! is_dir(dirname($local))) {
                mkdir(dirname($local), 0700, true);
            }
            copy($file, $local);
        }

        if ($incoming !== '') {
            shell_exec('kubectl config use-context '.escapeshellarg($incoming).' 2>/dev/null');
            $this->laraKubeInfo("✅ Imported — you're now on context '{$incoming}'.");
        } else {
            $this->laraKubeInfo('✅ Kubeconfig imported.');
        }

        $this->line('  <fg=gray>Try:</> <fg=yellow>kubectl get pods</>  <fg=gray>· switch later with</> <fg=yellow>larakube context</>');

        return 0;
    }
}
