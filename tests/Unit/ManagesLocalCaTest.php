<?php

use App\Data\GlobalConfigData;
use App\Traits\ManagesLocalCa;

function localCaHarness(): object
{
    return new class
    {
        use ManagesLocalCa;

        public function ensureCert(string $appName, ?string $tld = null): void
        {
            $this->ensureAppCertExists($appName, $tld);
        }

        public function certPath(string $appName): string
        {
            return $this->getAppCertPath($appName);
        }

        public function tldSidecarPath(string $appName): string
        {
            return $this->getAppCertTldPath($appName);
        }

        public function tldFor(string $appName): string
        {
            return $this->getAppCertTld($appName);
        }

        public function hostCovered(string $crt, string $host): bool
        {
            return $this->certCoversHost($crt, $host);
        }
    };
}

function cleanupLocalCaFor(string $appName): void
{
    $dir = $_SERVER['HOME'].'/.larakube/certificates';
    @unlink("{$dir}/{$appName}-dev.crt");
    @unlink("{$dir}/{$appName}-dev.key");
    @unlink("{$dir}/{$appName}-dev.tld");
}

test('generateAppCert writes a TLD sidecar alongside the cert, covering only that TLD', function () {
    $appName = 'lc-gen-'.uniqid();
    $harness = localCaHarness();

    $harness->ensureCert($appName, 'test');

    expect(file_exists($harness->certPath($appName)))->toBeTrue()
        ->and(file_get_contents($harness->tldSidecarPath($appName)))->toBe('test')
        ->and($harness->tldFor($appName))->toBe('test')
        ->and($harness->hostCovered($harness->certPath($appName), "{$appName}.test"))->toBeTrue()
        ->and($harness->hostCovered($harness->certPath($appName), "{$appName}.kube"))->toBeFalse();

    cleanupLocalCaFor($appName);
});

test('getAppCertTld falls back to the global default when no sidecar exists', function () {
    $appName = 'lc-nosidecar-'.uniqid();
    $harness = localCaHarness();

    expect($harness->tldFor($appName))->toBe(GlobalConfigData::load()->getLocalTld());
});

test('ensureAppCertExists regenerates the cert when the requested TLD changes', function () {
    $appName = 'lc-switch-'.uniqid();
    $harness = localCaHarness();

    $harness->ensureCert($appName, 'kube');
    $firstCert = file_get_contents($harness->certPath($appName));

    $harness->ensureCert($appName, 'test');
    $secondCert = file_get_contents($harness->certPath($appName));

    expect($secondCert)->not->toBe($firstCert)
        ->and($harness->tldFor($appName))->toBe('test')
        ->and($harness->hostCovered($harness->certPath($appName), "{$appName}.test"))->toBeTrue();

    cleanupLocalCaFor($appName);
});

test('ensureAppCertExists reuses the existing cert when the TLD is unchanged', function () {
    $appName = 'lc-reuse-'.uniqid();
    $harness = localCaHarness();

    $harness->ensureCert($appName, 'test');
    $firstCert = file_get_contents($harness->certPath($appName));

    $harness->ensureCert($appName, 'test');
    $secondCert = file_get_contents($harness->certPath($appName));

    expect($secondCert)->toBe($firstCert);

    cleanupLocalCaFor($appName);
});
