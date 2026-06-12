<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;

/**
 * Issue #108, end-to-end through the real pipeline: two component schemas whose
 * class names differ only by letter case must NOT emit two files whose paths
 * collide on a case-insensitive filesystem (macOS APFS default, Windows NTFS),
 * where the second write silently clobbers the first. The class-name allocator
 * dedupes case-insensitively, so the second claimant takes the _2 suffix path.
 * UniqueNamesTest covers the allocator in isolation; this test proves the
 * ModelGenerator actually routes class names through that allocator.
 */
it('emits case-folded-distinct filenames for case-only-clashing schemas (#108)', function () {
    $document = (new OpenApiReader)->read([
        'openapi' => '3.0.3',
        'info' => ['title' => 'Case Clash', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => [
            'FOO' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
            'Foo' => ['type' => 'object', 'properties' => ['b' => ['type' => 'integer']]],
        ]],
    ]);

    $files = (new ModelGenerator)->generate($document);

    $filenames = [];
    foreach ($files as $file) {
        $filenames[] = $file->filename();
    }
    sort($filenames, SORT_STRING);

    // First claimant keeps its exact casing, the case-only clash is suffixed.
    expect($filenames)->toBe(['FOOData.php', 'FooData_2.php']);

    // The actual #108 property: no two generated paths may collide after case
    // folding, anywhere in the file set.
    $folded = array_map(strtolower(...), $filenames);
    expect(array_unique($folded))->toHaveCount(count($folded));

    // Each file keeps its own schema's content: nothing was merged or lost.
    // generate() keys its result by allocated class name.
    expect($files['FOOData']->code)->toContain('class FOOData')
        ->and($files['FooData_2']->code)->toContain('class FooData_2');
});
