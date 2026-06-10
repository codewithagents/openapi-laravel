<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Console\LineDiff;

it('marks a changed line as removed then added and keeps context unchanged', function () {
    $diff = (new LineDiff)->diff("a\nb\nc", "a\nB\nc");

    expect($diff)->toBe([
        ' a',
        '-b',
        '+B',
        ' c',
    ]);
});

it('marks a pure addition with a + prefix only', function () {
    $diff = (new LineDiff)->diff("a\nb", "a\nb\nc");

    expect($diff)->toBe([
        ' a',
        ' b',
        '+c',
    ]);
});

it('marks a pure removal with a - prefix only', function () {
    $diff = (new LineDiff)->diff("a\nb\nc", "a\nc");

    expect($diff)->toBe([
        ' a',
        '-b',
        ' c',
    ]);
});

it('handles an empty expected side as all additions', function () {
    expect((new LineDiff)->diff('', "x\ny"))->toBe(['+x', '+y']);
});

it('bounds the output and appends a truncation note for a large diff', function () {
    $expected = implode("\n", array_map(static fn (int $i): string => 'old'.$i, range(1, 500)));
    $actual = implode("\n", array_map(static fn (int $i): string => 'new'.$i, range(1, 500)));

    $diff = (new LineDiff)->diff($expected, $actual);

    expect(count($diff))->toBe(201)
        ->and($diff[200])->toContain('more line');
});
