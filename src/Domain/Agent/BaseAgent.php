<?php

declare(strict_types=1);

namespace AnyllmCli\Domain\Agent;

use AnyllmCli\Domain\Api\ApiClientInterface;
use AnyllmCli\Domain\Tool\ToolRegistryInterface;
use AnyllmCli\Infrastructure\Terminal\Style;

abstract class BaseAgent implements AgentInterface
{
    protected array $history = [];
    protected int $maxIterations = 10;

    public function __construct(
        protected ApiClientInterface $apiClient,
        protected ToolRegistryInterface $toolRegistry,
        string $systemPrompt
    ) {
        $this->history[] = ['role' => 'system', 'content' => $systemPrompt];
    }

    public function execute(string $prompt, callable $onProgress): void
    {
        $this->history[] = ['role' => 'user', 'content' => $prompt];

        $loopCount = 0;
        $keepGoing = true;

        while ($keepGoing && $loopCount < $this->maxIterations) {
            $loopCount++;

            $response = $this->apiClient->chat(
                $this->history,
                $this->toolRegistry->getToolsAsJsonSchema(),
                $onProgress
            );

            if (!$response->hasToolCalls()) {
                // No tool calls, so it's the final answer.
                $keepGoing = false;
                // The onProgress callback already handled the streaming output.
                continue;
            }

            $this->history[] = $response->getMessage();
            $toolCalls = $response->getToolCalls();

            $onProgress(PHP_EOL);

            foreach ($toolCalls as $toolCall) {
                $toolName = $toolCall['function']['name'];
                $arguments = json_decode($toolCall['function']['arguments'], true);

                Style::tool("Using tool: " . Style::BOLD . $toolName . Style::RESET);

                $tool = $this->toolRegistry->getTool($toolName);

                if ($tool) {
                    $toolOutput = $tool->execute($arguments);
                    $this->history[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'],
                        'name' => $toolName,
                        'content' => $toolOutput,
                    ];
                    // Display tool output to the user
                    echo Style::GRAY . "│ Tool Output: " . trim($toolOutput) . Style::RESET . PHP_EOL;
                } else {
                    $this->history[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'],
                        'name' => $toolName,
                        'content' => "Error: Tool '{$toolName}' not found.",
                    ];
                    Style::error("Tool '{$toolName}' not found.");
                }
            }
        }

        if ($loopCount >= $this->maxIterations) {
            Style::error("Agent reached maximum number of iterations ({$this->maxIterations}).");
        }
    }
}
