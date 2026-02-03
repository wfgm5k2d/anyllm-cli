<?php

declare(strict_types=1);

namespace AnyllmCli\Application\SlashCommand;

use AnyllmCli\Domain\SlashCommand\SlashCommandInterface;
use AnyllmCli\Application\RunCommand;
use AnyllmCli\Infrastructure\Service\HistorySearchService;
use AnyllmCli\Infrastructure\Terminal\Style;

class SearchHistoryCommand implements SlashCommandInterface
{
    public function getName(): string
    {
        return '/search_history';
    }

    public function getDescription(): string
    {
        return 'Search through past sessions. Usage: /search_history <query>';
    }

    public function execute(array $args, RunCommand $mainApp): void
    {
        $query = implode(' ', $args);
        if (empty($query)) {
            Style::error("Search query is required. Usage: /search_history <query>");
            return;
        }

        Style::info("Searching history for: '{$query}'...");

        $searchService = new HistorySearchService(getcwd());
        $results = $searchService->search($query);

        if (empty($results)) {
            Style::info("No relevant history found.");
            return;
        }

        echo PHP_EOL . Style::BOLD . Style::PURPLE . "Found " . count($results) . " relevant entries from past sessions:" . Style::RESET . PHP_EOL;
        echo "--------------------------------------------------" . PHP_EOL;
        foreach ($results as $line) {
            $episode = json_decode($line, true);
            if (!$episode) continue;

            echo Style::GRAY . "Session: " . $episode['session_id'] . " (" . $episode['timestamp'] . ")" . Style::RESET . PHP_EOL;
            foreach ($episode['turns'] as $turn) {
                echo Style::BLUE . "User: " . Style::RESET . $turn['user'] . PHP_EOL;
                echo Style::PURPLE . "Assistant: " . Style::RESET . ($turn['assistant'] ? substr($turn['assistant'], 0, 200) . "..." : "[No response]") . PHP_EOL;
            }
            echo "--------------------------------------------------" . PHP_EOL;
        }
    }
}
