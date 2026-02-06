<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Api\Response;

use AnyllmCli\Domain\Api\ApiResponseInterface;
use AnyllmCli\Domain\Api\UsageStats;

class GeminiResponse implements ApiResponseInterface
{
    private array $data;
    private ?UsageStats $usage = null;

    public function __construct(string $jsonResponse)
    {
        $this->data = [];
        $fullContent = [];
        $responseChunks = [];

        // The official Gemini API returns a JSON array, not a standard SSE stream.
        // We first try to decode it as a single JSON array.
        $decodedArray = json_decode($jsonResponse, true);

        if (is_array($decodedArray) && (isset($decodedArray[0]['candidates']) || isset($decodedArray[0]['usageMetadata']))) {
            // It's a JSON array of chunks
            $responseChunks = $decodedArray;
        } else {
            // Fallback for a potential SSE-like stream (data: prefix)
            $lines = explode("\n", $jsonResponse);
            foreach ($lines as $line) {
                if (strpos($line, 'data: ') === 0) {
                    $jsonStr = substr($line, 6);
                    $chunk = json_decode($jsonStr, true);
                    if ($chunk) {
                        $responseChunks[] = $chunk;
                    }
                }
            }
        }

        // Process the collected chunks
        foreach ($responseChunks as $chunk) {
            if (isset($chunk['candidates'][0]['content'])) {
                $fullContent[] = $chunk['candidates'][0]['content'];
            }
            // The last chunk in a stream contains the usage metadata
            if (isset($chunk['usageMetadata'])) {
                $this->usage = new UsageStats(
                    $chunk['usageMetadata']['promptTokenCount'] ?? 0,
                    $chunk['usageMetadata']['candidatesTokenCount'] ?? 0
                );
            }
        }

        // Combine the parts from all chunks into a single content structure
        if (!empty($fullContent)) {
            $allParts = [];
            foreach ($fullContent as $content) {
                if(isset($content['parts'])) {
                    $allParts = array_merge($allParts, $content['parts']);
                }
            }
            $this->data['candidates'][0]['content']['parts'] = $allParts;
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

    public function getUsage(): ?UsageStats
    {
        return $this->usage;
    }
}
