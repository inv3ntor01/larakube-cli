<?php

use App\Traits\InteractsWithServerHardening;

function hardening(): object
{
    return new class
    {
        use InteractsWithServerHardening;
    };
}

test('the hardening script sets a default-deny firewall and installs fail2ban', function () {
    $script = hardening()->hardenServerScript(22);

    expect($script)
        ->toContain('ufw default deny incoming')
        ->toContain('ufw default allow outgoing')
        ->toContain('apt-get install -y ufw fail2ban')
        ->toContain('systemctl enable fail2ban');
});

test('the SSH port is allowed BEFORE ufw is enabled (no lockout window)', function () {
    $script = hardening()->hardenServerScript(2222);

    $allowPos = strpos($script, 'ufw allow 2222/tcp');
    $enablePos = strpos($script, 'ufw --force enable');

    expect($allowPos)->not->toBeFalse();
    expect($enablePos)->not->toBeFalse();
    expect($allowPos)->toBeLessThan($enablePos);
});

test('the firewall opens HTTP/HTTPS/k3s-API and keeps cluster CIDRs flowing', function () {
    $script = hardening()->hardenServerScript(22);

    expect($script)
        ->toContain('ufw allow 80/tcp')
        ->toContain('ufw allow 443/tcp')
        ->toContain('ufw allow 6443/tcp')
        // Enabling UFW on a running k3s node must not sever intra-cluster traffic.
        ->toContain('ufw allow from 10.42.0.0/16 to any')
        ->toContain('ufw allow from 10.43.0.0/16 to any');
});

test('SSH password auth is disabled by default but can be opted out', function () {
    expect(hardening()->hardenServerScript(22))
        ->toContain('PasswordAuthentication no');

    expect(hardening()->hardenServerScript(22, disablePasswordAuth: false))
        ->not->toContain('PasswordAuthentication no')
        ->toContain('Leaving SSH password auth unchanged');
});

test('the hardening script enables automatic security updates', function () {
    expect(hardening()->hardenServerScript(22))
        ->toContain('unattended-upgrades')
        ->toContain('systemctl enable unattended-upgrades');
});

test('the root-login script closes SSH root login without deleting the account', function () {
    $script = hardening()->disableRootLoginScript();

    expect($script)
        ->toContain('PermitRootLogin no')
        ->toContain('reload ssh')
        // It must NOT remove the root user — root stays for console/sudo/recovery.
        ->not->toContain('userdel')
        ->not->toContain('deluser');
});
