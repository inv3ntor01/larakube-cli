<?php

namespace App\Commands\Cloud;

use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithRemoteSsh;
use App\Traits\InteractsWithServerHardening;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class CloudHardenCommand extends Command
{
    use InteractsWithProjectConfig, InteractsWithRemoteSsh, InteractsWithServerHardening, LaraKubeOutput;

    protected $signature = 'cloud:harden {environment? : The project environment whose server to harden}';

    protected $description = 'Harden an already-provisioned server (UFW firewall, fail2ban, key-only SSH)';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Server Hardening');
        $this->line('  <fg=gray>(Re)applies firewall + SSH hardening to a server that is already provisioned.</>');
        $this->newLine();

        // Pull the server from a project env when we can; otherwise ask.
        [$user, $ip, $port, $keyPath] = $this->resolveConnection();

        $keyPath = str_replace('~', $_SERVER['HOME'] ?? getenv('HOME'), $keyPath);
        if (! file_exists($keyPath)) {
            $this->laraKubeError("SSH key not found at: {$keyPath}");

            return 1;
        }

        $this->laraKubeInfo("Testing SSH connection to {$user}@{$ip}...");
        if (! $this->testSsh($user, $ip, $port, $keyPath)) {
            $this->laraKubeError('Could not connect via SSH. Check the IP, user, port and key.');

            return 1;
        }
        $this->laraKubeInfo('Connection successful!');
        $this->newLine();

        $this->line("  This will apply on <fg=cyan>{$ip}</>:");
        $this->line("   • UFW — default-deny inbound; allow SSH({$port}), 80, 443, 6443 + k3s pod/service CIDRs");
        $this->line('   • fail2ban — SSH brute-force protection');
        $this->line('   • SSH — disable password auth (key-only)');
        $this->newLine();

        if (! confirm('Proceed with hardening?', true)) {
            $this->laraKubeInfo('Cancelled.');

            return 0;
        }

        $this->laraKubeInfo('Hardening server...');
        $this->runRemoteCommand($user, $ip, $port, $keyPath, $this->hardenServerScript($port));

        $this->laraKubeInfo('✅ Hardened: UFW (SSH/80/443/6443 + pod & service CIDRs), fail2ban, auto-updates, key-only SSH.');
        $this->info('   Note: k3s API (6443) is open to the internet — restricting it to your IP is a recommended follow-up.');

        // Closing remote root login is only safe when we have a proven non-root
        // sudo login. Connecting as a non-root user that just ran sudo IS that proof.
        if ($user !== 'root') {
            $this->newLine();
            if (confirm('Also disable remote root SSH login? (you are connected as a working sudo user)', false)) {
                $this->runRemoteCommand($user, $ip, $port, $keyPath, $this->disableRootLoginScript());
                $this->laraKubeInfo('✅ Remote root login disabled.');
            }
        } else {
            $this->newLine();
            $this->info('   Tip: run "cloud:harden" as the "larakube" user to also disable remote root login safely.');
        }

        return 0;
    }

    /**
     * SSH connection details — from a project env's cloud config when present,
     * else prompted (so the command also works standalone, like cloud:provision).
     *
     * @return array{0:string,1:string,2:int,3:string}
     */
    protected function resolveConnection(): array
    {
        $environment = $this->argument('environment');

        if ($environment && $this->isLaraKubeProject(false)) {
            $config = $this->getProjectConfigObject(getcwd());
            $cloud = $config->getCloud($environment);

            if ($cloud && $cloud->ip) {
                $this->line("  <fg=gray>Using</> <fg=cyan>{$environment}</> <fg=gray>server from your blueprint:</> <fg=cyan>{$cloud->ip}</>");

                return [
                    $cloud->user ?? 'root',
                    $cloud->ip,
                    (int) ($cloud->port ?? 22),
                    $cloud->key ?? home_path('.ssh/id_rsa'),
                ];
            }

            $this->laraKubeWarn("No server recorded for '{$environment}' — enter the details manually.");
        }

        return $this->promptConnection();
    }

    /**
     * @return array{0:string,1:string,2:int,3:string}
     */
    protected function promptConnection(): array
    {
        $ip = text(label: 'Server IP address', required: true, placeholder: 'e.g. 123.45.67.89');
        $user = text(label: 'SSH User (must have sudo access)', default: 'root');
        $port = text(label: 'SSH Port', default: '22');
        $keyPath = text(label: 'Path to your SSH Private Key', default: home_path('.ssh/id_rsa'));

        return [$user, $ip, (int) $port, $keyPath];
    }
}
