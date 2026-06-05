<?php

namespace App\Traits;

/**
 * Basic, idempotent hardening for a freshly provisioned k3s node. Pure builders
 * (return the remote bash) so they're unit-testable and reusable by both
 * `cloud:provision` and a future standalone `cloud:harden`. The orchestration
 * SSHes the script via the same runner as the rest of provisioning.
 *
 * Two safety rules baked into the script:
 *   1. The SSH port is allowed BEFORE `ufw --force enable` (no lockout window).
 *   2. The k3s pod & service CIDRs are allowed, so enabling a host firewall on a
 *      running cluster doesn't sever intra-cluster networking (CoreDNS → API, etc).
 */
trait InteractsWithServerHardening
{
    /**
     * @param  int  $sshPort  the live SSH port — allowed first so we never lock out
     * @param  array<int,int>  $allowPorts  extra inbound TCP ports (default: HTTP/HTTPS/k3s-API)
     * @param  bool  $disablePasswordAuth  drop SSH password auth (safe: we connect by key)
     */
    public function hardenServerScript(
        int $sshPort,
        array $allowPorts = [80, 443, 6443],
        bool $disablePasswordAuth = true,
        string $podCidr = '10.42.0.0/16',
        string $serviceCidr = '10.43.0.0/16',
    ): string {
        // SSH first — then the rest — so `ufw --force enable` can never strand us.
        $allows = 'ufw allow '.$sshPort."/tcp\n";
        foreach ($allowPorts as $port) {
            $allows .= 'ufw allow '.((int) $port)."/tcp\n";
        }
        // Keep k3s pod/service traffic flowing through the host firewall.
        $allows .= 'ufw allow from '.$podCidr." to any\n";
        $allows .= 'ufw allow from '.$serviceCidr.' to any';

        $ssh = $disablePasswordAuth
            ? <<<'BASH'
echo "Disabling SSH password auth (key-only)..."
sed -i 's/^#\?PasswordAuthentication .*/PasswordAuthentication no/' /etc/ssh/sshd_config
grep -qxF 'PasswordAuthentication no' /etc/ssh/sshd_config || echo 'PasswordAuthentication no' >> /etc/ssh/sshd_config
systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true
BASH
            : 'echo "Leaving SSH password auth unchanged."';

        return <<<BASH
set -e
export DEBIAN_FRONTEND=noninteractive

echo "Installing firewall, fail2ban, and unattended-upgrades..."
apt-get update -y
apt-get install -y ufw fail2ban unattended-upgrades

echo "Configuring UFW (default deny incoming)..."
ufw default deny incoming
ufw default allow outgoing
{$allows}
ufw --force enable

echo "Enabling fail2ban (SSH brute-force protection)..."
systemctl enable fail2ban
systemctl restart fail2ban

echo "Enabling automatic security updates..."
dpkg-reconfigure -f noninteractive unattended-upgrades || true
systemctl enable unattended-upgrades 2>/dev/null || true
systemctl restart unattended-upgrades 2>/dev/null || true

{$ssh}

echo "Hardening complete."
BASH;
    }

    /**
     * Disable remote root SSH login. The root ACCOUNT stays (system processes,
     * sudo, and the provider's recovery console still need it) — only its network
     * login over SSH is closed, removing that attack surface. Pure.
     *
     * Apply this ONLY after confirming a non-root sudo user can log in and sudo,
     * so you never cut the last remote admin path. The orchestration does that
     * check (testSsh + canSudo as `larakube`) before running this.
     */
    public function disableRootLoginScript(): string
    {
        return <<<'BASH'
echo "Disabling remote root SSH login..."
sed -i 's/^#\?PermitRootLogin .*/PermitRootLogin no/' /etc/ssh/sshd_config
grep -qxF 'PermitRootLogin no' /etc/ssh/sshd_config || echo 'PermitRootLogin no' >> /etc/ssh/sshd_config
systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true
echo "Remote root login disabled (root still available via console/sudo)."
BASH;
    }
}
