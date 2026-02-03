<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Terminal;

class Style
{
    const string RESET = "\033[0m";
    const string BOLD = "\033[1m";
    const string BLUE = "\033[38;5;39m";
    const string PURPLE = "\033[38;5;135m";
    const string GRAY = "\033[90m";
    const string RED = "\033[31m";
    const string GREEN = "\033[32m";
    const string YELLOW = "\033[33m";
    const string WHITE = "\033[97m";
    const string CYAN = "\033[36m";

    const string BG_SELECTED = "\033[48;5;236m";

    public static function clearLine(): void
    { echo "\r\033[K"; }
    public static function success($text): void
    { echo self::GREEN . "✓ " . $text . self::RESET . PHP_EOL; }
    public static function hideCursor(): void
    { echo "\033[?25l"; }
    public static function showCursor(): void
    { echo "\033[?25h"; }

    public static function banner(): void
    {
        $art = <<<ART
\033[38;5;39m  ▒▒▒▒▒   ▒▒   ▒▒  ▒▒   ▒▒  ▒▒       ▒▒       ▒▒   ▒▒
\033[38;5;45m ▒▒   ▒▒  ▒▒▒  ▒▒  ▒▒   ▒▒  ▒▒       ▒▒       ▒▒▒ ▒▒▒
\033[38;5;51m ▒▒▒▒▒▒▒  ▒▒ ▒ ▒▒   ▒▒▒▒▒   ▒▒       ▒▒       ▒▒ ▒ ▒▒
\033[38;5;135m ▒▒   ▒▒  ▒▒  ▒▒▒    ▒▒▒    ▒▒       ▒▒       ▒▒   ▒▒
\033[38;5;141m ▒▒   ▒▒  ▒▒   ▒▒    ▒▒     ▒▒▒▒▒▒▒  ▒▒▒▒▒▒▒  ▒▒   ▒▒
ART;
        echo self::BOLD . $art . self::RESET . PHP_EOL . PHP_EOL;
        echo self::GRAY . "  AnyLLM v2.5 • Robust AI Terminal" . self::RESET . PHP_EOL . PHP_EOL;
    }

    public static function prompt(): void
    { echo self::BLUE . "> " . self::RESET; }
    public static function info($text): void
    { echo self::GRAY . "• " . $text . self::RESET . PHP_EOL; }
    public static function tool($text): void
    { echo self::YELLOW . "🛠  " . $text . self::RESET . PHP_EOL; }
    public static function error($text): void
    { echo self::RED . "Error: " . $text . self::RESET . PHP_EOL; }

    public static function errorBox($text): void
    {
        $width = (int)shell_exec('tput cols');
        if ($width < 20) $width = 80;

        $boxWidth = min($width - 4, 100);
        $contentWidth = $boxWidth - 4;

        $wrappedText = wordwrap($text, $contentWidth, "\n", true);
        $lines = explode("\n", $wrappedText);

        $borderTop = "╔" . str_repeat("═", $boxWidth - 2) . "╗";
        $borderBottom = "╚" . str_repeat("═", $boxWidth - 2) . "╝";

        echo PHP_EOL;
        echo self::RED . $borderTop . self::RESET . PHP_EOL;

        foreach ($lines as $line) {
            $paddedLine = str_pad($line, $contentWidth);
            echo self::RED . "║ " . self::RESET . self::RED . $paddedLine . self::RED . " ║" . self::RESET . PHP_EOL;
        }

        echo self::RED . $borderBottom . self::RESET . PHP_EOL . PHP_EOL;
    }
}
