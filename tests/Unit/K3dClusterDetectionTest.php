<?php

/**
 * The k3d image-sideload bug: `larakube up` built the image but imported it into
 * a HARDCODED cluster named "larakube", so any other cluster name was silently
 * skipped → pods couldn't find the image. The fix derives the cluster from the
 * active kube-context (k3d-<name>). This tests that detection — the part that
 * was actually wrong. (The `k3d image import` shell call itself needs a real
 * cluster; that belongs in a CI k3d smoke job, not a unit test.)
 */

use App\Traits\InteractsWithDocker;

function k3dResolver(): object
{
    return new class
    {
        use InteractsWithDocker;

        public function resolve(string $context): ?string
        {
            return $this->resolveK3dClusterName($context);
        }

        public function sideload(string $context): ?array
        {
            return $this->resolveSideloadTarget($context);
        }
    };
}

test('resolves the cluster name from any k3d context, not just "larakube"', function () {
    $r = k3dResolver();

    expect($r->resolve('k3d-larakube'))->toBe('larakube')
        ->and($r->resolve('k3d-myapp'))->toBe('myapp')           // the bug: this used to be skipped
        ->and($r->resolve('k3d-my-team-cluster'))->toBe('my-team-cluster') // hyphenated names
        ->and($r->resolve('  k3d-spaced  '))->toBe('spaced');    // trims surrounding whitespace
});

test('returns null for non-k3d contexts (registry path) and empties', function () {
    $r = k3dResolver();

    expect($r->resolve('orbstack'))->toBeNull()
        ->and($r->resolve('docker-desktop'))->toBeNull()
        ->and($r->resolve('minikube'))->toBeNull()
        ->and($r->resolve('arn:aws:eks:...'))->toBeNull()
        ->and($r->resolve('k3d-'))->toBeNull()   // prefix only, no cluster name
        ->and($r->resolve(''))->toBeNull();
});

/**
 * The k3s image-sideload bug: native k3s uses containerd, not Docker, so a
 * host-built image must be imported with `k3s ctr images import` — but the
 * build path treated the k3s context as registry-backed and skipped it, so
 * pods failed with ImagePullBackOff. resolveSideloadTarget() routes by engine.
 */
test('routes a k3d context to the k3d engine with its cluster name', function () {
    $r = k3dResolver();

    expect($r->sideload('k3d-larakube'))->toBe(['engine' => 'k3d', 'cluster' => 'larakube'])
        ->and($r->sideload('k3d-myapp'))->toBe(['engine' => 'k3d', 'cluster' => 'myapp']);
});

test('routes the local native k3s context to the k3s engine', function () {
    $r = k3dResolver();

    expect($r->sideload('k3s-larakube'))->toBe(['engine' => 'k3s'])
        ->and($r->sideload('  k3s-larakube  '))->toBe(['engine' => 'k3s']); // trims whitespace
});

test('routes remote/registry-backed contexts to nothing (no sideload)', function () {
    $r = k3dResolver();

    expect($r->sideload('larakube-203.0.113.5'))->toBeNull()  // remote k3s from cloud:provision
        ->and($r->sideload('arn:aws:eks:...'))->toBeNull()    // managed cloud
        ->and($r->sideload('orbstack'))->toBeNull()           // not a build-sideload engine
        ->and($r->sideload(''))->toBeNull();
});
