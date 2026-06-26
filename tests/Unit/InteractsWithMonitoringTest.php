<?php

use App\Data\ConfigData;
use App\Data\EnvironmentData;

function monitoringReader(): object
{
    return new class
    {
        use App\Traits\InteractsWithMonitoring;

        public function host(string $env, ?ConfigData $config): ?string
        {
            return $this->resolveGrafanaHostReadOnly($env, $config);
        }

        public function kubectlFor(?string $context): string
        {
            return $this->monitoringKubectl($context);
        }
    };
}

test('local Grafana host uses the grafana subdomain on the dev TLD', function () {
    expect(monitoringReader()->host('local', null))->toStartWith('grafana.');
});

test('cloud Grafana host returns the host persisted for that env', function () {
    $config = ConfigData::from(['name' => 'demo']);
    $config->environments['production'] = EnvironmentData::from(['hosts' => ['grafana' => 'grafana.example.com']]);

    expect(monitoringReader()->host('production', $config))->toBe('grafana.example.com');
});

test('cloud Grafana host is null when none is configured for the env', function () {
    $config = ConfigData::from(['name' => 'demo']);
    $config->environments['production'] = EnvironmentData::from([]);

    expect(monitoringReader()->host('production', $config))->toBeNull();
});

test('monitoringKubectl scopes to a context only when one is given', function () {
    $reader = monitoringReader();

    expect($reader->kubectlFor('do-sfo3'))->toBe('kubectl --context=do-sfo3')
        ->and($reader->kubectlFor(''))->toBe('kubectl')
        ->and($reader->kubectlFor(null))->toBe('kubectl');
});
