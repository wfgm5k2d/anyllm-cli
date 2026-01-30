<?php

namespace AnyllmCli\Service;

use AnyllmCli\Terminal\Style;

class ToolExecutor
{
    private DiffService $diffService;

    public function __construct(DiffService $diffService)
    {
        $this->diffService = $diffService;
    }

    public function executeTools(string $content): string
    {
        $output = "";
        $cwd = getcwd();

        // 1. FILE EDIT
        if (preg_match_all('/\[\[FILE:(.*?)\]\](.*?)\[\[ENDFILE\]\]/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $path = trim($match[1]);
                $newContent = trim($match[2]);
                $fullPath = $cwd . DIRECTORY_SEPARATOR . $path;

                if (strpos($path, '..') !== false) {
                    Style::errorBox("Security Alert:\nPath traversal blocked: $path"); // <-- ИЗМЕНЕНО
                    $output .= "[Error: Blocked]\n"; continue;
                }

                $dir = dirname($fullPath);
                if (!is_dir($dir)) mkdir($dir, 0777, true);

                // Get old content for diff
                $oldContent = file_exists($fullPath) ? file_get_contents($fullPath) : "";

                // Show Diff BEFORE applying (or after, for confirmation. Here we do it as we verify)
                echo PHP_EOL;
                Style::tool("Applying changes to: " . Style::BOLD . $path . Style::RESET);
                $this->renderDiff($oldContent, $newContent);

                if (file_put_contents($fullPath, $newContent) !== false) {
                    $msg = "File created/updated: $path"; $output .= "[$msg]\n"; Style::success($msg);
                } else {
                    Style::errorBox("Failed to write to file:\n$fullPath"); // <-- ИЗМЕНЕНО
                    $output .= "[Error]\n";
                }
            }
        }

        // 2. READ
        if (preg_match_all('/\[\[READ:(.*?)\]\]/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $path = trim($match[1]);
                $fullPath = $cwd . DIRECTORY_SEPARATOR . $path;
                Style::tool("Reading file: $path");
                if (file_exists($fullPath)) {
                    $c = file_get_contents($fullPath);
                    $output .= "\nContent of $path:\n```\n$c\n```\n";
                } else {
                    Style::errorBox("File not found:\n$fullPath"); // <-- ИЗМЕНЕНО
                    $output .= "[Error: Not found]\n";
                }
            }
        }

        // 3. GREP
        if (preg_match_all('/\[\[GREP:(.*?)\]\]/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $term = escapeshellarg(trim($match[1]));
                Style::tool("Searching for: " . trim($match[1]));
                $cmd = "grep -rnI --exclude-dir=.git --exclude-dir=vendor $term . | head -n 20";
                $result = shell_exec($cmd);
                $output .= empty($result) ? "[No matches]\n" : "\n[GREP Results]:\n$result\n";
            }
        }

        // 4. LS
        if (preg_match_all('/\[\[LS(?::(.*?))?\]\]/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $path = empty($match[1]) ? '.' : trim($match[1]);
                Style::tool("Listing: $path");
                $fullPath = $cwd . DIRECTORY_SEPARATOR . $path;
                if (is_dir($fullPath)) {
                    $scan = array_diff(scandir($fullPath), ['.', '..', '.git']);
                    $output .= "\n[LS]:\n" . implode("\n", array_slice($scan, 0, 50)) . "\n";
                } else {
                    Style::errorBox("[Error: Dir not found]:\n$fullPath"); // <-- ИЗМЕНЕНО
                    $output .= "[Error: Dir not found]\n";
                }
            }
        }

        return $output;
    }

    private function renderDiff(string $old, string $new): void
    {
        $diff = $this->diffService->compare($old, $new);
        $contextLines = 2;

        $hunks = [];
        $currentHunk = [];

        for ($i = 0; $i < count($diff); $i++) {
            $line = $diff[$i];

            if ($line['type'] !== 'keep') {
                if (empty($currentHunk)) {
                    for ($j = max(0, $i - $contextLines); $j < $i; $j++) {
                        if ($diff[$j]['type'] === 'keep') $currentHunk[] = $diff[$j];
                    }
                }
                $currentHunk[] = $line;
            } else {
                if (!empty($currentHunk)) {
                    $isNextChangeClose = false;
                    for ($j = 1; $j <= $contextLines; $j++) {
                        if (isset($diff[$i + $j]) && $diff[$i + $j]['type'] !== 'keep') {
                            $isNextChangeClose = true;
                            break;
                        }
                    }

                    if ($isNextChangeClose) {
                        $currentHunk[] = $line;
                    } else {
                        $addedContext = 0;
                        while ($addedContext < $contextLines && isset($diff[$i])) {
                            if ($diff[$i]['type'] === 'keep') {
                                $currentHunk[] = $diff[$i];
                                $i++;
                                $addedContext++;
                            } else {
                                break;
                            }
                        }
                        $i--;

                        $hunks[] = $currentHunk;
                        $currentHunk = [];
                    }
                }
            }
        }
        if (!empty($currentHunk)) $hunks[] = $currentHunk;

        echo Style::GRAY . " │" . Style::RESET . PHP_EOL;

        foreach ($hunks as $idx => $hunk) {
            if ($idx === 0 && !empty($hunk) && isset($hunk[0]['nln']) && $hunk[0]['nln'] > $contextLines + 1) {
                echo Style::GRAY . " │ ... hidden lines ... " . Style::RESET . PHP_EOL;
            } else if ($idx > 0) {
                echo Style::GRAY . " │ ═══════════════════════════════════════════ " . Style::RESET . PHP_EOL;
            }

            foreach ($hunk as $line) {
                if ($line['type'] === 'keep') {
                    $ln = str_pad($line['nln'], 4, " ", STR_PAD_LEFT);
                    echo Style::GRAY . " │ " . $ln . "   " . $line['new'] . Style::RESET . PHP_EOL;
                } elseif ($line['type'] === 'add') {
                    $ln = str_pad($line['nln'], 4, " ", STR_PAD_LEFT);
                    echo Style::GRAY . " │ " . Style::GREEN . $ln . " + " . $line['line'] . Style::RESET . PHP_EOL;
                } elseif ($line['type'] === 'remove') {
                    $ln = str_pad($line['oln'], 4, " ", STR_PAD_LEFT);
                    echo Style::GRAY . " │ " . Style::RED . $ln . " - " . $line['line'] . Style::RESET . PHP_EOL;
                }
            }
        }

        echo Style::GRAY . " │" . Style::RESET . PHP_EOL;
    }
}
