<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Terminal;

class DiffService
{
    public static function compare($old, $new): array
    {
        $oldLines = explode("\n", $old);
        $newLines = explode("\n", $new);

        $matrix = array_fill(0, count($oldLines) + 1, array_fill(0, count($newLines) + 1, 0));

        for ($i = 0; $i < count($oldLines); $i++) {
            for ($j = 0; $j < count($newLines); $j++) {
                if ($oldLines[$i] === $newLines[$j]) {
                    $matrix[$i + 1][$j + 1] = $matrix[$i][$j] + 1;
                } else {
                    $matrix[$i + 1][$j + 1] = max($matrix[$i + 1][$j], $matrix[$i][$j + 1]);
                }
            }
        }

        $i = count($oldLines);
        $j = count($newLines);
        $ops = [];

        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $oldLines[$i - 1] === $newLines[$j - 1]) {
                array_unshift(
                    $ops,
                    ['type' => 'keep', 'old' => $oldLines[$i - 1], 'new' => $newLines[$j - 1], 'oln' => $i, 'nln' => $j]
                );
                $i--;
                $j--;
            } elseif ($j > 0 && ($i === 0 || $matrix[$i][$j - 1] >= $matrix[$i - 1][$j])) {
                array_unshift($ops, ['type' => 'add', 'line' => $newLines[$j - 1], 'nln' => $j]);
                $j--;
            } elseif ($i > 0 && ($j === 0 || $matrix[$i][$j - 1] < $matrix[$i - 1][$j])) {
                array_unshift($ops, ['type' => 'remove', 'line' => $oldLines[$i - 1], 'oln' => $i]);
                $i--;
            }
        }
        return $ops;
    }
}
