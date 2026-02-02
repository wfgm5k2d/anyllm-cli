<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Api\Response;

use AnyllmCli\Domain\Api\ApiResponseInterface;

class GeminiResponse implements ApiResponseInterface
{
    private array $data;

    public function __construct(string $jsonResponse)
    {
        $this->data = [];
        $lines = explode("\n", $jsonResponse);

        $fullContent = [];

        foreach ($lines as $line) {
            if (strpos($line, 'data: ') === 0) {
                $jsonStr = substr($line, 6);
                $chunk = json_decode($jsonStr, true);

                if (isset($chunk['candidates'][0]['content'])) {
                    $fullContent[] = $chunk['candidates'][0]['content'];
                }
            }
        }

        // Combine the parts from all chunks into a single content structure
        if (!empty($fullContent)) {
            $this->data['candidates'][0]['content']['parts'] = array_merge(...array_column($fullContent, 'parts'));
            $this->data['candidates'][0]['content']['role'] = 'model';
        }
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
