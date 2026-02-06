<?php

declare(strict_types=1);

namespace AnyllmCli\Domain\Api;

class UsageStats
{
    public int $promptTokens;
    public int $completionTokens;
    public int $totalTokens;

    public function __construct(int $promptTokens = 0, int $completionTokens = 0)
    {
        $this->promptTokens = $promptTokens;
        $this->completionTokens = $completionTokens;
        $this->totalTokens = $promptTokens + $completionTokens;
    }

    public function add(UsageStats $other): void
    {
        $this->promptTokens += $other->promptTokens;
        $this->completionTokens += $other->completionTokens;
        $this->totalTokens += $other->totalTokens;
    }
}
