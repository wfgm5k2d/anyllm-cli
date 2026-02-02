<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Api\Response;

use AnyllmCli\Domain\Api\ApiResponseInterface;

class GeminiResponse implements ApiResponseInterface
{
    private array $data;

    public function __construct(string $jsonResponse)
    {
        // Gemini streams multiple JSON objects, we only care about the last one
        $jsonObjects = explode('data: ', $jsonResponse);
        $lastJson = trim(end($jsonObjects));
        $this->data = json_decode($lastJson, true) ?? [];
    }

    public function getMessageContent(): ?string
    {
        $parts = $this->data['candidates'][0]['content']['parts'] ?? [];
        foreach ($parts as $part) {
            if (isset($part['text'])) {
                return $part['text'];
            }
        }
        return null;
    }

    public function getToolCalls(): ?array
    {
        if (!$this->hasToolCalls()) {
            return null;
        }

        $toolCalls = [];
        $parts = $this->data['candidates'][0]['content']['parts'];

        foreach ($parts as $part) {
            if (isset($part['functionCall'])) {
                $functionCall = $part['functionCall'];
                $toolCalls[] = [
                    // Create an OpenAI-compatible structure
                    'id' => 'call_' . uniqid(),
                    'type' => 'function',
                    'function' => [
                        'name' => $functionCall['name'],
                        'arguments' => json_encode($functionCall['args'] ?? []),
                    ],
                ];
            }
        }

        return $toolCalls;
    }

    public function getMessage(): array
    {
        $message = [
            'role' => 'assistant',
            'content' => null,
        ];

        if ($this->hasToolCalls()) {
            $message['tool_calls'] = $this->getToolCalls();
        } else {
            $message['content'] = $this->getMessageContent();
        }

        return $message;
    }

    public function hasToolCalls(): bool
    {
        $parts = $this->data['candidates'][0]['content']['parts'] ?? [];
        foreach ($parts as $part) {
            if (isset($part['functionCall'])) {
                return true;
            }
        }
        return false;
    }
}
