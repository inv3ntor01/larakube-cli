<?php

namespace App\Traits;

trait ManagesLocalCa
{
    protected function getLocalCaDir(): string
    {
        return ((string) (getenv('HOME') ?: sys_get_temp_dir())).'/.larakube/certificates';
    }

    protected function getLocalCaCertPath(): string
    {
        return $this->getLocalCaDir().'/ca.crt';
    }

    protected function getLocalCaKeyPath(): string
    {
        return $this->getLocalCaDir().'/ca.key';
    }

    protected function getAppCertsDir(): string
    {
        return $this->getLocalCaDir();
    }

    protected function getAppCertPath(string $appName): string
    {
        return $this->getAppCertsDir()."/{$appName}-dev.crt";
    }

    protected function getAppKeyPath(string $appName): string
    {
        return $this->getAppCertsDir()."/{$appName}-dev.key";
    }

    protected function getSystemCertPath(): string
    {
        return $this->getAppCertsDir().'/system-dev.crt';
    }

    protected function getSystemKeyPath(): string
    {
        return $this->getAppCertsDir().'/system-dev.key';
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
            .' -subj "/CN=LaraKube Local CA/O=LaraKube" 2>/dev/null',
        );
    }

    /**
     * Ensure the per-app cert exists for {appName}.kube + *.{appName}.kube.
     * Regenerates if missing, expiring within 30 days, or covering the wrong TLD
     * (e.g. an old .dev.test cert from Phase 1 that needs upgrading to .kube).
     */
    protected function ensureAppCertExists(string $appName): void
    {
        $this->ensureLocalCaExists();
        @mkdir($this->getAppCertsDir(), 0700, true);

        $crt = $this->getAppCertPath($appName);
        $key = $this->getAppKeyPath($appName);

        if (file_exists($crt) && file_exists($key)
            && $this->certIsValid($crt)
            && $this->certCoversHost($crt, "{$appName}.kube")) {
            return;
        }

        $this->generateAppCert($appName);
    }

    /**
     * Ensure the system cert exists covering console.kube, traefik.kube, and
     * future global companion hosts. Regenerates if missing, expiring, or covering
     * the wrong TLD.
     */
    protected function ensureSystemCertExists(): void
    {
        $this->ensureLocalCaExists();
        @mkdir($this->getAppCertsDir(), 0700, true);

        $crt = $this->getSystemCertPath();
        $key = $this->getSystemKeyPath();

        if (file_exists($crt) && file_exists($key)
            && $this->certIsValid($crt)
            && $this->certCoversHost($crt, 'console.kube')) {
            return;
        }

        $this->generateSystemCert();
    }

    protected function generateAppCert(string $appName): void
    {
        $dir = $this->getAppCertsDir();
        $csr = "{$dir}/{$appName}-dev.csr";
        $cnf = "{$dir}/{$appName}-dev.cnf";

        $cnfContent = <<<CNF
[req]
distinguished_name = req_distinguished_name
req_extensions     = v3_req
prompt             = no

[req_distinguished_name]
CN = {$appName}.kube

[v3_req]
basicConstraints = CA:FALSE
keyUsage         = nonRepudiation, digitalSignature, keyEncipherment
subjectAltName   = DNS:{$appName}.kube,DNS:*.{$appName}.kube
CNF;

        $this->writeCert($cnf, $cnfContent, $csr, $this->getAppKeyPath($appName), $this->getAppCertPath($appName));
    }

    protected function generateSystemCert(): void
    {
        $dir = $this->getAppCertsDir();
        $csr = "{$dir}/system-dev.csr";
        $cnf = "{$dir}/system-dev.cnf";

        $sans = implode(',', array_map(
            fn ($h) => "DNS:{$h}",
            ['console.kube', 'traefik.kube', 'phpmyadmin.kube', 'mailpit.kube', 'redisinsight.kube'],
        ));

        $cnfContent = <<<CNF
[req]
distinguished_name = req_distinguished_name
req_extensions     = v3_req
prompt             = no

[req_distinguished_name]
CN = console.kube

[v3_req]
basicConstraints = CA:FALSE
keyUsage         = nonRepudiation, digitalSignature, keyEncipherment
subjectAltName   = {$sans}
CNF;

        $this->writeCert($cnf, $cnfContent, $csr, $this->getSystemKeyPath(), $this->getSystemCertPath());
    }

    /**
     * Build the Traefik tls.certificates YAML listing all known local certs.
     */
    protected function buildTraefikCertsYml(): string
    {
        $certs = $this->getAllLocalAppCerts();
        $lines = [
            'tls:',
            '  stores:',
            '    default:',
            '      defaultCertificate:',
            '        certFile: /certs/system-dev.pem',
            '        keyFile: /certs/system-dev-key.pem',
            '  certificates:',
            '    - certFile: /certs/system-dev.pem',
            '      keyFile: /certs/system-dev-key.pem',
            '      stores:',
            '        - default',
        ];

        foreach (array_keys($certs) as $appName) {
            $lines[] = "    - certFile: /certs/{$appName}-dev.pem";
            $lines[] = "      keyFile: /certs/{$appName}-dev-key.pem";
            $lines[] = '      stores:';
            $lines[] = '        - default';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Returns all locally-generated per-app cert pairs: [appName => [crt, key]].
     *
     * @return array<string, array{crt: string, key: string}>
     */
    protected function getAllLocalAppCerts(): array
    {
        $dir = $this->getAppCertsDir();
        $certs = [];

        foreach ((array) glob("{$dir}/*-dev.crt") as $crtPath) {
            $crtPath = (string) $crtPath;
            $baseName = basename($crtPath, '-dev.crt');
            if ($baseName === 'system') {
                continue;
            }
            $keyPath = "{$dir}/{$baseName}-dev.key";
            if (file_exists($keyPath)) {
                $certs[$baseName] = ['crt' => $crtPath, 'key' => $keyPath];
            }
        }

        return $certs;
    }

    protected function certIsValid(string $crtPath): bool
    {
        $expiry = shell_exec('openssl x509 -enddate -noout -in '.escapeshellarg($crtPath).' 2>/dev/null');
        if (! $expiry) {
            return false;
        }
        $expiryTs = strtotime(str_replace('notAfter=', '', trim($expiry)));

        return $expiryTs !== false && $expiryTs > time() + (30 * 86400);
    }

    protected function certCoversHost(string $crtPath, string $host): bool
    {
        $text = shell_exec('openssl x509 -text -noout -in '.escapeshellarg($crtPath).' 2>/dev/null');

        return $text !== null && str_contains($text, $host);
    }

    private function writeCert(string $cnf, string $cnfContent, string $csr, string $keyPath, string $crtPath): void
    {
        file_put_contents($cnf, $cnfContent);

        exec('openssl genrsa -out '.escapeshellarg($keyPath).' 2048 2>/dev/null');
        exec('openssl req -new -key '.escapeshellarg($keyPath).' -out '.escapeshellarg($csr).' -config '.escapeshellarg($cnf).' 2>/dev/null');
        exec(
            'openssl x509 -req'
            .' -in '.escapeshellarg($csr)
            .' -CA '.escapeshellarg($this->getLocalCaCertPath())
            .' -CAkey '.escapeshellarg($this->getLocalCaKeyPath())
            .' -CAcreateserial'
            .' -out '.escapeshellarg($crtPath)
            .' -days 825 -sha256'
            .' -extfile '.escapeshellarg($cnf).' -extensions v3_req 2>/dev/null',
        );

        @unlink($cnf);
        @unlink($csr);
    }
}
