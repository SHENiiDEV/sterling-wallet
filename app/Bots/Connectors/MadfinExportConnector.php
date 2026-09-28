<?php

namespace App\Bots\Connectors;

use App\Bots\Target;

/**
 * Madfin portal export. The portal steps are not known yet; the script
 * reports a clear failure until bots/madfin-export.mjs is filled in.
 */
class MadfinExportConnector extends PlaywrightConnector
{
    public function code(): string
    {
        return 'madfin-export';
    }

    public function label(): string
    {
        return 'Madfin export (portal steps pending)';
    }

    protected function input(Target $target): array
    {
        return [];
    }
}
