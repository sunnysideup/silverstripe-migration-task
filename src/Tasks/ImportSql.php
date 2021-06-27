<?php

namespace Sunnysideup\MigrateData\Tasks;

use SilverStripe\Control\Director;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;
use Sunnysideup\Flush\FlushNow;

class ImportSql extends BuildTask
{
    /**
     * standard SS variable.
     *
     * @var string
     */
    protected $title = 'Import SQL';

    /**
     * standard SS variable.
     *
     * @var string
     */
    protected $description = 'CAREFUL!!! - Import data from an SQL file';

    private static $file_names = [
        // 'my-sql-file-goes-here.sql',
    ];

    public function isEnabled()
    {
        return Director::isDev();
    }

    public function run($request)
    {
        foreach ($this->config()->get('file_names') as $fileName) {
            FlushNow::do_flush("<hr /><hr /><hr /><hr /><hr /><hr /><hr />START: '.{$fileName}.'<hr /><hr /><hr /><hr /><hr /><hr /><hr />");

            $fileName = Director::baseFolder() . '/' . $fileName;

            // Temporary variable, used to store current query
            $templine = '';

            if (! file($fileName)) {
                die('File not found: ' . $fileName);
            }
            // Read in entire file
            $lines = file($fileName);
            $count = 0;

            // Loop through each line
            foreach ($lines as $line) {
                // Skip it if it's a comment
                if ('--' === substr($line, 0, 2) || '' === $line) {
                    continue;
                }

                // Add this line to the current segment
                $templine .= $line;
                // If it has a semicolon at the end, it's the end of the query
                if (';' === substr(trim($line), -1, 1)) {
                    ++$count;
                    FlushNow::do_flush('running SQL ... line: ' . $count);
                    // Perform the query
                    DB::query($templine);
                    // Reset temp variable to empty
                    $templine = '';
                }
            }

            FlushNow::do_flush("<hr /><hr /><hr /><hr /><hr /><hr /><hr />END: '.{$fileName}.'<hr /><hr /><hr /><hr /><hr /><hr /><hr />");
        }
        FlushNow::do_flush('<hr /><hr /><hr /><hr /><hr /><hr /><hr />COMPLETED<hr /><hr /><hr /><hr /><hr /><hr /><hr />');
    }
}
