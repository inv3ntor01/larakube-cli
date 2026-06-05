<?php

namespace App\Traits;

/**
 * Shared SSH primitives for the cloud:* commands that drive a remote host
 * (provision, harden). Kept in one place so connection + remote-exec behaviour
 * stays identical across them.
 */
trait InteractsWithRemoteSsh
{
    /** Probe the SSH connection with a key, non-interactively. */
    protected function testSsh($user, $ip, $port, $keyPath): bool
    {
        $command = "ssh -o ConnectTimeout=5 -o BatchMode=yes -o StrictHostKeyChecking=no -i {$keyPath} -p {$port} {$user}@{$ip} 'echo success' 2>&1";
        $output = shell_exec($command);

        return trim($output ?? '') === 'success';
    }

    /**
     * Does this user have *passwordless* sudo on the host? `sudo -n true` exits 0
     * and prints nothing when it works; otherwise it prints a password/permission
     * error to stderr (captured via 2>&1). Used as a lockout guard before we
     * disable remote root login.
     */
    protected function canSudo($user, $ip, $port, $keyPath): bool
    {
        $command = "ssh -o ConnectTimeout=5 -o BatchMode=yes -o StrictHostKeyChecking=no -i {$keyPath} -p {$port} {$user}@{$ip} 'sudo -n true' 2>&1";
        $output = shell_exec($command);

        return trim($output ?? '') === '';
    }

    /** Run a bash script on the remote host (sudo-wrapped for non-root users). */
    protected function runRemoteCommand($user, $ip, $port, $keyPath, $remoteCommand): void
    {
        $sudo = $user !== 'root' ? 'sudo ' : '';
        $fullCommand = $sudo.'bash -c '.escapeshellarg($remoteCommand);
        $sshCommand = "ssh -i {$keyPath} -p {$port} {$user}@{$ip} ".escapeshellarg($fullCommand);
        passthru($sshCommand);
    }
}
