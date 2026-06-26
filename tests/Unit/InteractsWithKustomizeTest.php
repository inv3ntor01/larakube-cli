<?php

function kustomizeHarness(?string $bin): object
{
    return new class($bin)
    {
        use App\Traits\InteractsWithKustomize;

        public function __construct(private ?string $bin) {}

        public function build(string $path): string
        {
            return $this->kustomizeBuildCommand($path);
        }

        public function apply(string $path): string
        {
            return $this->kustomizeApplyCommand($path);
        }

        public function recent(string $version): bool
        {
            return $this->kustomizeVersionIsRecent($version);
        }

        // Stand in for the resolved binary so we can test command shaping without a
        // real install on disk.
        protected function kustomizeBin(): ?string
        {
            return $this->bin;
        }
    };
}

test('build/apply fall back to kubectl when no standalone kustomize is installed', function () {
    $h = kustomizeHarness(null);

    expect($h->build('overlays/local'))->toBe('kubectl kustomize '.escapeshellarg('overlays/local'))
        ->and($h->apply('overlays/local'))->toBe('kubectl apply -k '.escapeshellarg('overlays/local'));
});

test('build/apply route through the standalone kustomize when present', function () {
    $bin = '/home/u/.larakube/bin/kustomize';
    $h = kustomizeHarness($bin);

    expect($h->build('overlays/local'))->toBe(escapeshellarg($bin).' build '.escapeshellarg('overlays/local'))
        ->and($h->apply('overlays/local'))->toBe(escapeshellarg($bin).' build '.escapeshellarg('overlays/local').' | kubectl apply -f -');
});

test('kustomizeVersionIsRecent gates on major v5+ (the multi-doc-patch threshold)', function () {
    $h = kustomizeHarness(null);

    expect($h->recent('v5.0.4-0.20230601165947-9e8e6799514f'))->toBeTrue()   // kubectl-reported form
        ->and($h->recent('v5.6.0'))->toBeTrue()
        ->and($h->recent('kustomize/v5.0.0'))->toBeTrue()
        ->and($h->recent('v10.1.0'))->toBeTrue()                              // multi-digit major
        ->and($h->recent('v4.5.7'))->toBeFalse()                             // the version that breaks on WSL
        ->and($h->recent('v3.8.1'))->toBeFalse()
        ->and($h->recent(''))->toBeFalse();                                  // absent → treat as old
});
