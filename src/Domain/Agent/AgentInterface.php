<?php

declare(strict_types=1);

namespace AnyllmCli\Domain\Agent;

use AnyllmCli\Domain\Api\UsageStats;

interface AgentInterface
{
    public function execute(string $prompt, callable $onProgress): ?UsageStats;
}
