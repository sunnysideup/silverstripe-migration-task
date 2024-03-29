<?php

namespace Sunnysideup\MigrateData\Tasks;

use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;
use Sunnysideup\Flush\FlushNow;

/**
 * Update all systems.
 *
 * Class UpdateSystemsWithProductCodeVariantKeywords
 */
class FixMissingFiles extends BuildTask
{
    use FlushNow;

    /**
     * @var string
     */
    protected $title = 'Fix missing filename references in duplicate file records from SS3';

    /**
     * @var string
     */
    protected $description = 'When duplicate records exist for the same file in SS3 the silverstripe
        data migration task will only update one. Causing the other files to "go missing". This task fixes that';

    /**
     * Fix broken file references and publish them.
     *
     * @param mixed $request
     */
    public function run($request)
    {
        $sql = 'SELECT * FROM File WHERE ClassName != \'SilverStripe\\Assets\\Folder\' AND FileFilename is NULL';
        $broken_rows = DB::query($sql);

        foreach ($broken_rows as $row) {
            $sql = "
            SELECT * FROM File WHERE
                ClassName != 'SilverStripe\\Assets\\Folder' AND
                Filename = '" . $row['Filename'] . "' AND
                Name = '" . $row['Name'] . "' AND
                FileFilename is not NULL LIMIT 1
            ";
            $result = DB::query($sql);
            $healthyRow = $result->first();
            if ($healthyRow) {
                echo 'Fixing' . $healthyRow['Name'];
                $this->runUpdateQuery(
                    'UPDATE "' . 'File' . '"
                    SET "' . 'File' . '"."' . 'FileHash' . '" = \'' . $healthyRow['FileHash'] . '\'
                    WHERE ID = ' . $row['ID'],
                    2
                );

                $this->runUpdateQuery(
                    'UPDATE "' . 'File' . '"
                    SET "' . 'File' . '"."' . 'FileFilename' . '" = \'' . $healthyRow['FileFilename'] . '\'
                    WHERE ID = ' . $row['ID'],
                    2
                );

                $this->publishFile($row['ID']);
            }
        }
    }

    /**
     * @param string $sqlQuery list of queries
     * @param int    $indents  what is this list about?
     */
    protected function runUpdateQuery(string $sqlQuery, ?int $indents = 1)
    {
        $this->flushNow(str_replace('"', '`', $sqlQuery), 'created');
        $prefix = str_repeat(' ... ', $indents);

        try {
            DB::query($sqlQuery);
            $this->flushNow($prefix . ' DONE ' . DB::affected_rows() . ' rows affected');
        } catch (\Exception $exception) {
            $this->flushNow($prefix . "ERROR: Unable to run '{$sqlQuery}'", 'deleted');
            $this->flushNow('' . $exception->getMessage() . '', 'deleted');
        }
    }

    /**
     * Take a file record and publish it (enter it to File_Live).
     *
     * @param mixed $fileId
     */
    protected function publishFile($fileId)
    {
        $sql = "
            SELECT * FROM File_Live WHERE ID = {$fileId}
        ";
        $result = DB::query($sql);

        if (0 === $result->numRecords()) {
            $sql = "
                SELECT * FROM File WHERE ID = {$fileId}
            ";
            $file_record = DB::query($sql)->first();

            DB::query('
                INSERT IGNORE INTO `File_Live` (`ID`, `ClassName`, `LastEdited`, `Created`, `Name`, `Title`, `ShowInSearch`,
                `CanViewType`, `ParentID`, `OwnerID`, `Version`, `CanEditType`, `FileHash`, `FileFilename`, `FileVariant`)
                VALUES (' . $file_record['ID'] . ", '" . str_replace('\\', '\\\\', $file_record['ClassName']) . "', '" . $file_record['LastEdited'] . "',
                '" . $file_record['Created'] . "', '" . $file_record['Name'] . "', '" . $file_record['Title'] . "', '" . $file_record['ShowInSearch'] . "',
                '" . $file_record['CanViewType'] . "', '" . $file_record['ParentID'] . "', '" . $file_record['OwnerID'] . "', '" . $file_record['Version'] . "',
                '" . $file_record['CanEditType'] . "', '" . $file_record['FileHash'] . "', '" . $file_record['FileFilename'] . "', NULL)
            ");

            echo '
                INSERT INTO `File_Live` (`ID`, `ClassName`, `LastEdited`, `Created`, `Name`, `Title`, `ShowInSearch`,
                `CanViewType`, `ParentID`, `OwnerID`, `Version`, `CanEditType`, `FileHash`, `FileFilename`, `FileVariant`)
                VALUES (' . $file_record['ID'] . ", '" . str_replace('\\', '\\\\', $file_record['ClassName']) . "', '" . $file_record['LastEdited'] . "',
                '" . $file_record['Created'] . "', '" . $file_record['Name'] . "', '" . $file_record['Title'] . "', '" . $file_record['ShowInSearch'] . "',
                '" . $file_record['CanViewType'] . "', '" . $file_record['ParentID'] . "', '" . $file_record['OwnerID'] . "', '" . $file_record['Version'] . "',
                '" . $file_record['CanEditType'] . "', '" . $file_record['FileHash'] . "', '" . $file_record['FileFilename'] . "', NULL)
            ";
        }
    }
}
