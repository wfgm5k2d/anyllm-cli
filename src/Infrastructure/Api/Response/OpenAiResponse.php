<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Api\Response;

use AnyllmCli\Domain\Api\ApiResponseInterface;
use AnyllmCli\Domain\Api\UsageStats;

class OpenAiResponse implements ApiResponseInterface
{
    private array $data;
    private ?UsageStats $usage = null;

    public function __construct(string $jsonResponse)
    {
        $this->data = [];
        $lines = explode("\n", $jsonResponse);

        $finalMessage = [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [],
        ];

        foreach ($lines as $line) {
            if (strpos($line, 'data: ') === 0) {
                $jsonStr = substr($line, 6);
                if (trim($jsonStr) === '[DONE]') {
                    continue;
                }

                $chunk = json_decode($jsonStr, true);
                if (!$chunk) {
                    continue;
                }

                // Capture usage statistics, which often come in a late chunk
                if (isset($chunk['usage'])) {
                    $this->usage = new UsageStats(
                        $chunk['usage']['prompt_tokens'] ?? 0,
                        $chunk['usage']['completion_tokens'] ?? 0
                    );
                }
                
                $delta = $chunk['choices'][0]['delta'] ?? null;

                if (!$delta) {
                    continue;
                }

                // Accumulate content
                if (!empty($delta['content'])) {
                    if ($finalMessage['content'] === null) {
                        $finalMessage['content'] = '';
                    }
                    $finalMessage['content'] .= $delta['content'];
                }

                // Accumulate tool calls
                if (!empty($delta['tool_calls'])) {
                    foreach ($delta['tool_calls'] as $toolCallChunk) {
                        $index = $toolCallChunk['index'];
                        if (!isset($finalMessage['tool_calls'][$index])) {
                            // First time seeing this tool call, initialize it
                            $finalMessage['tool_calls'][$index] = [
                                'id' => '',
                                'type' => 'function',
                                'function' => ['name' => '', 'arguments' => '']
                            ];
                        }
                        // Append parts of the tool call as they stream in
                        if (!empty($toolCallChunk['id'])) {
                            $finalMessage['tool_calls'][$index]['id'] = $toolCallChunk['id'];
                        }
                        if (!empty($toolCallChunk['function']['name'])) {
                            $finalMessage['tool_calls'][$index]['function']['name'] .= $toolCallChunk['function']['name'];
                        }
                        if (!empty($toolCallChunk['function']['arguments'])) {
                            $finalMessage['tool_calls'][$index]['function']['arguments'] .= $toolCallChunk['function']['arguments'];
                        }
                    }
                }
            }
        }

        // Clean up the final message
        if (empty($finalMessage['tool_calls'])) {
            unset($finalMessage['tool_calls']);
        } else {
            // Re-index the array to be a clean list
            $finalMessage['tool_calls'] = array_values($finalMessage['tool_calls']);
            // Content is usually null when tool calls are present
            $finalMessage['content'] = null;
        }


        $this->data['choices'][0]['message'] = $finalMessage;
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

    public function getUsage(): ?UsageStats
    {
        return $this->usage;
    }
}
