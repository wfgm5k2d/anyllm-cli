<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Terminal;

readonly class DiffRenderer
{
    public function __construct(private DiffService $diffService)
    {
    }

    public function render(string $old, string $new): void
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
            if ($idx > 0) {
                echo Style::GRAY . " │ -------------------------------------------------" . Style::RESET . PHP_EOL;
            }

            foreach ($hunk as $line) {
                if ($line['type'] === 'keep') {
                    $ln = str_pad((string)($line['nln'] ?? ' '), 4, " ", STR_PAD_LEFT);
                    echo Style::GRAY . " │ " . $ln . "   " . ($line['new'] ?? '') . Style::RESET . PHP_EOL;
                } elseif ($line['type'] === 'add') {
                    $ln = str_pad((string)($line['nln'] ?? ' '), 4, " ", STR_PAD_LEFT);
                    echo Style::GRAY . " │ " . Style::GREEN . $ln . " + " . ($line['line'] ?? '') . Style::RESET . PHP_EOL;
                } elseif ($line['type'] === 'remove') {
                    $ln = str_pad((string)($line['oln'] ?? ' '), 4, " ", STR_PAD_LEFT);
                    echo Style::GRAY . " │ " . Style::RED . $ln . " - " . ($line['line'] ?? '') . Style::RESET . PHP_EOL;
                }
            }
        }

        echo Style::GRAY . " │" . Style::RESET . PHP_EOL;
    }
}
