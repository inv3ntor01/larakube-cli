<?php

namespace App\Contracts;

use App\Data\ConfigData;

interface HasKubernetesFiles
{
    public function updateK8s(ConfigData $config): void;

    public function getWorkloadViewName(): ?string;

    public function getWorkloadYamlDestination(): ?string;

    public function getNetworkViewName(): ?string;

    public function getNetworkYamlDestination(): ?string;

    public function getStorageViewName(): ?string;

    public function getStorageYamlDestination(): ?string;

    public function getPatchViewName(): ?string;

    public function getPatchYamlDestination(): ?string;

    public function getK8sDeploymentArgs(): string;

    public function getManifestFiles(): array;
}
