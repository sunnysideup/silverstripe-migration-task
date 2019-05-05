<?php

namespace Sunnysideup\MigrateData\Tasks;

use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Control\Director;

class MigrateDataTask extends BuildTask
{
    protected $title = 'Migrate Data';

    protected $description = 'Migrates specific data defined in yml';

    protected $enabled = true;

    public function run($request)
    {
        DataObject::Config()->set('validation_enabled', false);
        ini_set('memory_limit', '512M');
        Environment::increaseMemoryLimitTo();
        //20 minutes
        Environment::increaseTimeLimitTo(7200);
        $this->performMigration();
        $this->flushNow('-----------------------------');
        $this->flushNow('THE END');
        $this->flushNow('-----------------------------');
    }

    protected $itemsToMigrate = [
        'ElementalMigration'
    ];

    /**
     * Queries the config for Migrate definitions, and runs migrations
     *
     *
     * @return string
     */
    public function performMigration()
    {
        $fullList = Config::inst()->get(self::class, 'items_to_migrate');
        foreach($this->itemsToMigrate as $item => $details) {
            $this->flushNow( '<h2>Starting Migration for '.$item.'</h2>');
            $preSqlQueries = $details['pre_sql_queries'];
            $data = $details['data'];
            $publishClasses = $details['publish_classes'];
            $postSqlQueries = $details['post_sql_queries'];

            if ($preSqlQueries) {
                $this->flushNow( '<h2>Performing PRE SQL Queries</h2>');
                foreach ($preSqlQueries as $sqlQuery) {

                    try {
                        $sqlResults = DB::query($sqlQuery);
                        $this->flushNow( "<p>Run '$sqlQuery'</p>");

                    } catch (Exception $e) {
                        $this->flushNow( "<p>Unable to run '$sqlQuery'</p>");
                        $this->flushNow( "<p>" . $e->getMessage() . "</p>");
                    }
                }
            }

            if ($data) {
                $this->flushNow( '<h2>Migrating data</h2>');
                foreach ($data as $dataItem) {
                    $this->migrateSimple(
                        $dataItem['old_table'],
                        $dataItem['new_table'],
                        $dataItem['old_fields'],
                        $dataItem['new_fields']
                    );
                }
            }

            if ($publishClasses) {
                $this->flushNow( '<h2>Publish classes</h2>');
                foreach ($publishClasses as $publishClass) {

                    try {
                        $publishItems = $publishClass::get();
                        foreach ($publishItems as $publishItem) {
                            $publishItem->write();
                            $publishItem->doPublish();
                        }
                        $this->flushNow(
                            "<p>Published " . $publishItems->count() .
                            " " .
                            $publishClass .
                            " item" .
                            ($publishItems->count() == 1 ? "" : "s") . ".</p>"
                        );

                    } catch (Exception $e) {
                        $this->flushNow( "<p>Unable to publish " . $publishClass . "</p>", 'error');
                        $this->flushNow( "<p>" . $e->getMessage() . "</p>", 'error');
                    }
                }
            }

            if ($postSqlQueries) {
                $this->flushNow( '<h2>Performing POST SQL Queries</h2>' );
                foreach ($postSqlQueries as $sqlQuery) {

                    try {
                        $sqlResults = DB::query($sqlQuery);
                        $this->flushNow( "<p>Run '$sqlQuery'</p>");

                    } catch (Exception $e) {
                        $this->flushNow( "<p>Unable to run '$sqlQuery'</p>");
                        $this->flushNow( "<p>" . $e->getMessage() . "</p>");
                    }
                }
            }
            $this->flushNow( '<h2>Finish Migration for '.$item.'</h2>' );
        }

    }

    /**
     * Migrates data from one table to another
     *
     * @param String | $tableOld - the db table where we are moving fields from
     * @param String | $tableNew - the db table where we are moving fields to
     * @param String | $fieldNamesOld - The current field names
     * @param String | $fieldNamesNew - The new field names (this may be the same as $fieldNameOld)
     * @return string
     */
    public function migrateSimple($tableOld, $tableNew, $fieldNamesOld, $fieldNamesNew)
    {

        if(! $this->tableExists($tableOld)) {
            $this->flusNow( "<p>$tableOld (old table) does not exist</p>", 'error');;
        }

        if(! $this->tableExists($tableNew)) {
            $this->flusNow( "<p>$tableNew (old table) does not exist</p>", 'error');;
        }

        try {
            $count = 0;

            $newEntriesQuery = new SQLSelect();
            $newEntriesQuery->setFrom($tableNew);
            $newEntriesQuery->setOrderBy('ID');
            $newEntries = $newEntriesQuery->execute();
            $newEntryIDs = $newEntries->keyedColumn('ID');

            $oldEntriesQuery = new SQLSelect();
            $oldEntriesQuery->setFrom($tableOld);
            $oldEntriesQuery->setOrderBy('ID');
            $oldEntries = $oldEntriesQuery->execute();
            $oldEntryIDs = [];

            //add a new line using the ID as identifier
            foreach ($oldEntries as $oldEntry) {
                if (!in_array($oldEntry['ID'], $newEntryIDs)) {
                    DB::query('INSERT INTO "' . $tableNew . '" ("ID") VALUES (' . $oldEntry['ID'] . ');');
                    $this->flushNow( '<p>Added row ' . $oldEntry['ID'] . ' to ' . $tableNew . '.</p>' );
                }
                array_push($oldEntryIDs, $oldEntry['ID']);
            }

            //update the new table with the old values
            //for the rows that join with the ID and match the list of OLD ids.
            $updateQuery = 'UPDATE "' . $tableNew . '" AS "tablenew" ';
            $updateQuery .= 'INNER JOIN "' . $tableOld . '" AS "tableold" ON "tablenew"."ID" = "tableold"."ID" ';
            $updateQuery .= 'SET ';

            for ($i = 0; $i < count($fieldNamesNew) && $i < count($fieldNamesOld); $i++) {
                if ($i > 0) {
                    $updateQuery .= ', ';
                }
                $updateQuery .=  '"tablenew"."' . $fieldNamesNew[$i] . '" = "tableold"."' . $fieldNamesOld[$i] . '" ';
            }
            $updateQuery .= 'WHERE "tablenew"."ID" IN (' . implode(', ', $oldEntryIDs) . ');';
            $sqlResults = DB::query($updateQuery);

            $this->flushNow( "<p>Migration of $tableOld to $tableNew successful.</p>" );

        } catch (Exception $e) {
            $this->flushNow( "<p>Unable to migrate $tableOld to $tableNew.</p>" );
            $this->flushNow( "<p>" . $e->getMessage() . "</p>" );
        }

    }


    protected function tableExists($tableName) : bool
    {
        $connection = DB::get_conn();
        $database = $connection->getSelectedDatabase();
        return DB::query('
            SELECT COUNT()
            FROM information_schema.tables
            WHERE table_schema = \''.$database.'\'
                AND table_name = \''.$tableName.'\'
            LIMIT 1;'
        )->value() ? true : false;
    }


    protected function flushNow($message, $type = '', $bullet = true)
    {
        echo '';
        // check that buffer is actually set before flushing
        if (ob_get_length()) {
            @ob_flush();
            @flush();
            @ob_end_flush();
        }
        @ob_start();
        if(Director::is_cli()) {
            $message = strip_tags($message);
        }
        if ($bullet) {
            DB::alteration_message($message, $type);
        } else {
            echo $message;
        }
    }

}
