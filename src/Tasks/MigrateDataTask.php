<?php

namespace Sunnysideup\MigrateData\Tasks;

use SilverStripe\ORM\DB;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Control\Director;
use SilverStripe\Versioned\Versioned;

class MigrateDataTask extends BuildTask
{
    protected $title = 'Migrate Data';

    protected $description = 'Migrates specific data defined in yml';

    protected $enabled = true;

    protected $includeInserts = true;

    public function run($request)
    {
        $this->flushNow('-----------------------------');
        $this->flushNow('THE START - look out for THE END ...');
        $this->flushNow('-----------------------------');

        DataObject::Config()->set('validation_enabled', false);
        ini_set('memory_limit', '512M');
        Environment::increaseMemoryLimitTo();
        Environment::increaseTimeLimitTo(7200);

        $this->performMigration();

        $this->flushNow('-----------------------------');
        $this->flushNow('THE END');
        $this->flushNow('-----------------------------');
    }

    /**
     * Queries the config for Migrate definitions, and runs migrations
     *
     *
     * @return string
     */
    protected function performMigration()
    {
        $fullList = Config::inst()->get(self::class, 'items_to_migrate');
        foreach($fullList as $item => $details) {
            $this->flushNow( '<h2>Starting Migration for '.$item.'</h2>');

            $preSqlQueries = $details['pre_sql_queries'];
            $this->runSQLQueries($preSqlQueries, 'PRE');

            $data = $details['data'];
            $this->runMoveData($data);

            $publishClasses = $details['publish_classes'];
            $this->runPublishClasses($publishClasses);

            $postSqlQueries = $details['post_sql_queries'];
            $this->runSQLQueries($postSqlQueries, 'POST');

            $this->flushNow( '<h2>Finish Migration for '.$item.'</h2>' );
        }

    }

    /**
     *
     * @param  array $queries list of queries
     * @param  string $name what is this list about?
     */
    protected function runSQLQueries($queries, $name = 'UPDATE QUERIES')
    {

        if ($queries) {
            $this->flushNow( '<h2>Performing '.$name.' Queries</h2>');
            foreach ($queries as $sqlQuery) {
                $this->flushNow($sqlQuery);
                try {
                    $sqlResults = DB::query($sqlQuery);
                    $this->flushNow('... DONE');
                } catch (Exception $e) {
                    $this->flushNow( "Unable to run '$sqlQuery'", 'error');
                    $this->flushNow( "" . $e->getMessage() . "", 'error');
                }
            }
        }
    }

    /**
     * data needs to be in this format:
     *      [
     *          'include_inserts' => true|false, #assumed true if not provided
     *          'old_table' => 'foo',
     *          'new_table' => 'bar' (can be the same!)
     *          'old_fields' => ['A', 'B', 'C']
     *          'new_fields' => ['A', 'B', 'C2']
     *      ]
     * @param  array $data list of data that is going to be moved
     * @return [type]       [description]
     */
    protected function runMoveData($data)
    {
        if ($data) {
            $this->flushNow( '<h2>Migrating data</h2>');
            foreach ($data as $dataItem) {
                if(! isset($dataItem['include_inserts'])) {
                    $dataItem['include_inserts'] = true;
                }
                if(! isset($dataItem['new_table'])) {
                    $dataItem['new_table'] = $dataItem['old_table'];
                }
                if(! isset($dataItem['new_fields'])) {
                    $dataItem['new_fields'] = $dataItem['old_fields'];
                }
                $this->flushNow( '<h4>Migrating data '.$dataItem['old_table'].' to '.$dataItem['new_table'].'</h4>');
                $this->migrateSimple(
                    $dataItem['include_inserts'],
                    $dataItem['old_table'],
                    $dataItem['new_table'],
                    $dataItem['old_fields'],
                    $dataItem['new_fields']
                );
            }
        }

    }

