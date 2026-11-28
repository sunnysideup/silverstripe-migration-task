<?php

declare(strict_types=1);

namespace Sunnysideup\MigrateData\Tasks;

class FixSheaDawsonLink extends MigrateDataTask
{
    protected string $title = 'Fix Shea Dawson Link Module (sheadawson/link)';

    protected static string $description = 'Moves table and changes class names';

    /**
     * @config
     */
    private static $is_enabled = true;
}
