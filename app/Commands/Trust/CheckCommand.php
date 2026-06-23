<?php

namespace App\Commands\Trust;

use App\Data\GlobalConfigData;
use App\Traits\InteractsWithTrust;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class CheckCommand extends Command
{
    use InteractsWithTrust, LaraKubeOutput;

    protected $signature = 'trust:check';

    protected $description = 'Diagnose the local HTTPS trust chain (CA, keychain, DNS, certs)';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('Diagnosing local HTTPS trust chain...');
        $this->newLine();

        $issues = 0;

        // ── Local CA ────────────────────────────────────────────────────────
        $this->line('  <fg=cyan>Local CA</>');

        $caExists = $this->localCaExists();
        $this->checkLine($caExists, 'CA files present at ~/.larakube/certificates/');
        $issues += $caExists ? 0 : 1;

        if ($caExists) {
            $trusted = $this->isCaTrusted();
            $this->checkLine($trusted, 'Trusted in system keychain');
            if (! $trusted) {
                $this->line('    <fg=gray>→ Run: larakube trust</>');
                $issues++;
            }
        }

        $this->newLine();

        // ── DNS ─────────────────────────────────────────────────────────────
        $tld = GlobalConfigData::load()->getLocalTld();
        $this->line('  <fg=cyan>DNS (*.'.$tld.' → 127.0.0.1)</>');

        $dnsmasq = $this->isDnsmasqConfigured();
        if ($dnsmasq) {
            $this->checkLine(true, 'dnsmasq configured');
        } else {
            $hostsHasKube = str_contains((string) file_get_contents('/etc/hosts'), '# LaraKube:');
            $this->checkLine($hostsHasKube, '/etc/hosts fallback active (run larakube up to add entries)');
            if (! $hostsHasKube) {
                $this->line('    <fg=gray>→ Run: larakube trust  (to set up dnsmasq) or  larakube up  (to add /etc/hosts entries)</>');
                $issues++;
            }
        }

        $this->newLine();

        // ── System cert ──────────────────────────────────────────────────────
        $this->line('  <fg=cyan>System cert (console.'.$tld.', traefik.'.$tld.', …)</>');

        $sysCrt = $this->getSystemCertPath();
        $sysKey = $this->getSystemKeyPath();

        if (! file_exists($sysCrt) || ! file_exists($sysKey)) {
            $this->checkLine(false, 'System cert not found');
            $this->line('    <fg=gray>→ Run: larakube traefik:setup</>');
            $issues++;
        } elseif (! $this->certIsValid($sysCrt)) {
            $this->checkLine(false, 'System cert expired or expiring within 30 days');
            $this->line('    <fg=gray>→ Run: larakube trust:reset</>');
            $issues++;
        } elseif (! $this->certCoversHost($sysCrt, 'console.'.$tld)) {
            $this->checkLine(false, 'System cert covers wrong TLD (needs regeneration)');
            $this->line('    <fg=gray>→ Run: larakube trust:reset</>');
            $issues++;
        } else {
            $expiry = $this->certExpiry($sysCrt);
            $this->checkLine(true, "Valid until {$expiry}");
        }

        $this->newLine();

        // ── App certs ────────────────────────────────────────────────────────
        $appCerts = $this->getAllLocalAppCerts();

        if (! empty($appCerts)) {
            $this->line('  <fg=cyan>App certs</>');

            foreach ($appCerts as $appName => $paths) {
                $crt = $paths['crt'];
                // Each app's own pinned TLD (sidecar written alongside its cert),
                // not the global $tld — a project with a `config:tld --project`
                // override legitimately uses a different TLD than this machine's default.
                $appTld = $this->getAppCertTld($appName);

                if (! $this->certIsValid($crt)) {
                    $this->checkLine(false, sprintf('  %-18s expired or expiring — run: larakube up', $appName));
                    $issues++;
                } elseif (! $this->certCoversHost($crt, "{$appName}.{$appTld}")) {
                    $this->checkLine(false, sprintf('  %-18s wrong TLD — run: larakube up (in that project)', $appName));
                    $issues++;
                } else {
                    $expiry = $this->certExpiry($crt);
                    $this->checkLine(true, sprintf('  %-18s valid until %s (.%s)', $appName, $expiry, $appTld));
                }
            }

            $this->newLine();
        }

        // ── Summary ──────────────────────────────────────────────────────────
        if ($issues === 0) {
            $this->laraKubeInfo('All checks passed.');
        } else {
            $noun = $issues === 1 ? 'issue' : 'issues';
            $this->laraKubeWarn("{$issues} {$noun} found. See suggestions above.");
        }

        return $issues > 0 ? 1 : 0;
    }

    private function checkLine(bool $ok, string $label): void
    {
        $icon = $ok ? '<fg=green>✓</>' : '<fg=red>✗</>';
        $this->line("  {$icon}  {$label}");
    }

    private function certExpiry(string $crtPath): string
    {
        $ts = $this->getCertExpiry($crtPath);

        return $ts ? date('Y-m-d', $ts) : 'unknown';
    }
}
