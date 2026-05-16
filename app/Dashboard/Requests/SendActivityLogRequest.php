<?php

namespace App\Dashboard\Requests;

use App\Dashboard\DashboardRequest;

class SendActivityLogRequest extends DashboardRequest
{
    public function __construct(
        protected string $projectUuid,
        protected string $event,
        protected string $description,
        protected array $properties = []
    ) {}

    public function getEndpoint(): string
    {
        return 'activity-logs';
    }

    public function getData(): array
    {
        return [
            'project_uuid' => $this->projectUuid,
            'event' => $this->event,
            'description' => $this->description,
            'properties' => $this->properties,
        ];
    }
}
