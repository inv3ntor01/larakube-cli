<?php

/**
 * The "(stopped)" bug: `larakube context` labelled the k3d cluster as stopped
 * even when it was running. The detection did `! str_contains($out, 'running')`,
 * but `k3d cluster list --no-headers` never prints "running"/"stopped" — it
 * prints the SERVERS column as "running/total" (e.g. "1/1" up, "0/1" stopped),
 * so the test was ALWAYS true. The fix parses that column instead. This locks
 * in the parsing — the part that was actually wrong. (The `k3d cluster list`
 * shell call itself needs a real k3d and belongs in a CI smoke job.)
 */

use App\Traits\InteractsWithClusterContext;

function k3dStatusParser(): object
{
    return new class
    {
        use InteractsWithClusterContext;
    };
}

test('a running cluster (SERVERS running/total > 0) reads as running', function () {
    $p = k3dStatusParser();

    expect($p->k3dClusterListLineIsRunning('larakube   1/1       0/0      true'))->toBeTrue()
        ->and($p->k3dClusterListLineIsRunning('larakube   2/2       1/1      true'))->toBeTrue()
        ->and($p->k3dClusterListLineIsRunning("larakube\t1/1\t0/1\tfalse"))->toBeTrue()       // tab-separated
        ->and($p->k3dClusterListLineIsRunning('  larakube  1/1  0/0  true  '))->toBeTrue();   // surrounding whitespace
});

test('a stopped cluster (0 servers running) reads as stopped — the actual bug', function () {
    $p = k3dStatusParser();

    expect($p->k3dClusterListLineIsRunning('larakube   0/1       0/0      false'))->toBeFalse()
        ->and($p->k3dClusterListLineIsRunning('larakube   0/1       1/1      true'))->toBeFalse(); // agents up, server down
});

test('an absent cluster (empty output) reads as not running', function () {
    $p = k3dStatusParser();

    expect($p->k3dClusterListLineIsRunning(''))->toBeFalse()
        ->and($p->k3dClusterListLineIsRunning('   '))->toBeFalse()
        ->and($p->k3dClusterListLineIsRunning("\n"))->toBeFalse();
});
