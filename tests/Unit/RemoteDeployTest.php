<?php

/**
 * Pure-logic tests for the registry-less remote deploy (SSH-sideload): the
 * command-builders, the rollout-triggering tag, and the .env → ConfigMap/Secret
 * split. The actual build/ssh/kubectl I/O belongs in a droplet smoke test.
 */

use App\Traits\InteractsWithRemoteDeploy;

function remoteDeploy(): object
{
    return new class
    {
        use InteractsWithRemoteDeploy;
    };
}

test('the per-host context name matches what cloud:provision creates', function () {
    expect(remoteDeploy()->remoteContextName('159.223.43.95'))->toBe('larakube-159.223.43.95');
});

test('the production build cross-compiles for the amd64 node and targets the deploy stage', function () {
    $cmd = remoteDeploy()->buildProductionImageCommand('app-one:abc123', '/proj/Dockerfile.php', '/proj');

    expect($cmd)
        ->toContain('docker buildx build')
        ->toContain('--platform linux/amd64')   // arm Mac -> amd64 droplet
        ->toContain('--target deploy')           // production stage, not development
        ->toContain('--load')                    // so `docker save` can stream it
        ->toContain("-t 'app-one:abc123'");
});

test('the sideload streams the saved image into the remote k3s containerd', function () {
    $r = remoteDeploy();
    $ssh = $r->sshBaseCommand('larakube', '159.223.43.95', 22, '/home/me/.ssh/id_rsa');
    $cmd = $r->sideloadOverSshCommand('app-one:abc123', $ssh);

    expect($ssh)->toContain('ssh -o StrictHostKeyChecking=no')->toContain("'larakube@159.223.43.95'")
        ->and($cmd)->toContain("docker save 'app-one:abc123' | ssh")
        ->and($cmd)->toContain("'sudo k3s ctr images import -'");
});

test('apply rewrites the local :latest tag to the sideloaded tag, on the env context', function () {
    $cmd = remoteDeploy()->applyWithImageRewriteCommand(
        'larakube-159.223.43.95', '/proj/.infrastructure/k8s/overlays/production', 'app-one:latest', 'app-one:abc123',
    );

    expect($cmd)
        ->toContain("kubectl --context 'larakube-159.223.43.95' kustomize")
        ->toContain('sed')
        ->toContain('s|image: app-one:latest|image: app-one:abc123|g')
        ->toContain("kubectl --context 'larakube-159.223.43.95' apply -f -");
});

test('the image tag uses the git sha when present, else a timestamped fallback', function () {
    $r = remoteDeploy();

    expect($r->formatImageTag('  abc1234  ', 1000))->toBe('abc1234')   // trimmed sha wins
        ->and($r->formatImageTag(null, 1717286400))->toBe('build-1717286400')
        ->and($r->formatImageTag('', 1717286400))->toBe('build-1717286400');
});

test('env split routes secrets to the Secret and the rest to the ConfigMap', function () {
    $r = remoteDeploy();
    $lines = [
        'APP_URL=https://app.test',
        '# a comment',
        'DB_HOST=postgres.larakube-shared.svc.cluster.local',
        'DB_PASSWORD=s3cr3t',
        'APP_KEY=base64:xxx',
        'NO_VALUE_LINE',
    ];

    // knownSecrets is a LIST of keys (as array_keys(getAllSecretEnvironmentVariables) gives).
    ['public' => $public, 'secret' => $secret] = $r->splitEnvForK8s($lines, ['APP_URL']);

    // APP_URL is a known secret here (passed in knownSecrets), so it's a secret;
    // DB_HOST is public; PASSWORD/KEY route to secret by heuristic.
    expect($secret)->toContain('APP_URL=https://app.test')
        ->toContain('DB_PASSWORD=s3cr3t')
        ->toContain('APP_KEY=base64:xxx')
        ->and($public)->toContain('DB_HOST=postgres.larakube-shared.svc.cluster.local')
        ->and($public)->not->toContain('a comment')        // comments skipped
        ->and($public)->not->toContain('NO_VALUE_LINE');   // non KEY=VALUE skipped
});
