<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Console\DriftChecker;
use CodeWithAgents\OpenApiLaravel\Console\DriftStatus;
use CodeWithAgents\OpenApiLaravel\Console\GenerationPlan;
use CodeWithAgents\OpenApiLaravel\Console\PlannedFile;

$tempFile = fn (): string => sys_get_temp_dir().'/oal_drift_'.uniqid().'.php';

it('reports in sync when the on-disk content matches exactly', function () use ($tempFile) {
    $path = $tempFile();
    file_put_contents($path, "<?php // x\n");

    $plan = new GenerationPlan([new PlannedFile($path, "<?php // x\n", PlannedFile::CATEGORY_DATA)], false);

    $entries = (new DriftChecker)->check($plan);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->status)->toBe(DriftStatus::InSync)
        ->and($entries[0]->isDrifted())->toBeFalse();
});

it('reports changed when even a single byte differs', function () use ($tempFile) {
    $path = $tempFile();
    file_put_contents($path, "<?php // x\n");

    $plan = new GenerationPlan([new PlannedFile($path, "<?php // x \n", PlannedFile::CATEGORY_DATA)], false);

    $entries = (new DriftChecker)->check($plan);

    expect($entries[0]->status)->toBe(DriftStatus::Changed)
        ->and($entries[0]->isDrifted())->toBeTrue()
        ->and($entries[0]->expected)->toBe("<?php // x \n")
        ->and($entries[0]->actual)->toBe("<?php // x\n");
});

it('reports missing when no file exists at the planned path', function () use ($tempFile) {
    $path = $tempFile();

    $plan = new GenerationPlan([new PlannedFile($path, '<?php', PlannedFile::CATEGORY_DATA)], false);

    $entries = (new DriftChecker)->check($plan);

    expect($entries[0]->status)->toBe(DriftStatus::Missing)
        ->and($entries[0]->isDrifted())->toBeTrue()
        ->and($entries[0]->actual)->toBe('');
});
