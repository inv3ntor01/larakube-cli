<?php

namespace App\Traits;

trait GeneratesOfflineCertificates
{
    /**
     * Generates a local Certificate Authority (CA) and a server certificate
     * that covers all provided domain names (SANs). Returns the paths to the
     * generated files so they can be injected into Kubernetes.
     *
     * @param  array<string>  $domains
     * @return array{ca_crt: string, tls_crt: string, tls_key: string}
     */
    public function generateSanCertificates(array $domains, string $outputDir): array
    {
        $caKey = "$outputDir/ca.key";
        $caCrt = "$outputDir/ca.crt";
        $serverKey = "$outputDir/tls.key";
        $serverCsr = "$outputDir/tls.csr";
        $serverCrt = "$outputDir/tls.crt";
        $cnfFile = "$outputDir/openssl.cnf";

        // Filter out empty domains and format for SANs
        $domains = array_values(array_filter($domains));
        $primaryDomain = $domains[0] ?? 'larakube.internal';

        $sanList = implode(',', array_map(fn ($d) => "DNS:$d", $domains));

        $cnfContent = <<<CNF
[req]
distinguished_name = req_distinguished_name
req_extensions = v3_req
prompt = no

[req_distinguished_name]
CN = {$primaryDomain}

[v3_req]
basicConstraints = CA:FALSE
keyUsage = nonRepudiation, digitalSignature, keyEncipherment
subjectAltName = {$sanList}
CNF;

        file_put_contents($cnfFile, $cnfContent);

        // 1. Generate CA Key and Cert
        exec('openssl genrsa -out '.escapeshellarg($caKey).' 2048 2>/dev/null');
        exec('openssl req -x509 -new -nodes -key '.escapeshellarg($caKey).' -sha256 -days 3650 -out '.escapeshellarg($caCrt).' -subj "/CN=LaraKube Airgap CA" 2>/dev/null');

        // 2. Generate Server Key
        exec('openssl genrsa -out '.escapeshellarg($serverKey).' 2048 2>/dev/null');

        // 3. Generate CSR using the SAN config
        exec('openssl req -new -key '.escapeshellarg($serverKey).' -out '.escapeshellarg($serverCsr).' -config '.escapeshellarg($cnfFile).' -extensions v3_req 2>/dev/null');

        // 4. Sign the CSR with the CA to generate the Server Cert
        exec('openssl x509 -req -in '.escapeshellarg($serverCsr).' -CA '.escapeshellarg($caCrt).' -CAkey '.escapeshellarg($caKey).' -CAcreateserial -out '.escapeshellarg($serverCrt).' -days 3650 -sha256 -extfile '.escapeshellarg($cnfFile).' -extensions v3_req 2>/dev/null');

        return [
            'ca_crt' => $caCrt,
            'tls_crt' => $serverCrt,
            'tls_key' => $serverKey,
        ];
    }
}
