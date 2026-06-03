<?php

namespace App\Traits;

use function Laravel\Prompts\confirm;

trait InteractsWithHosts
{
    use DetectsWsl, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * Check and optionally update the hosts file(s) based on project context.
     * On WSL this also syncs the Windows hosts file, since the Windows browser
     * doesn't read WSL's /etc/hosts.
     *
     * @param  array  $customHosts  Optional specific hosts to map. If empty, uses the current project's hosts.
     * @param  string|null  $customAppName  Optional app name to group the block. Defaults to the current directory name.
     */
    protected function ensureHostsAreSet(array $customHosts = [], ?string $customAppName = null): void
    {
        $projectPath = getcwd();
        $appName = $customAppName ?? basename($projectPath);

        if (empty($customHosts)) {
            $config = $this->getProjectConfig($projectPath);

            // No readable config → nothing to map. Host syncing is a convenience
            // pre-step; skip it rather than crash (getProjectConfig already
            // surfaced the reason if the file exists but is invalid).
            if (! $config) {
                return;
            }

            $requiredHosts = array_keys($config->getAllHosts('local'));
        } else {
            $requiredHosts = $customHosts;
        }

        if (empty($requiredHosts)) {
            return;
        }

        // 🪟 WSL: the Windows browser can't see WSL's /etc/hosts, so also sync
        // the Windows hosts file. Done first/independently of the Linux sync.
        if ($this->isWsl()) {
            $this->ensureWindowsHostsAreSet($requiredHosts, $appName);
        }

        // 🛡 SMART IP DETECTION
        // On Mac and Windows, Docker Desktop/OrbStack maps published ports to 127.0.0.1.
        // On Linux, we use the actual LoadBalancer IP because it's natively routable.
        $isLinux = PHP_OS_FAMILY === 'Linux';
        $externalIp = '127.0.0.1';

        if ($isLinux) {
            $detectedIp = shell_exec("kubectl get svc traefik -n traefik -o jsonpath='{.status.loadBalancer.ingress[0].ip}' 2>/dev/null");
            if (! empty($detectedIp)) {
                $externalIp = $detectedIp;
            }
        }
        $hostList = implode(' ', $requiredHosts);
        $newEntry = "$externalIp $hostList";
        $blockIdentifier = "# LaraKube: $appName";
        $fullBlock = "\n{$blockIdentifier}\n{$newEntry}\n";

        // 1. Check if update is actually needed
        if (! file_exists('/etc/hosts')) {
            return;
        }

        // If running inside a container, we usually can't update the host's /etc/hosts
        // without mapping it, which is risky. We'll skip it and warn the user.
        if (getenv('LARAKUBE_HOST_PROJECT_PATH') && ! is_writable('/etc/hosts')) {
            $this->warning('Running inside LaraKube daemon: skipping /etc/hosts sync.');
            $this->line('  👉 Please ensure your host machine has these mappings:');
            $this->line("     $newEntry");
            $this->line('');

            return;
        }

        $currentHosts = file_get_contents('/etc/hosts');

        if (str_contains($currentHosts, $fullBlock)) {
            return;
        }

        $this->laraKubeInfo('Local domain mapping update required.');
        $this->line("  <fg=gray>Target IP:</> <fg=blue>$externalIp</>");
        foreach ($requiredHosts as $host) {
            $this->line("  <fg=gray>●</> <fg=blue>{$host}</>");
        }
        $this->line('');

        if (confirm('Would you like LaraKube to sync your /etc/hosts?')) {
            $this->line('  <fg=gray>LaraKube requires sudo privileges to update /etc/hosts</>');
            passthru('sudo -v');

            $this->withSpin('Syncing /etc/hosts...', function () use ($currentHosts, $blockIdentifier, $newEntry) {
                $newHosts = $this->applyHostsBlock($currentHosts, $blockIdentifier, $newEntry);

                $tmpPath = sys_get_temp_dir().'/larakube_hosts';
                file_put_contents($tmpPath, $newHosts);

                exec("sudo cp $tmpPath /etc/hosts");
                @unlink($tmpPath);

                return true;
            });

            $this->laraKubeInfo('Hosts synchronized successfully!');
        }
    }

