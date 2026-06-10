<?php

declare(strict_types=1);

use ComposerUnused\ComposerUnused\Configuration\Configuration;
use ComposerUnused\ComposerUnused\Configuration\NamedFilter;

return static function (Configuration $config): Configuration {
    // spatie/laravel-data is a runtime dependency of the GENERATED code, not of
    // this package's own source, so static analysis cannot see it being used.
    return $config->addNamedFilter(NamedFilter::fromString('spatie/laravel-data'));
};
