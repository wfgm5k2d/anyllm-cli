<?php

declare(strict_types=1);

namespace AnyllmCli\Domain\Api;

interface ApiResponseInterface
{
    public function getMessageContent(): ?string;

    public function getToolCalls(): ?array;

    public function getMessage(): array;

    public function hasToolCalls(): bool;

    public function getUsage(): ?UsageStats;
}
