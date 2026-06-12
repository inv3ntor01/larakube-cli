<?php

namespace App\Dashboard;

use Illuminate\Support\Facades\Http;
use Throwable;

class DashboardConnector
{
    protected string $baseUrl = 'https://console.kube';

    public function isUp(): bool
    {
        try {
            return Http::timeout(2)
                ->withoutVerifying()
                ->get("{$this->baseUrl}/up")
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }

    public function send(DashboardRequest $request): bool
    {
        if (! $this->isUp()) {
            return false;
        }

        try {
            return Http::timeout(5)
                ->withoutVerifying()
                ->post("{$this->baseUrl}/api/{$request->getEndpoint()}", $request->getData())
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
