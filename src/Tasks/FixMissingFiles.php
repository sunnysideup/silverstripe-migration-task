<?php

namespace Sunnysideup\MigrateData\Tasks;

use SilverStripe\ORM\DB;
use SilverStripe\Dev\BuildTask;
use Sunnysideup\Flush\FlushNow;

/**
 * Update all systems
 *
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
     * Method to save all System dataobjects and trigger the onBeforeWrite() event handler.
     */
    public function run($request)
    {
        $sql = 'SELECT * FROM File WHERE ClassName != \'SilverStripe\\Assets\\Folder\' AND FileFilename is NULL';
        $broken_rows = DB::query($sql);

        foreach($broken_rows as $row) {
            $sql = "
            SELECT * FROM File WHERE
                ClassName != 'SilverStripe\\Assets\\Folder' AND
                Filename = '" . $row['Filename'] . "' AND
                Name = '" . $row['Name'] . "' AND
                FileFilename is not NULL LIMIT 1
            ";
            $result = DB::query($sql);

            if($healthy_row = $result->first()) {
                echo "Fixing" . $healthy_row['Name'];
                $this->runUpdateQuery(
                    'UPDATE "'.'File'.'"
                    SET "'.'File'.'"."'.'FileHash'.'" = \''.$healthy_row['FileHash'].'\'
                    WHERE ID = '.$row['ID'],
                    2
                );

                $this->runUpdateQuery(
                    'UPDATE "'.'File'.'"
                    SET "'.'File'.'"."'.'FileFilename'.'" = \''.$healthy_row['FileFilename'].'\'
                    WHERE ID = '.$row['ID'],
                    2
                );
            }
        }
    }

    /**
     *
     * @param  array $queries list of queries
     * @param  string $name what is this list about?
     */
    protected function runUpdateQuery(string $sqlQuery, $indents = 1)
    {
        $this->flushNow(str_replace('"', '`', $sqlQuery), 'created');
        try {
            $sqlResults = DB::query($sqlQuery);
            $prefix = str_repeat(' ... ', $indents);
            $this->flushNow($prefix . ' DONE ' . DB::affected_rows() . ' rows affected');
        } catch (\Exception $e) {
            $this->flushNow($prefix . "ERROR: Unable to run '$sqlQuery'", 'deleted');
            $this->flushNow("" . $e->getMessage() . "", 'deleted');
        }
    }
}
