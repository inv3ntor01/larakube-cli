<?php

namespace App\Enums;

/**
 * Managed Kubernetes providers. Stored on CloudData::$provider alongside
 * $context, and used to default a sensible per-env storageClass (each provider
 * ships its own dynamic block-storage class).
 */
enum ManagedProvider: string
{
    public function label(): string
    {
        return match ($this) {
            self::DOKS => 'DigitalOcean Kubernetes (DOKS)',
            self::EKS => 'AWS Elastic Kubernetes Service (EKS)',
            self::GKE => 'Google Kubernetes Engine (GKE)',
            self::AKS => 'Azure Kubernetes Service (AKS)',
            self::CIVO => 'Civo Kubernetes',
            self::LKE => 'Linode Kubernetes Engine (LKE)',
            self::CUSTOM => 'Other / custom',
        };
    }

    /** The provider's default dynamic storage class (null = set it yourself). */
    public function defaultStorageClass(): ?string
    {
        return match ($this) {
            self::DOKS => 'do-block-storage',
            self::EKS => 'gp3',
            self::GKE => 'standard',
            self::AKS => 'managed-csi',
            self::CIVO => 'civo-volume',
            self::LKE => 'linode-block-storage',
            self::CUSTOM => null,
        };
    }
    case DOKS = 'doks';
    case EKS = 'eks';
    case GKE = 'gke';
    case AKS = 'aks';
    case CIVO = 'civo';
    case LKE = 'lke';
    case CUSTOM = 'custom';
}
