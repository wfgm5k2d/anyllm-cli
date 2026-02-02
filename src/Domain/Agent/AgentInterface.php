<?php

declare(strict_types=1);

namespace AnyllmCli\Domain\Agent;

interface AgentInterface
{
    public function execute(string $prompt, callable $onProgress): void;
}
