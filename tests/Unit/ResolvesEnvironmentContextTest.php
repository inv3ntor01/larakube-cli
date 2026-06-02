<?php

/**
 * Pure-logic tests for the shared environment-context resolution — the bit that
 * lets commands target an env's OWN context (larakube-<ip>) via `kubectl
 * --context` instead of switching the global context. The prompt/persist and
 * reachability paths touch I/O and belong in a cluster smoke test.
 */

use App\Traits\ResolvesEnvironmentContext;

function envContext(): object
{
    return new class
    {
        use ResolvesEnvironmentContext;
    };
}

test('environmentContextName matches the name cloud:provision creates', function () {
    expect(envContext()->environmentContextName('159.223.43.95'))->toBe('larakube-159.223.43.95');
});

test('contextKubectl scopes kubectl to a context, or stays plain when null/empty', function () {
    $e = envContext();

    expect($e->contextKubectl('larakube-159.223.43.95'))->toBe("kubectl --context 'larakube-159.223.43.95'")
        ->and($e->contextKubectl(null))->toBe('kubectl')
        ->and($e->contextKubectl(''))->toBe('kubectl');
});
