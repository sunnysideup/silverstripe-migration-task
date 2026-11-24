<?php

declare(strict_types=1);

namespace Sunnysideup\MigrateData\Tasks;

class FixSheaDawsonLink extends MigrateDataTask
{
    protected $title = 'Fix Shea Dawson Link Module (sheadawson/link)';

    protected $description = 'Moves table and changes class names';

    /**
     * @config
     */
    private static $is_enabled = true;
}
