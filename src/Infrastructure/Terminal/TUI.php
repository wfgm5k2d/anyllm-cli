<?php

namespace AnyllmCli\Infrastructure\Terminal;

use AnyllmCli\Infrastructure\Config\AnylmJsonConfig;
use AnyllmCli\Application\SlashCommand\SlashCommandRegistry;
use AnyllmCli\Infrastructure\Service\SignalManager;

class TUI
{
    private TerminalManager $terminalManager;
    private AnylmJsonConfig $config;
    private SlashCommandRegistry $commandRegistry;
    private array $projectFiles;

    // UI State
    private string $buffer = "";
    private int $cursorPos = 0;
    private bool $isMenuVisible = false;
    private array $currentSuggestions = [];
    private int $menuSelectedIndex = 0;
    private int $terminalWidth = 80;
    private string $menuType = ''; // To distinguish between '@' and '/'

    public function __construct(TerminalManager $terminalManager, AnylmJsonConfig $config, SlashCommandRegistry $commandRegistry, array $projectFiles = [])
    {
        $this->terminalManager = $terminalManager;
        $this->config = $config;
        $this->commandRegistry = $commandRegistry;
        $this->projectFiles = $projectFiles;
        $cols = shell_exec('tput cols');
        if ($cols) {
            $this->terminalWidth = (int)$cols;
        }
    }

    public function selectModelTUI(): ?array
    {
        $flatList = [];
        foreach ($this->config->get('provider', []) as $provKey => $provData) {
            if (!isset($provData['models'])) continue;
            foreach ($provData['models'] as $modelKey => $modelData) {
                $flatList[] = [
                    'provider_key' => $provKey,
                    'provider_name' => $provData['name'],
                    'provider_config' => array_merge($provData['options'] ?? [], ['type' => $provData['type'] ?? 'openai']),
                    'model_key' => $modelKey,
                    'model_name' => $modelData['name'],
                    'model_config' => $modelData,
                ];
            }
        }

        if (empty($flatList)) {
            Style::error("No models found.");
            exit(1);
        }
        if (count($flatList) === 1) return $flatList[0];

        $selectedIndex = 0;
        $this->terminalManager->setRawMode();
        Style::hideCursor();

        echo "Select AI Model:\n";

        while (true) {
            $buffer = "";
            $linesPrinted = 0;

            $currentProvider = null;

            foreach ($flatList as $idx => $item) {
                if ($item['provider_key'] !== $currentProvider) {
                    if ($idx > 0) {
                        $buffer .= PHP_EOL;
                        $linesPrinted++;
                    }
                    $buffer .= Style::PURPLE . Style::BOLD . $item['provider_name'] . Style::RESET . PHP_EOL;
                    $linesPrinted++;
                    $currentProvider = $item['provider_key'];
                }

                $prefix = ($idx === $selectedIndex) ? Style::BLUE . " > " : "   ";

                if ($idx === $selectedIndex) {
                    $line = $prefix . Style::BOLD . Style::WHITE . $item['model_key'] . Style::RESET . Style::GRAY . " (" . $item['model_name'] . ")" . Style::RESET;
                } else {
                    $line = $prefix . $item['model_key'];
                }

                $buffer .= $line . PHP_EOL;
                $linesPrinted++;
            }

            echo $buffer;

            $c = fread(STDIN, 1);

            echo "\033[{$linesPrinted}A";
            echo "\033[J";

            $ord = ord($c);

            if ($ord === 27) {
                $seq = fread(STDIN, 2);
                if ($seq === '[A') {
                    $selectedIndex--;
                    if ($selectedIndex < 0) $selectedIndex = count($flatList) - 1;
                } elseif ($seq === '[B') {
                    $selectedIndex++;
                    if ($selectedIndex >= count($flatList)) $selectedIndex = 0;
                }
            } elseif ($ord === 10 || $ord === 13) {
                break;
            } elseif ($ord >= 49 && $ord <= 57) {
                $n = $ord - 49;
                if (isset($flatList[$n])) {
                    $selectedIndex = $n;
                    break;
                }
            } elseif ($ord === 3) {
                $this->terminalManager->restoreMode();
                exit(0);
            }
        }

        $this->terminalManager->restoreMode();
        return $flatList[$selectedIndex];
    }

