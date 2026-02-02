<?php

namespace AnyllmCli\Application;

use AnyllmCli\Application\Factory\AgentFactory;
use AnyllmCli\Infrastructure\Config\AnylmJsonConfig;
use AnyllmCli\Infrastructure\Terminal\Style;
use AnyllmCli\Infrastructure\Terminal\TerminalManager;
use AnyllmCli\Infrastructure\Terminal\TUI;

class RunCommand
{
    private AnylmJsonConfig $config;
    private TerminalManager $terminalManager;
    private TUI $tui;

    public function __construct()
    {
        // --- Global Handlers ---
        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            if (!(error_reporting() & $errno)) {
                return false;
            }
            Style::errorBox("PHP Error [$errno]: $errstr\nLocation: $errfile:$errline");
            return true;
        });

        set_exception_handler(function ($e) {
            Style::errorBox("Uncaught Exception:\n" . $e->getMessage() . "\nLocation: " . $e->getFile() . ":" . $e->getLine());
            exit(1);
        });
        // ------------------------------------------

        $this->config = new AnylmJsonConfig();
        $this->terminalManager = new TerminalManager();
        $this->tui = new TUI($this->terminalManager, $this->config);

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

        $modelName = $selection['model_name'];
        $providerConfig = $selection['provider_config'];

        Style::info("Using Provider: " . Style::PURPLE . $selection['provider_name'] . Style::RESET);
        Style::info("Using Model:    " . Style::BOLD . $selection['model_key'] . Style::RESET);

        $systemPrompt = $this->getSystemPrompt();
        $agent = AgentFactory::create($providerConfig, $modelName, $systemPrompt);

        $this->startLoop($agent);
    }

    private function startLoop($agent): void
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

            echo PHP_EOL . Style::PURPLE . "✦ " . Style::RESET;

            $agent->execute($processedInput, function ($chunk) {
                echo $chunk;
                if (ob_get_length()) ob_flush();
                flush();
            });

            echo PHP_EOL . PHP_EOL;
        }
    }

    private function getSystemPrompt(): string
    {
        $osInfo = php_uname();
        $cwd = getcwd();
        return <<<PROMPT
You are a powerful AI assistant running in a CLI on a user's local machine. Your primary function is to execute user requests by calling functions to interact with the local filesystem.

**System Information:**
- Operating System: $osInfo
- Current Working Directory: $cwd

**CRITICAL INSTRUCTIONS:**
1.  You MUST use the provided tools (functions) to interact with the filesystem. Do not ask the user for permission; you are expected to use them.
2.  Think step-by-step. When the user gives a command, figure out which tool can fulfill the request and call it with the correct arguments.
3.  If a file path is required, use the relative path from the current working directory.
4.  When you have completed the user's request or have provided the requested information, simply stop. Do not output a summary message like "I have successfully...".
5.  Your responses should be concise and directly related to the task.
PROMPT;
    }
}
