<?php

namespace App\Traits;

trait InteractsWithSslTrust
{
    use DetectsWsl, InteractsWithOs;

    /**
     * Check if the LaraKube Local CA is already trusted by the system.
     */
    protected function isSslTrusted(): bool
    {
        if (file_exists('/.dockerenv')) {
            return false;
        }

        if ($this->isWsl()) {
            $output = shell_exec('certutil.exe -verifystore Root "LaraKube Local CA" 2>&1');

            return str_contains((string) $output, 'Certificate is valid') || str_contains((string) $output, 'CertUtil: -verifystore command completed successfully');
        }

        if ($this->isDarwin()) {
            $output = shell_exec('security find-certificate -c "LaraKube Local CA" 2>/dev/null');

            return ! empty($output);
        }

        if ($this->isLinux()) {
            $paths = [
                '/usr/local/share/ca-certificates/larakube-local-ca.crt',
                '/etc/pki/ca-trust/source/anchors/larakube-local-ca.crt',
            ];

            foreach ($paths as $path) {
                if (file_exists($path)) {
                    return true;
                }
            }
        }

        return false;
    }
}
