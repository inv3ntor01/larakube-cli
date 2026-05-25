<?php

namespace App\Enums;

use App\Contracts\HasLabel;
use App\Contracts\HasSelectOptions;
use App\Traits\ProvidesSelectOptions;

enum IngressController: string implements HasLabel, HasSelectOptions
{
    use ProvidesSelectOptions;

    case TRAEFIK = 'traefik';
    case AWS_ALB = 'aws-alb';
    case NGINX = 'nginx';

    public function getLabel(): string
    {
        return match ($this) {
            self::TRAEFIK => 'Traefik (LaraKube Default)',
            self::AWS_ALB => 'AWS Application Load Balancer (EKS Standard)',
            self::NGINX => 'NGINX Ingress Controller (AKS/DigitalOcean Standard)',
        };
    }

    public function getAnnotationView(): ?string
    {
        return match ($this) {
            self::AWS_ALB => 'k8s.overlays.production.ingress.aws_alb',
            self::NGINX => 'k8s.overlays.production.ingress.nginx',
            default => null,
        };
    }

    public function getIngressClass(): ?string
    {
        return match ($this) {
            self::AWS_ALB => 'alb',
            self::NGINX => 'nginx',
            default => null,
        };
    }
}
