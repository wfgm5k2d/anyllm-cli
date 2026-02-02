<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Api\Response;

use AnyllmCli\Domain\Api\ApiResponseInterface;
use Exception;

class OpenAiResponse implements ApiResponseInterface
{
    private array $data;

    public function __construct(string $jsonResponse)
    {
        $this->data = json_decode($jsonResponse, true) ?? [];
    }

    public function getMessageContent(): ?string
    {
        return $this->data['choices'][0]['message']['content'] ?? null;
    }

    public function getToolCalls(): ?array
    {
        return $this->data['choices'][0]['message']['tool_calls'] ?? null;
    }

    public function getMessage(): array
    {
        return $this->data['choices'][0]['message'] ?? [];
    }

    public function hasToolCalls(): bool
    {
        return isset($this->data['choices'][0]['message']['tool_calls']);
    }
}
