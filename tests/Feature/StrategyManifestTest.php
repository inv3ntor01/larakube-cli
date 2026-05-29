<?php

use App\Data\ConfigData;
use App\Enums\DeploymentStrategy;
use App\Enums\ServerVariation;

test('Strategy: cloud-overlay PVCs follow the strategy; local is always ReadWriteOnce', function () {
    foreach ([
        [DeploymentStrategy::SINGLE_NODE, 'ReadWriteOnce'],
        [DeploymentStrategy::MULTI_NODE_HA, 'ReadWriteMany'],
    ] as [$strategy, $mode]) {
        $config = new ConfigData(name: 'strat-'.$strategy->value);
        $config->setServerVariation(ServerVariation::FPM_NGINX);
        $config->setStrategy($strategy);

        $manifests = generateManifestsAsArray($config);

        // App PVCs now live per-environment, not in base/.
        expect($manifests)->not->toHaveKey('base/volumes.yaml')
            ->and($manifests)->toHaveKey('overlays/production/app-volumes.yaml')
            ->and($manifests)->toHaveKey('overlays/local/app-volumes.yaml');

        // The cloud env reflects the project strategy…
        $prod = $manifests['overlays/production/app-volumes.yaml'];
        expect($prod[0]['spec']['accessModes'][0])->toBe($mode)
            ->and($prod[1]['spec']['accessModes'][0])->toBe($mode);

        // …while local is always ReadWriteOnce (single machine), regardless.
        $local = $manifests['overlays/local/app-volumes.yaml'];
        expect($local[0]['spec']['accessModes'][0])->toBe('ReadWriteOnce');
    }
});