    public function readInputTUI(): ?string
    {
        $this->buffer = "";
        $this->cursorPos = 0;
        $this->menuSelectedIndex = 0;
        $this->terminalManager->setRawMode();
        $this->redraw();

        while (true) {
            // Dispatch any pending signals
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // Check for signals captured by the main signal handler
            if (SignalManager::$sigintCount > 0) {
                SignalManager::$sigintCount = 0; // Consume the signal

                static $lastPressTime = 0;
                $currentTime = time();
                if ($currentTime - $lastPressTime < 2) {
                    $this->terminalManager->restoreMode();
                    echo PHP_EOL . Style::GRAY . "Goodbye." . Style::RESET . PHP_EOL;
                    exit(0);
                }
                $lastPressTime = $currentTime;

                $this->clearMenuArea();
                echo "\r\033[K" . Style::YELLOW . "Press Ctrl+C again to exit." . Style::RESET;
                sleep(1);
                $this->redraw();
                continue;
            }

            // Wait for input on STDIN with a timeout
            $read = [STDIN];
            $write = null;
            $except = null;
            $char = '';

            // Use stream_select to wait for input without blocking the whole script
            // Suppress warning on interrupted system call, which is expected on SIGINT
            $result = @stream_select($read, $write, $except, 0, 200000);

            if ($result === false) {
                // Interrupted by a signal, loop again to dispatch and check flags.
                continue;
            }
            if ($result > 0) {
                $char = fread(STDIN, 1);
            } else {
                // Timeout, loop again
                continue;
            }

            if ($char === '') continue;

            $ord = ord($char);

            if ($ord === 27) { // Arrow keys, etc.
                $seq = fread(STDIN, 2);
                if ($seq === '[A') $this->navigateMenu(-1);
                elseif ($seq === '[B') $this->navigateMenu(1);
                elseif ($seq === '[D') $this->cursorPos = max(0, $this->cursorPos - 1);
                elseif ($seq === '[C') $this->cursorPos = min(mb_strlen($this->buffer), $this->cursorPos + 1);
                elseif ($seq === '[3' && fread(STDIN, 1) === '~') {
                    if ($this->cursorPos < mb_strlen($this->buffer)) {
                        $this->buffer = mb_substr($this->buffer, 0, $this->cursorPos) . mb_substr($this->buffer, $this->cursorPos + 1);
                    }
                }
                $this->redraw();
                continue;
            }
            if ($ord === 10 || $ord === 13) { // Enter key
                $this->terminalManager->restoreMode();
                $this->clearMenuArea();
                echo PHP_EOL;
                return trim($this->buffer);
            }
            if ($ord === 9) { // Tab key
                $this->applyAutocomplete();
                $this->redraw();
                continue;
            }
            if ($ord === 127 || $ord === 8) { // Backspace
                if ($this->cursorPos > 0) {
                    $this->buffer = mb_substr($this->buffer, 0, $this->cursorPos - 1) . mb_substr($this->buffer, $this->cursorPos);
                    $this->cursorPos--;
                    $this->menuSelectedIndex = 0;
                }
            } else {
                if ($ord >= 192) { // Handle multi-byte characters
                    $bytes = ($ord >= 240) ? 3 : (($ord >= 224) ? 2 : 1);
                    $char .= fread(STDIN, $bytes);
                }
                if ($ord >= 32) { // Printable characters
                    $this->buffer = mb_substr($this->buffer, 0, $this->cursorPos) . $char . mb_substr($this->buffer, $this->cursorPos);
                    $this->cursorPos++;
                    $this->menuSelectedIndex = 0;
                }
            }
            $this->redraw();
        }
    }

    private function redraw(): void
    {
        Style::hideCursor();
        echo "\r\033[K";
        Style::prompt();
        $this->renderHighlightedBuffer();
        $this->prepareSuggestions();
        if ($this->isMenuVisible) {
            echo PHP_EOL;
            foreach ($this->currentSuggestions as $index => $item) {
                echo "\033[K";
                $icon = $item['icon'] ?? ' ';
                $text = $icon . $item['display'];
                $description = $item['description'] ?? '';
                if ($description) {
                    $text .= Style::GRAY . " - " . $description . Style::RESET;
                }
                
                echo ($index === $this->menuSelectedIndex) ? Style::BG_SELECTED . Style::WHITE . "  $text  " . Style::RESET : Style::GRAY . "  $text" . Style::RESET;
                echo PHP_EOL;
            }
            echo "\033[J";
            $lines = count($this->currentSuggestions) + 1;
            echo "\033[{$lines}A";
        } else {
            echo "\n\033[J\033[1A";
        }
        $cursorCol = 2 + mb_strwidth(mb_substr($this->buffer, 0, $this->cursorPos)) + 1;
        echo "\033[{$cursorCol}G";
        Style::showCursor();
    }

