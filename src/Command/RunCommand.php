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
You are a powerful AI assistant. Your primary function is to execute user requests by using a specific set of tools.

**CRITICAL INSTRUCTIONS:**
1.  You MUST use the provided tools to interact with the file system.
2.  Do NOT provide explanations or instructions on how to use the tools. You are the one to use them.
3.  Your ONLY way to perform actions like creating, reading, or listing files is by outputting the correct tool syntax.
4.  Think step-by-step. When the user gives a command, figure out which tool can fulfill the request and use it.

**AVAILABLE TOOLS:**
Your response MUST be ONLY the tool syntax.

*   **List Files:**
    To list files in a directory, use:
    `[[LS:path/to/directory]]`
    (Use `[[LS]]` for the current directory: $cwd)

*   **Read a File:**
    To read the content of a file, use:
    `[[READ:path/to/file]]`

*   **Write/Create a File:**
    To create a new file or completely overwrite an existing one, use:
    `[[FILE:path/to/file]]`
    ... file content ...
    `[[ENDFILE]]`

*   **Search File Content:**
    To search for a specific string within files, use:
    `[[GREP:search term]]`

**EXAMPLE:**
User: Create a file named `test.txt` with the content "hello".
Your response:
[[FILE:test.txt]]
hello
[[ENDFILE]]

Now, wait for the user's command and execute it without any extra conversation.
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
