<?php

declare(strict_types=1);

namespace AnyllmCli\Application\SlashCommand;

use AnyllmCli\Domain\SlashCommand\SlashCommandInterface;
use AnyllmCli\Application\RunCommand;
use AnyllmCli\Infrastructure\Terminal\Style;

class SummarizeCommand implements SlashCommandInterface
{
    public function getName(): string
    {
        return '/summarize';
    }

    public function getDescription(): string
    {
        return 'Analyze conversation and extract key decisions.';
    }

    public function execute(array $args, RunCommand $mainApp): void
    {
        Style::info("Analyzing conversation to extract key decisions...");

        $history = $mainApp->getSessionContext()->conversation_history;
        if (count($history) < 2) {
            Style::error("Not enough conversation history to summarize.");
            return;
        }

        // Format history for analysis
        $formattedHistory = "";
        foreach ($history as $message) {
            if ($message['role'] === 'user') {
                $formattedHistory .= "User: " . $message['content'] . "\n";
            } elseif ($message['role'] === 'assistant' && $message['content']) {
                $formattedHistory .= "Assistant: " . $message['content'] . "\n";
            }
        }

        $prompt = $this->getSummarizationPrompt($formattedHistory);
        
        // The command needs an API client. It will create it on the fly.
        $providerConfig = $mainApp->getActiveProviderConfig();
        $modelName = $mainApp->getActiveModelName();

        if (!$providerConfig || !$modelName) {
            Style::error("Cannot summarize because no active model is selected.");
            return;
        }

        $apiClient = ($providerConfig['type'] === 'google')
            ? new \AnyllmCli\Infrastructure\Api\Adapter\GeminiClient($providerConfig, $modelName)
            : new \AnyllmCli\Infrastructure\Api\Adapter\OpenAiClient($providerConfig, $modelName);

        $decisionData = $apiClient->simpleChat($prompt);

        if ($decisionData && is_array($decisionData)) {
            $mainApp->getSessionContext()->decisions = [
                'constraints' => $decisionData['constraints'] ?? [],
                'decisions' => $decisionData['decisions'] ?? [],
                'qa' => $decisionData['qa'] ?? [],
            ];
            $this->displaySummary($mainApp->getSessionContext()->decisions);
        } else {
            Style::error("Failed to extract decisions from the conversation.");
        }
    }

    private function getSummarizationPrompt(string $conversationLog): array
    {
        $prompt = <<<PROMPT
You are a conversation summarization assistant. Analyze the following conversation between a user and an AI assistant. Your goal is to extract key decisions, constraints, and questions/answers. Respond ONLY with a valid JSON object with three keys: "constraints", "decisions", and "qa".
- "constraints": An array of strings. List all limitations and requirements imposed (e.g., "must use Python 3.10+", "no external libraries").
- "decisions": An array of strings. List all key architectural or implementation choices made (e.g., "use SQLite for storage", "implement a RESTful API").
- "qa": An array of objects, each with a "q" (question) and "a" (answer) key. List important questions and their direct answers.

Here is the conversation history:
{$conversationLog}

Respond only with the JSON object.
PROMPT;
        return [
            ['role' => 'system', 'content' => 'You are a helpful assistant designed to output JSON.'],
            ['role' => 'user', 'content' => $prompt]
        ];
    }

    private function displaySummary(array $decisions): void
    {
        echo PHP_EOL . Style::BOLD . Style::PURPLE . "Conversation Summary:" . Style::RESET . PHP_EOL;

        if (!empty($decisions['constraints'])) {
            echo Style::YELLOW . "Constraints:" . Style::RESET . PHP_EOL;
            foreach ($decisions['constraints'] as $item) {
                echo "  - " . $item . PHP_EOL;
            }
        }
        if (!empty($decisions['decisions'])) {
            echo Style::YELLOW . "Decisions:" . Style::RESET . PHP_EOL;
            foreach ($decisions['decisions'] as $item) {
                echo "  - " . $item . PHP_EOL;
            }
        }
        if (!empty($decisions['qa'])) {
            echo Style::YELLOW . "Q&A:" . Style::RESET . PHP_EOL;
            foreach ($decisions['qa'] as $item) {
                echo "  Q: " . $item['q'] . PHP_EOL;
                echo "  A: " . $item['a'] . PHP_EOL;
            }
        }
        echo PHP_EOL;
    }
}