    /**
     *
     * @param  array $publishClasses list of class names to write / publish
     */
    protected function runPublishClasses($publishClasses)
    {

        if ($publishClasses) {
            $this->flushNow( '<h2>Publish classes</h2>');
            foreach ($publishClasses as $publishClass) {
                $this->flushNow( '<h4>Publishing '.$publishClass.'</h4>' );
                try {
                    $publishItems = $publishClass::get();
                    foreach ($publishItems as $publishItem) {
                        $this->flushNow(
                            "Publishing " . $publishItems->count() .
                            " " .
                            $publishClass .
                            " item" .
                            ($publishItems->count() == 1 ? "" : "s") . "."
                        );
                        $publishItem->write();
                        if($publishItem->hasMethod('copyVersionToStage')) {
                            $publishItem->doPublish();
                            $publishItem->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
                            $this->flushNow('... DONE - PUBLISHED');
                        } else {
                            $this->flushNow('... DONE - WRITE ONLY');
                        }
                    }

                } catch (Exception $e) {
                    $this->flushNow( "Unable to publish " . $publishClass . "", 'error');
                    $this->flushNow( "" . $e->getMessage() . "", 'error');
                }
            }
        }
    }


    /**
     * Migrates data from one table to another
     *
     * @param bool | $includeInserts - the db table where we are moving fields from
     * @param string | $tableOld - the db table where we are moving fields from
     * @param string | $tableNew - the db table where we are moving fields to
     * @param string | $fieldNamesOld - The current field names
     * @param string | $fieldNamesNew - The new field names (this may be the same as $fieldNameOld)
     * @return string
     */
    public function migrateSimple($includeInserts, $tableOld, $tableNew, $fieldNamesOld, $fieldNamesNew)
    {

        if(! $this->tableExists($tableOld)) {
            $this->flushNow( "$tableOld (old table) does not exist", 'error');;
        }

        if(! $this->tableExists($tableNew)) {
            $this->flushNow( "$tableNew (new table) does not exist", 'error');;
        }

        try {
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
                if($includeInserts) {
                    if (! in_array($oldEntry['ID'], $newEntryIDs)) {
                        DB::query('INSERT INTO "' . $tableNew . '" ("ID") VALUES (' . $oldEntry['ID'] . ');');
                        $this->flushNow( 'Added row ' . $oldEntry['ID'] . ' to ' . $tableNew . '.' );
                    }
                }
                array_push($oldEntryIDs, $oldEntry['ID']);
            }

            //update fields
            if(count($oldEntryIDs)) {
                //update the new table with the old values
                //for the rows that join with the ID and match the list of OLD ids.
                $updateQuery = 'UPDATE "' . $tableNew . '" AS "tablenew" ';
                $updateQuery .= 'INNER JOIN "' . $tableOld . '" AS "tableold" ON "tablenew"."ID" = "tableold"."ID" ';
                if(substr($tableNew, -9) == '_versions') {
                    $updateQuery .= ' AND "tablenew"."RecordID" = "tableold"."RecordID" ';
                    // also link to RecordID ...
                }
                $updateQuery .= 'SET ';

                for ($i = 0; $i < count($fieldNamesNew) && $i < count($fieldNamesOld); $i++) {
                    if ($i > 0) {
                        $updateQuery .= ', ';
                    }
                    $updateQuery .=  '"tablenew"."' . $fieldNamesNew[$i] . '" = "tableold"."' . $fieldNamesOld[$i] . '" ';
                }
                $updateQuery .= 'WHERE "tablenew"."ID" IN (' . implode(', ', $oldEntryIDs) . ');';
                $this->flushNow($updateQuery);
                $sqlResults = DB::query($updateQuery);
                $this->flushNow( "... DONE" );
            }
        } catch (Exception $e) {
            $this->flushNow( "Unable to migrate $tableOld to $tableNew.", 'error');
            $this->flushNow($e->getMessage(), 'error');
        }

    }


    protected function tableExists($tableName) : bool
    {
        $schema = $this->getSchema();

        return $schema->hasTable($tableName);
    }

    protected function makeTableObsolete($tableName) : bool
    {
        $schema = $this->getSchema();
        if($this->tableExists($tableName)) {
            if(! $this->tableExists('_obsolete_'.$tableName)) {
                $schema->dontRequireTable($tableName);
                return true;
            } else {
                $this->flushNow('Table '.$tableName.' is already obsolete');
            }
        } else {
            $this->flushNow('Table '.$tableName.' does not exist.');
        }
        return false;
    }

    protected function fieldExists($tableName, $fieldName) : bool
    {
        $schema = $this->getSchema();
        $fieldList = $schema->fieldList($tableName);

        return isset($fieldList[$fieldName]);
    }

    protected $_schema = null;

    protected function getSchema()
    {
        if($this->_schema === null) {
            $this->_schema = DB::get_schema();
            $this->_schema->schemaUpdate(function() {return true;});
        }
        return $this->_schema;
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
