<?php

namespace App\Dashboard;

abstract class DashboardRequest
{
    abstract public function getEndpoint(): string;

    abstract public function getData(): array;
}
