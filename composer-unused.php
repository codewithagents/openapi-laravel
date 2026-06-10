<?php

declare(strict_types=1);

use ComposerUnused\ComposerUnused\Configuration\Configuration;

return static function (Configuration $config): Configuration {
    // No user filters needed: spatie/laravel-data is now used directly by this
    // package's source (App\Support\MapObjectTransformer implements its
    // Transformer interface), so composer-unused detects it as used.
    return $config;
};
