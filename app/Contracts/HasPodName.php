<?php

namespace App\Contracts;

use App\Data\ConfigData;

interface HasPodName
{
    /**
     * Get the primary Kubernetes pod/workload name for this component.
     */
    public function getPodName(?ConfigData $config = null): string;
}
