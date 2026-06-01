<?php

namespace App\Enums;

use App\Contracts\HasCommandOptions;
use App\Contracts\HasLabel;
use App\Contracts\HasSelectOptions;
use App\Traits\ProvidesCommandOptions;
use App\Traits\ProvidesSelectOptions;

enum DeploymentStrategy: string implements HasCommandOptions, HasLabel, HasSelectOptions
{
    use ProvidesCommandOptions, ProvidesSelectOptions;

    public function getLabel(): ?string
    {
        return match ($this) {
            self::SINGLE_NODE => 'Single-Node Hero (Optimized for cost/simplicity, using HostPort)',
            self::MULTI_NODE_HA => 'Multi-Node HA (Optimized for scale, using managed LoadBalancer)',
        };
    }

    case SINGLE_NODE = 'single-node';
    case MULTI_NODE_HA = 'multi-node-ha';
}
