<?php

declare(strict_types=1);

namespace AnyllmCli\Application\Agent;

use AnyllmCli\Domain\Agent\AgentInterface;
use AnyllmCli\Domain\Api\ApiClientInterface;
use AnyllmCli\Domain\Session\SessionContext;
use AnyllmCli\Infrastructure\Service\EditBlockParser;

/**
 * An agent for "small" models that do not support function calling (tools).
 * It interacts with the model via pure text and parses the response for specific
 * text-based commands to manipulate files (e.g., SEARCH/REPLACE blocks).
 */
class SmallAgent implements AgentInterface
{
    public function __construct(
        protected ApiClientInterface $apiClient,
        protected EditBlockParser $editBlockParser,
        string $systemPrompt,
        protected SessionContext $sessionContext,
        protected int $maxIterations = 1 // Small models usually respond in one go
    ) {
        // Ensure system prompt is the first message, and only if history is empty or different.
        if (empty($this->sessionContext->conversation_history) || $this->sessionContext->conversation_history[0]['content'] !== $systemPrompt) {
            $this->sessionContext->conversation_history = [['role' => 'system', 'content' => $systemPrompt]];
        }
    }

    public function execute(string $prompt, callable $onProgress): void
    {
        // For small models, the "turn" is the entire conversation history up to this point.
        $messagesForThisTurn = $this->sessionContext->conversation_history;
        $messagesForThisTurn[] = ['role' => 'user', 'content' => $prompt];

        // Add the user prompt to the permanent history for logging/saving purposes.
        $this->sessionContext->conversation_history[] = ['role' => 'user', 'content' => $prompt];

        // Small models don't loop with tools. We expect a single, complete response.
        $response = $this->apiClient->chat(
            $messagesForThisTurn,
            [], // No tools for small models
            $onProgress
        );

        $finalAssistantContent = $response->getMessageContent();
        $this->sessionContext->conversation_history[] = $response->getMessage();

        if ($finalAssistantContent) {
            $onProgress(PHP_EOL); // Ensure we're on a new line before parsing
            $parserOutput = $this->editBlockParser->applyEdits($finalAssistantContent);

            // Log the outcome of the parsing for context
            $this->updateCurrentContext($finalAssistantContent, $parserOutput);
            $this->summarizeEpisode($prompt, $parserOutput ?: $finalAssistantContent);
        } else {
            $this->summarizeEpisode($prompt, "No content in assistant's response.");
        }
    }

    private function updateCurrentContext(string $llmResponse, string $parserOutput): void
    {
        $this->sessionContext->current = [
            'last_action' => 'text_command_generation',
            'last_result' => empty($parserOutput) ? 'NO_OP' : 'SUCCESS',
            'last_file' => null, // Could be enhanced by parsing file from output
        ];
    }

    private function summarizeEpisode(string $prompt, string $outcome): void
    {
        $this->sessionContext->summarized_history[] = [
            'request' => $prompt,
            'outcome' => $outcome,
            'timestamp' => date('c'),
        ];
    }
}
