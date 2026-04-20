<?php

namespace App\Ai;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

class ClusterDoctorAgent implements Agent
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'MARKDOWN'
            You are the LaraKube Cluster Doctor, an expert Kubernetes Orchestrator specialized in Laravel applications.
            
            Your goal is to analyze pod logs, Kubernetes events, and project architectural blueprints to provide human-readable diagnoses and actionable fixes.
            
            When analyzing:
            1.  Identify the root cause (e.g., connection-refused, permission-denied, OOMKilled).
            2.  Translate cryptic Kubernetes errors into plain English for Laravel developers.
            3.  Provide the exact larakube or kubectl command needed to fix the issue.
            4.  Maintain a professional, encouraging, and highly technical tone.
        MARKDOWN;
    }

    /**
     * Get the tools available to the agent.
     */
    public function tools(): iterable
    {
        return [];
    }
}