    /**
     * Sync project domains into the Windows hosts file from WSL.
     *
     * The Windows hosts file requires Administrator rights, so we don't write to
     * /mnt/c/... directly (it would fail with permission denied). Instead we drop
     * a tiny .ps1 and run it elevated via PowerShell's Start-Process -Verb RunAs
     * (the standard UAC prompt). Windows reaches the WSL2 cluster ingress on
     * 127.0.0.1, so that's the mapped address.
     *
     * @param  array<int, string>  $requiredHosts
     */
    protected function ensureWindowsHostsAreSet(array $requiredHosts, string $appName): void
    {
        $winHosts = '/mnt/c/Windows/System32/drivers/etc/hosts';

        if (! file_exists($winHosts)) {
            return; // Non-standard WSL mount; nothing we can safely do.
        }

        $blockIdentifier = "# LaraKube: $appName";
        $entry = '127.0.0.1 '.implode(' ', $requiredHosts);

        $current = (string) file_get_contents($winHosts);
        $updated = $this->applyHostsBlock($current, $blockIdentifier, $entry);

        // Already in sync (ignoring trailing-whitespace differences).
        if (rtrim($updated) === rtrim($current)) {
            return;
        }

        $this->laraKubeInfo('Windows hosts file needs updating (so your Windows browser resolves these).');
        $this->printWindowsHostsManualHelp($entry);

        // Editing the Windows hosts file needs admin, and UAC auto-elevation across
        // the WSL→Windows boundary is unreliable (it can just flash a window and
        // fail). So the manual steps above are the recommended path, and the
        // auto-sync is strictly opt-in — we no longer rely on the elevation.
        if (! confirm('Or have LaraKube try to sync it now via a Windows admin prompt?', false)) {
            return;
        }

        // Write the full new content to a temp file, then copy it into place via
        // an elevated PowerShell running a generated .ps1 (literal paths only —
        // no fragile inline quoting).
        $contentTmp = sys_get_temp_dir().'/larakube_win_hosts';
        $scriptTmp = sys_get_temp_dir().'/larakube_win_hosts_sync.ps1';
        file_put_contents($contentTmp, $updated);

        $winContent = trim((string) shell_exec('wslpath -w '.escapeshellarg($contentTmp).' 2>/dev/null'));
        if ($winContent === '') {
            @unlink($contentTmp);
            $this->printWindowsHostsManualHelp($entry);

            return;
        }

        file_put_contents(
            $scriptTmp,
            "Copy-Item -LiteralPath '{$winContent}' -Destination 'C:\\Windows\\System32\\drivers\\etc\\hosts' -Force\n",
        );
        $winScript = trim((string) shell_exec('wslpath -w '.escapeshellarg($scriptTmp).' 2>/dev/null'));

        if ($winScript === '') {
            @unlink($contentTmp);
            @unlink($scriptTmp);
            $this->printWindowsHostsManualHelp($entry);

            return;
        }

        $startProcess = 'Start-Process -FilePath powershell -Verb RunAs -Wait '
            ."-ArgumentList '-NoProfile','-ExecutionPolicy','Bypass','-File','{$winScript}'";

        $output = [];
        $code = 0;
        exec('powershell.exe -NoProfile -Command '.escapeshellarg($startProcess).' 2>/dev/null', $output, $code);

        @unlink($contentTmp);
        @unlink($scriptTmp);

        if ($code !== 0) {
            $this->laraKubeWarn('Could not sync the Windows hosts file automatically.');
            $this->printWindowsHostsManualHelp($entry);

            return;
        }

        $this->laraKubeInfo('Windows hosts file synchronized!');
    }

    /**
     * Insert/replace this project's hosts block idempotently. Strips any existing
     * block with the same identifier, then appends a fresh one — so applying it
     * repeatedly with the same entry yields the same file.
     */
    protected function applyHostsBlock(string $current, string $blockIdentifier, string $entryLine): string
    {
        // Remove a previous block: the identifier line + its single entry line.
        $pattern = '/\n?'.preg_quote($blockIdentifier, '/')."\n[^\n]*\n?/";
        $stripped = preg_replace($pattern, "\n", $current);
        $stripped = $stripped ?? $current;

        return rtrim($stripped)."\n\n{$blockIdentifier}\n{$entryLine}\n";
    }

    /**
     * Print copy-pasteable instructions for adding the Windows hosts entry by
     * hand — the fallback when the user declines or the elevated sync fails.
     */
    protected function printWindowsHostsManualHelp(string $entry): void
    {
        $this->line('  👉 Add this line to your Windows hosts file manually:');
        $this->line("     <fg=blue>$entry</>");
        $this->line('     <fg=gray>File: C:\\Windows\\System32\\drivers\\etc\\hosts (open Notepad as Administrator)</>');
        $this->line('');
    }
}
