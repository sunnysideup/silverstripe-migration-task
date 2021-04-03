<?php

namespace Sunnysideup\MigrateData\Tasks;

use SilverStripe\Control\Director;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;

/**
 * Update all systems.
 *
 * Class UpdateSystemsWithProductCodeVariantKeywords
 */
class CleanUpSS4Files extends BuildTask
{
    /**
     * @var string
     */
    protected $title = 'Removes non-existent files from DB';

    /**
     * @var string
     */
    protected $description = 'You can run this script straight after an upgrade to remove files that are not physically present.';

    /**
     * Method to save all System dataobjects and trigger the onBeforeWrite() event handler.
     *
     * @param mixed $request
     */
    public function run($request)
    {
        $sql = '
        SELECT Filename
        FROM `File`
            LEFT JOIN File_Live ON File.ID = File_Live.ID
        WHERE File_Live.ID IS NULL AND File.ClassName = \'SilverStripe\\\Assets\\\File\' ORDER BY File.Filename
        ';
        $rows = DB::query($sql);
        echo '<h2>Files that are not published</h2>';
        foreach ($rows as $row) {
            DB::alteration_message($row['Filename'], 'deleted');
        }

        $sql = '
            SELECT "Filename", "ID"
            FROM "File"
            WHERE
                "ClassName" = \'SilverStripe\\\Assets\\\File\'
            ORDER BY Filename ASC';
        $rows = DB::query($sql);
        $baseDir = Director::baseFolder() . '/public/';
        foreach ($rows as $row) {
            $fullName = $baseDir . $row['Filename'];
            if (file_exists($fullName)) {
                echo '.';
            } else {
                DB::alteration_message('DELETING: ' . $fullName, 'deleted');
                $sql = 'DELETE FROM File WHERE ID = ' . $row['ID'] . ';';
                DB::query($sql);
                $sql = 'DELETE FROM File_Live WHERE ID = ' . $row['ID'] . ';';
                DB::query($sql);
            }
        }
    }
}