    private function clearMenuArea(): void
    {
        if ($this->isMenuVisible) {
            echo "\n\033[J\033[1A\r\033[K";
            Style::prompt();
            $this->renderHighlightedBuffer();
        }
    }

    private function renderHighlightedBuffer(): void
    {
        // Highlight @mentions and /commands (only the command word)
        $pattern = '/(@\S+|^\/\S+)/u';
        echo preg_replace_callback($pattern, function ($m) {
            return Style::BLUE . $m[0] . Style::RESET;
        }, $this->buffer);
    }

    private function prepareSuggestions(): void
    {
        if (preg_match('/@([^\s]*)$/u', $this->buffer, $matches)) {
            $this->isMenuVisible = true;
            $this->menuType = '@';
            $this->scanFiles($matches[1]);
        } elseif (preg_match('/^\/([^\s]*)$/u', $this->buffer, $matches)) {
            $this->isMenuVisible = true;
            $this->menuType = '/';
            $this->scanCommands($matches[1]);
        } else {
            $this->isMenuVisible = false;
            $this->menuType = '';
        }
    }

    private function scanFiles(string $searchTerm): void
    {
        if (empty($searchTerm)) {
            $this->isMenuVisible = false;
            return;
        }

        $suggestions = [];
        foreach ($this->projectFiles as $file) {
            if (str_contains(strtolower($file), strtolower($searchTerm))) {
                $suggestions[] = [
                    'name' => $file,
                    'display' => $file, // Display the full relative path
                    'is_dir' => false,
                ];
            }
        }

        $this->currentSuggestions = array_slice($suggestions, 0, 7);
        if (empty($this->currentSuggestions)) {
            $this->isMenuVisible = false;
        }
    }

    private function scanCommands(string $searchTerm): void
    {
        $commands = $this->commandRegistry->getAllCommands();

        $suggestions = [];
        foreach ($commands as $command) {
            $commandNameOnly = substr($command->getName(), 1);
            $match = ($searchTerm === '' || str_starts_with($commandNameOnly, $searchTerm));

            if ($match) {
                $suggestions[] = [
                    'name' => $command->getName(),
                    'display' => $command->getName(),
                    'description' => $command->getDescription(),
                    'icon' => '',
                ];
            }
        }

        $this->currentSuggestions = array_slice($suggestions, 0, 7);
        if (empty($this->currentSuggestions)) {
            $this->isMenuVisible = false;
        }
    }

    private function navigateMenu(int $d): void
    {
        if (!$this->isMenuVisible) return;
        $this->menuSelectedIndex += $d;
        $count = count($this->currentSuggestions);
        if ($this->menuSelectedIndex < 0) $this->menuSelectedIndex = $count - 1;
        if ($this->menuSelectedIndex >= $count) $this->menuSelectedIndex = 0;
    }

    private function applyAutocomplete(): void
    {
        if (!$this->isMenuVisible || empty($this->currentSuggestions)) return;
        
        $selected = $this->currentSuggestions[$this->menuSelectedIndex];
        $searchTerm = ($this->menuType === '@') ? '@' : '/';
        $pos = mb_strrpos($this->buffer, $searchTerm);

        $prefix = '';
        if ($this->menuType === '@') {
            $prefix = '@';
        }

        $this->buffer = mb_substr($this->buffer, 0, $pos) . $prefix . $selected['name'] . " ";
        $this->cursorPos = mb_strlen($this->buffer);
        $this->isMenuVisible = false;
    }

    public function processInputFiles(string $input): string
    {
        return preg_replace_callback('/@(\S+)/', function ($m) {
            $path = getcwd() . '/' . $m[1];
            if (file_exists($path) && !is_dir($path)) {
                Style::info("  [Loaded: $m[1]]");
                return "\nFile: $m[1]\n```\n" . file_get_contents($path) . "\n```\n";
            }
            return $m[0];
        }, $input);
    }
}
