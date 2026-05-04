<?php

namespace App\Traits;

trait InteractsWithSslTrust
{
    /**
     * Check if the LaraKube Local CA is already trusted by the system.
     */
    protected function isSslTrusted(): bool
    {
        // Safety Guard: Don't run inside a container
        if (file_exists('/.dockerenv')) {
            return false;
        }

        $os = PHP_OS_FAMILY;
        $isWsl = @file_exists('/proc/version') && str_contains(file_get_contents('/proc/version'), 'Microsoft');

        if ($isWsl) {
            $output = shell_exec('certutil.exe -verifystore Root "Server Side Up CA" 2>&1');

            return str_contains($output, 'Certificate is valid') || str_contains($output, 'CertUtil: -verifystore command completed successfully');
        }

        if ($os === 'Darwin') {
            $output = shell_exec('security find-certificate -c "Server Side Up CA" 2>/dev/null');

            return ! empty($output);
        }

        if ($os === 'Linux') {
            // Check common locations for the CA file we install
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
