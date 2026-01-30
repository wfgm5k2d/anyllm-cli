<?php

namespace AnyllmCli\Command;

use AnyllmCli\Service\ApiClient;
use AnyllmCli\Service\Config;
use AnyllmCli\Service\DiffService;
use AnyllmCli\Service\ToolExecutor;
use AnyllmCli\Terminal\Style;
use AnyllmCli\Terminal\TerminalManager;
use AnyllmCli\Terminal\TUI;

class RunCommand
{
    private Config $config;
    private TerminalManager $terminalManager;
    private TUI $tui;
    private ApiClient $apiClient;
    private ToolExecutor $toolExecutor;

    private array $history = [];
    private ?string $selectedModelName = null;
    private ?array $activeProviderConfig = null;
    private int $maxAgentLoops = 5;

    public function __construct()
    {
        // --- Глобальные перехватчики ---
        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            if (!($errno & (E_NOTICE | E_DEPRECATED | E_WARNING))) return false;
            Style::errorBox("PHP Error [$errno]: $errstr\nLocation: $errfile:$errline");
            return true;
        });

        set_exception_handler(function ($e) {
            Style::errorBox("Uncaught Exception:\n" . $e->getMessage() . "\nLocation: " . $e->getFile() . ":" . $e->getLine());
            exit(1);
        });
        // ------------------------------------------

        $this->config = new Config();
        $this->terminalManager = new TerminalManager();
        $this->tui = new TUI($this->terminalManager, $this->config);
        $this->apiClient = new ApiClient($this->terminalManager);
        $this->toolExecutor = new ToolExecutor(new DiffService());

        register_shutdown_function([$this->terminalManager, 'restoreMode']);
    }

    public function run(): void
    {
        Style::banner();

        if (empty($this->config->get('provider'))) {
            Style::error("No providers configured in anyllm.json");
            exit(1);
        }

        $selection = $this->tui->selectModelTUI();

        if (!$selection) {
            echo Style::GRAY . "Exit." . Style::RESET . PHP_EOL;
            exit(0);
        }

        $this->selectedModelName = $selection['model_name'];
        $this->activeProviderConfig = $selection['provider_config'];

        Style::info("Using Provider: " . Style::PURPLE . $selection['provider_name'] . Style::RESET);
        Style::info("Using Model:    " . Style::BOLD . $selection['model_key'] . Style::RESET);

        $cwd = getcwd();
        $systemPrompt = <<<PROMPT
You are AnyLLM, an advanced AI coding assistant.
Current Working Directory: $cwd

You have access to the file system using the following TOOL BLOCKS.
To use a tool, output the block exactly as shown.

1. LIST FILES (ls):
   [[LS:path]] (or [[LS]] for current dir)

2. READ FILE:
   [[READ:path/to/file]]

3. CREATE or OVERWRITE FILE (Edit):
   [[FILE:path/to/file]]
   ... content ...
   [[ENDFILE]]

4. SEARCH FILES (grep):
   [[GREP:search term]]

When you use a tool, I will execute it and provide the result. You can then continue your thought.
Don't use tools unless necessary.
PROMPT;

        $this->history[] = ['role' => 'system', 'content' => $systemPrompt];
        echo PHP_EOL;
        $this->startLoop();
    }

    private function startLoop(): void
    {
        while (true) {
            $input = $this->tui->readInputTUI();
            if (empty($input)) continue;

            if (in_array($input, ['/exit', '/quit', 'exit', 'quit'])) {
                echo Style::GRAY . "Goodbye." . Style::RESET . PHP_EOL;
                break;
            }
            if ($input === '/clear') {
                echo "\033[2J\033[H";
                Style::banner();
                continue;
            }

            $processedInput = $this->tui->processInputFiles($input);
            $this->history[] = ['role' => 'user', 'content' => $processedInput];

            $loopCount = 0;
            $keepGoing = true;

            echo PHP_EOL . Style::PURPLE . "✦ " . Style::RESET;

            while ($keepGoing && $loopCount < $this->maxAgentLoops) {
                $responseContent = $this->apiClient->streamResponse(
                    $this->activeProviderConfig,
                    $this->selectedModelName,
                    $this->history
                ) ?? '';
                $this->history[] = ['role' => 'assistant', 'content' => $responseContent];
                $toolOutput = $this->toolExecutor->executeTools($responseContent);

                if (!empty($toolOutput)) {
                    echo PHP_EOL;
                    $this->history[] = ['role' => 'user', 'content' => "Tool Output:\n" . $toolOutput];
                    echo Style::PURPLE . "✦ (Tool Result) " . Style::RESET;
                    $loopCount++;
                } else {
                    $keepGoing = false;
                }
            }
            echo PHP_EOL . PHP_EOL;
        }
    }
}
