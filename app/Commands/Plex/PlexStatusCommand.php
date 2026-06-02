<?php

namespace App\Commands\Plex;

use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;
use LaravelZero\Framework\Commands\Command;

class PlexStatusCommand extends Command
{
    use InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext;

    protected $signature = 'plex:status {environment=production : The cloud environment whose Commons to inspect}';

    protected $description = 'Show the shared Commons services and its tenants';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Plex — Commons Status');

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $config = $this->getProjectConfig(getcwd());
        if (! $config) {
            return 1;
        }

        $env = (string) $this->argument('environment');
        if ($env === 'local') {
            $this->laraKubeError('Plex is a cloud topology — pick a cloud environment.');

            return 1;
        }

        // Read-only: target the env's own context if recorded, else fall back to
        // the current context. Never prompt to capture a deploy target just to look.
        $context = $this->environmentContextOrCurrent($config, $env);
        $this->plexContext = $context;

        if (! $this->plexContextReachable()) {
            $this->laraKubeError('The '.($context ? "context '{$context}'" : 'current context').' is unreachable.');

            return 1;
        }

        $this->line('  <fg=gray>Context:</> <fg=cyan>'.($context ?: 'current context').'</>');

        $spec = $this->getCommonsSpec();
        if ($spec === null) {
            $this->laraKubeNewLine();
            $this->laraKubeInfo('No Commons on this cluster yet. Run `larakube plex:init`.');

            return 0;
        }

        // Services + in-cluster hosts — driven by the spec itself (service name
        // and port come from the spec, never a hardcoded list).
        $ns = $this->plexNamespace();
        $this->laraKubeNewLine();
        $this->line("  <fg=green>Commons services</> ({$ns}):");
        foreach ($spec['services'] ?? [] as $service => $cfg) {
            $on = $cfg['enabled'] ?? false;
            $mark = $on ? '<fg=green>●</>' : '<fg=gray>○</>';
            $detail = $on ? "{$service}.{$ns}.svc.cluster.local:".($cfg['port'] ?? '') : 'disabled';
            $this->line('    '.$mark.' '.str_pad($service, 12).' <fg=gray>'.$detail.'</>');
            if ($on && ! empty($cfg['host'])) {
                $this->line('      <fg=gray>public:</> <fg=cyan>https://'.$cfg['host'].'</>');
            }
        }

        // Tenants from the registry (highlight this app if it's one).
        $tenants = $this->getRegistry()['tenants'] ?? [];
        $self = $this->plexTenantIdentifier($config->getName());

        $this->laraKubeNewLine();
        if (empty($tenants)) {
            $this->line('  <fg=gray>No tenants yet.</>');

            return 0;
        }

        $this->line('  <fg=green>Tenants</> ('.count($tenants).'):');
        foreach ($tenants as $name => $alloc) {
            $db = $alloc['db'] ?? '—';
            $redis = ($alloc['redis_index'] ?? null) === null ? 'no redis' : 'redis db '.$alloc['redis_index'];
            $s3 = ($alloc['s3_bucket'] ?? null) ? ', s3 '.$alloc['s3_bucket'] : '';
            $you = $name === $self ? ' <fg=yellow>(this app)</>' : '';
            $this->line("    <fg=cyan>{$name}</>{$you}  <fg=gray>db={$db}, {$redis}{$s3}</>");
        }

        return 0;
    }
}
