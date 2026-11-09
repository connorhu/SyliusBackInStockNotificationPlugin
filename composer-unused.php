<?php

declare(strict_types=1);

use ComposerUnused\ComposerUnused\Configuration\Configuration;
use ComposerUnused\ComposerUnused\Configuration\NamedFilter;

return static function (Configuration $config): Configuration {
    // Needed at runtime by Symfony's LockableTrait (used by the alert command), but never referenced by name in src/.
    return $config->addNamedFilter(NamedFilter::fromString('symfony/lock'));
};
