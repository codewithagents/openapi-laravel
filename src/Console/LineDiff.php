<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

/**
 * A small, dependency-free unified line diff for the --diff option. It is meant
 * to make a "changed" file's drift readable, not to be a full diff engine: it
 * walks both sides with a longest-common-subsequence over lines, emits "-"
 * (expected) and "+" (on-disk) markers, and caps the output so a wholesale
 * rewrite cannot flood the terminal.
 *
 * @internal
 */
final readonly class LineDiff
{
    private const MAX_LINES = 200;

    /**
     * @return list<string> diff lines, each already prefixed with " ", "-" or "+"
     */
    public function diff(string $expected, string $actual): array
    {
        $expectedLines = $this->split($expected);
        $actualLines = $this->split($actual);

        $lcs = $this->lcsTable($expectedLines, $actualLines);

        $out = [];
        $i = 0;
        $j = 0;
        $countExpected = count($expectedLines);
        $countActual = count($actualLines);

        while ($i < $countExpected && $j < $countActual) {
            if ($expectedLines[$i] === $actualLines[$j]) {
                $out[] = ' '.$expectedLines[$i];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $out[] = '-'.$expectedLines[$i];
                $i++;
            } else {
                $out[] = '+'.$actualLines[$j];
                $j++;
            }
        }

        while ($i < $countExpected) {
            $out[] = '-'.$expectedLines[$i];
            $i++;
        }

        while ($j < $countActual) {
            $out[] = '+'.$actualLines[$j];
            $j++;
        }

        if (count($out) > self::MAX_LINES) {
            $hidden = count($out) - self::MAX_LINES;
            $out = array_slice($out, 0, self::MAX_LINES);
            $out[] = sprintf('... (%d more line%s)', $hidden, $hidden === 1 ? '' : 's');
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function split(string $text): array
    {
        if ($text === '') {
            return [];
        }

        return explode("\n", $text);
    }

    /**
     * Standard LCS length table over the two line arrays.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return array<int, array<int, int>>
     */
    private function lcsTable(array $a, array $b): array
    {
        $m = count($a);
        $n = count($b);

        $table = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        for ($i = $m - 1; $i >= 0; $i--) {
            for ($j = $n - 1; $j >= 0; $j--) {
                if ($a[$i] === $b[$j]) {
                    $table[$i][$j] = $table[$i + 1][$j + 1] + 1;
                } else {
                    $table[$i][$j] = max($table[$i + 1][$j], $table[$i][$j + 1]);
                }
            }
        }

        return $table;
    }
}
