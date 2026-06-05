<?php

use App\Traits\InteractsWithScopedRbac;

function scopedRbac(): object
{
    return new class
    {
        use InteractsWithScopedRbac;
    };
}

test('rbac manifest contains SA + namespaced Role + RoleBinding, all scoped to the namespace', function () {
    $yaml = scopedRbac()->scopedRbacManifest('myapp-production', 'myapp', 'production');

    expect($yaml)
        ->toContain('kind: ServiceAccount')
        ->toContain('kind: Role')
        ->toContain('kind: RoleBinding')
        ->toContain('namespace: myapp-production')
        ->toContain('larakube.dev/app: myapp')
        ->toContain('larakube.dev/env: production')
        ->toContain('app.kubernetes.io/managed-by: larakube');
});

test('rbac manifest never grants cluster-scoped power', function () {
    $yaml = scopedRbac()->scopedRbacManifest('myapp-production', 'myapp', 'production');

    // A leaked token must not be able to escalate beyond its namespace.
    expect($yaml)
        ->not->toContain('kind: ClusterRole')
        ->not->toContain('kind: ClusterRoleBinding');
});

test('role grants the namespaced Kinds a cloud overlay emits', function () {
    $yaml = scopedRbac()->scopedRbacManifest('myapp-production', 'myapp', 'production');

    foreach (['deployments', 'statefulsets', 'cronjobs', 'services', 'configmaps', 'secrets', 'persistentvolumeclaims', 'ingresses'] as $resource) {
        expect($yaml)->toContain($resource);
    }
    // pods/exec for artisan + migrations.
    expect($yaml)->toContain('pods/exec');
});

test('create token command targets the SA in the namespace via the admin context', function () {
    $cmd = scopedRbac()->createTokenCommand('larakube-1.2.3.4', 'myapp-production');

    expect($cmd)
        ->toContain('--context')
        ->toContain('larakube-1.2.3.4')
        ->toContain('-n')
        ->toContain('myapp-production')
        ->toContain('create token')
        ->toContain('deployer');
});

test('create token command honours an explicit duration', function () {
    $cmd = scopedRbac()->createTokenCommand('ctx', 'ns', 'deployer', 3600);

    expect($cmd)->toContain('--duration=3600s');
});

test('token secret manifest is the SA-token type bound to the SA', function () {
    $yaml = scopedRbac()->tokenSecretManifest('myapp-production');

    expect($yaml)
        ->toContain('type: kubernetes.io/service-account-token')
        ->toContain('kubernetes.io/service-account.name: deployer')
        ->toContain('namespace: myapp-production');
});

test('scoped kubeconfig embeds server, CA, token and pins the namespace', function () {
    $kubeconfig = scopedRbac()->assembleScopedKubeconfig(
        clusterName: 'larakube-1.2.3.4',
        server: 'https://1.2.3.4:6443',
        caData: 'CADATABASE64',
        namespace: 'myapp-production',
        token: 'TOKEN123',
    );

    expect($kubeconfig)
        ->toContain('server: https://1.2.3.4:6443')
        ->toContain('certificate-authority-data: CADATABASE64')
        ->toContain('token: TOKEN123')
        ->toContain('namespace: myapp-production')
        ->toContain('current-context: myapp-production');
});

test('server and CA extraction read from the minified admin context', function () {
    $rbac = scopedRbac();

    expect($rbac->clusterServerCommand('admin-ctx'))
        ->toContain('--minify')
        ->toContain('--flatten')
        ->toContain('admin-ctx')
        ->toContain('clusters[0].cluster.server');

    expect($rbac->clusterCaDataCommand('admin-ctx'))
        ->toContain('certificate-authority-data');
});
