<?php

namespace App\Traits;

trait ManagesLocalCa
{
    protected function getLocalCaDir(): string
    {
        return ((string) (getenv('HOME') ?: sys_get_temp_dir())).'/.larakube';
    }

    protected function getLocalCaCertPath(): string
    {
        return $this->getLocalCaDir().'/ca.crt';
    }

    protected function getLocalCaKeyPath(): string
    {
        return $this->getLocalCaDir().'/ca.key';
    }

    protected function getLocalDevCertPath(): string
    {
        return $this->getLocalCaDir().'/local-dev.crt';
    }

    protected function getLocalDevKeyPath(): string
    {
        return $this->getLocalCaDir().'/local-dev.key';
    }

    protected function localCaExists(): bool
    {
        return file_exists($this->getLocalCaKeyPath()) && file_exists($this->getLocalCaCertPath());
    }

    /**
     * Generate the persistent LaraKube Local CA at ~/.larakube/ if it does not already exist.
     */
    protected function ensureLocalCaExists(): void
    {
        if ($this->localCaExists()) {
            return;
        }

        @mkdir($this->getLocalCaDir(), 0700, true);

        exec('openssl genrsa -out '.escapeshellarg($this->getLocalCaKeyPath()).' 4096 2>/dev/null');
        exec(
            'openssl req -x509 -new -nodes'
            .' -key '.escapeshellarg($this->getLocalCaKeyPath())
            .' -sha256 -days 3650'
            .' -out '.escapeshellarg($this->getLocalCaCertPath())
            .' -subj "/CN=LaraKube Local CA/O=LaraKube" 2>/dev/null'
        );
    }

    /**
     * Ensure the wildcard dev cert exists and is signed by the local CA.
     * Covers *.dev.test. Regenerates automatically if missing or expiring within 30 days.
     */
    protected function ensureLocalDevCertExists(): void
    {
        $this->ensureLocalCaExists();

        $crt = $this->getLocalDevCertPath();
        $key = $this->getLocalDevKeyPath();

        if (file_exists($crt) && file_exists($key)) {
            $expiry = shell_exec('openssl x509 -enddate -noout -in '.escapeshellarg($crt).' 2>/dev/null');
            if ($expiry) {
                $expiryTs = strtotime(str_replace('notAfter=', '', trim($expiry)));
                if ($expiryTs !== false && $expiryTs > time() + (30 * 86400)) {
                    return;
                }
            }
        }

        $this->generateLocalDevCert();
    }

    protected function generateLocalDevCert(): void
    {
        $dir = $this->getLocalCaDir();
        $csr = $dir.'/local-dev.csr';
        $cnf = $dir.'/local-dev.cnf';

        $cnfContent = <<<CNF
[req]
distinguished_name = req_distinguished_name
req_extensions     = v3_req
prompt             = no

[req_distinguished_name]
CN = *.dev.test

[v3_req]
basicConstraints = CA:FALSE
keyUsage         = nonRepudiation, digitalSignature, keyEncipherment
subjectAltName   = DNS:*.dev.test,DNS:dev.test
CNF;

        file_put_contents($cnf, $cnfContent);

        exec('openssl genrsa -out '.escapeshellarg($this->getLocalDevKeyPath()).' 2048 2>/dev/null');
        exec('openssl req -new -key '.escapeshellarg($this->getLocalDevKeyPath()).' -out '.escapeshellarg($csr).' -config '.escapeshellarg($cnf).' 2>/dev/null');
        exec(
            'openssl x509 -req'
            .' -in '.escapeshellarg($csr)
            .' -CA '.escapeshellarg($this->getLocalCaCertPath())
            .' -CAkey '.escapeshellarg($this->getLocalCaKeyPath())
            .' -CAcreateserial'
            .' -out '.escapeshellarg($this->getLocalDevCertPath())
            .' -days 825 -sha256'
            .' -extfile '.escapeshellarg($cnf).' -extensions v3_req 2>/dev/null'
        );

        @unlink($cnf);
        @unlink($csr);
    }
}
