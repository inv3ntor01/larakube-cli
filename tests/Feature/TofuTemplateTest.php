<?php

test('do/vps template renders valid HCL with restricted sources and no HTML escaping', function () {
    $hcl = view('tofu.do.vps', [
        'region' => 'sgp1',
        'size' => 's-1vcpu-1gb',
        'dropletName' => 'larakube-acme-prod',
        'sshKeyName' => 'larakube-acme',
        'sshPubKey' => 'ssh-ed25519 AAAAC3NzaC1lZDI1 user@host',
        'keyFingerprint' => '3b:16:aa:cc',
        'sshSources' => '"203.0.113.5/32"',
        'apiSources' => '"203.0.113.5/32"',
    ])->render();

    // Provider constraint must NOT be HTML-escaped (~> stays literal).
    expect($hcl)->toContain('version = "~> 2.0"')
        ->not->toContain('&gt;')
        ->not->toContain('&quot;');

    // Firewall sources keep their literal quotes.
    expect($hcl)->toContain('source_addresses = ["203.0.113.5/32"]')
        ->and($hcl)->toContain('port_range       = "6443"')
        ->and($hcl)->toContain('digitalocean/digitalocean');

    // Token is a variable, never a literal in the rendered HCL.
    expect($hcl)->toContain('variable "do_token"')
        ->and($hcl)->toContain('output "ip"');
});

test('do/vps template leaves API + SSH open when no admin CIDR', function () {
    $hcl = view('tofu.do.vps', [
        'region' => 'nyc1',
        'size' => 's-1vcpu-1gb',
        'dropletName' => 'larakube-open',
        'sshKeyName' => 'larakube-open',
        'sshPubKey' => 'ssh-ed25519 AAAA x',
        'keyFingerprint' => 'ab:cd:ef',
        'sshSources' => '"0.0.0.0/0", "::/0"',
        'apiSources' => '"0.0.0.0/0", "::/0"',
    ])->render();

    expect(substr_count($hcl, '"0.0.0.0/0", "::/0"'))->toBeGreaterThanOrEqual(4); // ssh + api + 80 + 443
});

test('do/managed template renders cluster, node pool and a sensitive kubeconfig output', function () {
    $hcl = view('tofu.do.managed', [
        'region' => 'sgp1',
        'clusterName' => 'larakube-acme',
        'size' => 's-2vcpu-4gb',
        'nodeCount' => 3,
        'versionPrefix' => '1.31.',
    ])->render();

    expect($hcl)->toContain('resource "digitalocean_kubernetes_cluster"')
        ->and($hcl)->toContain('node_count = 3')
        ->and($hcl)->toContain('version_prefix = "1.31."')
        ->and($hcl)->toContain('output "kubeconfig"')
        ->and($hcl)->toContain('sensitive = true')
        ->not->toContain('&gt;');
});
