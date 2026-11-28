<?php

namespace Sunnysideup\MigrateData\Tasks;

class CheckClassNames extends MigrateDataTaskBase
{
    protected string $title = 'Check all tables for valid class names';

    protected static string $description = 'Check all tables for valid class names';

    protected static string $commandName = 'check-class-names';

    /**
     * @config
     */
    private static $is_enabled = true;

    protected function performMigration()
    {
        die('Please install the sunnysideup/classname-fixer module to fix class names. See https://github.com/sunnysideup/classname-fixer for more information.');
    }
}
