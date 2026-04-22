<?php

namespace App\Enums;

use App\Actions\Contracts\FeatureAction;
use App\Actions\Features\MonitoringAction;
use App\Actions\Features\OctaneAction;
use App\Actions\Features\ReverbAction;
use App\Actions\Queues\HorizonAction;
use App\Actions\Queues\QueueAction;
use App\Actions\Queues\TaskSchedulingAction;
use App\Actions\Search\ScoutAction;

enum LaravelFeature: string
{
    case TASK_SCHEDULING = 'Task Scheduling';
    case HORIZON = 'Horizon (with Redis)';
    case QUEUES = 'Queues (without Redis)';
    case REVERB = 'Reverb';
    case SCOUT = 'Laravel Scout';
    case OCTANE = 'Octane (requires FrankenPHP)';
    case MONITORING = 'Monitoring (Prometheus & Grafana)';
    case METALLB = 'MetalLB (LoadBalancer Provider)';

    public function action(): FeatureAction
    {
        return match ($this) {
            self::TASK_SCHEDULING => new TaskSchedulingAction,
            self::HORIZON => new HorizonAction,
            self::QUEUES => new QueueAction,
            self::REVERB => new ReverbAction,
            self::SCOUT => new ScoutAction,
            self::OCTANE => new OctaneAction,
            self::MONITORING => new MonitoringAction,
            self::METALLB => new MetalLbAction,
        };
    }
}
