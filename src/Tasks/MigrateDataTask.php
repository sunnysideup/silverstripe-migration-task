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
        ini_set('memory_limit', '1024M');
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

            if(isset($details['pre_sql_queries'])) {
                $preSqlQueries = $details['pre_sql_queries'];
                $this->runSQLQueries($preSqlQueries, 'PRE');
            }

            if(isset($details['data'])) {
                $data = $details['data'];
                $this->runMoveData($data);
            }

            if(isset($details['publish_classes'])) {
                $publishClasses = $details['publish_classes'];
                $this->runPublishClasses($publishClasses);
            }

            if(isset($details['post_sql_queries'])) {
                $postSqlQueries = $details['post_sql_queries'];
                $this->runSQLQueries($postSqlQueries, 'POST');
            }

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
                    $this->flushNow('... DONE '.DB::affected_rows().' rows affected' );
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
     *
     *          'simple_move_fields' => ['A', 'B', 'C']
     *          OR
     *          'complex_move_fields' => ['A' => 'Anew', 'B' => 'BBew', 'C2' => 'Cnew']
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
                $dataItem['old_fields'] = [];
                $dataItem['new_fields'] = [];
                if(isset($dataItem['simple_move_fields'])) {
                    $dataItem['old_fields'] = $dataItem['simple_move_fields'];
                    $dataItem['new_fields'] = $dataItem['simple_move_fields'];
                }
                elseif(isset($dataItem['complex_move_fields'])) {
                    $dataItem['old_fields'] = array_keys($dataItem['complex_move_fields']);
                    $dataItem['new_fields'] = array_values($dataItem['complex_move_fields']);
                } else {
                    $this->flushNow('Could not find simple_move_fields or complex_move_fields.');
                }
                if(count($dataItem['new_fields']) !== count($dataItem['old_fields'])){
                    user_error('Count of new fields does not match old fields');
                    foreach($dataItem['old_fields'] as $key => $value) {
                        if(intval($value) == $value) {
                            $this->flushNow('Potential error in fields: '.print_r($dataItem['old_fields'], 1), 'error');
                        }
                    }
                    foreach($dataItem['new_fields'] as $key => $value) {
                        if(intval($value) == $value) {
                            $this->flushNow('Potential error in fields: '.print_r($dataItem['new_fields'], 1), 'error');
                        }
                    }
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
                    $count = 0;
                    $publishItems = $publishClass::get();
                    foreach ($publishItems as $publishItem) {
                        $count++;
                        $this->flushNow(
                            "Publishing " .$count.' of '. $publishItems->count() .
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
            $this->flushNow('getting new table IDs.');
            $newEntryIDs = $this->getListOfIDs($tableNew);

            $this->flushNow('getting old IDs.');
            $oldEntries = $this->getListAsIterableQuery($tableOld);
            $oldEntryIDs = [];

            //add a new line using the ID as identifier
            foreach ($oldEntries as $oldEntry) {
                if($includeInserts) {
                    if (! in_array($oldEntry['ID'], $newEntryIDs)) {
                        $this->flushNow( 'Added row ' . $oldEntry['ID'] . ' to ' . $tableNew . '.' );
                        DB::query('INSERT INTO "' . $tableNew . '" ("ID") VALUES (' . $oldEntry['ID'] . ');');
                        $this->flushNow('... DONE '.DB::affected_rows().' rows affected' );
                    }
                }

                array_push($oldEntryIDs, $oldEntry['ID']);
            }

            //update fields
            if(count($oldEntryIDs)) {

                //work out what option is shorter in terms of ID count:
                $this->flushNow('working out update SQL..');
                $allIDs = $this->getListOfIDs($tableNew);
                $allIDCount = count($allIDs);
                $oldIDCount = count($oldEntryIDs);
                if($oldIDCount > ($allIDCount - $oldIDCount)) {
                    $excludeIDs = array_diff($allIDs, $oldEntryIDs);
                    if(count($excludeIDs) === 0) {
                        $excludeIDs = [0];
                    }
                    $wherePhrase = ' NOT IN (' . implode(', ', $excludeIDs) . ')';
                } else {
                    if(count($oldEntryIDs) === 0) {
                        $oldEntryIDs = [0];
                    }
                    $wherePhrase = ' IN (' . implode(', ', $oldEntryIDs) . ')';
                }

                //update the new table with the old values
                //for the rows that join with the ID and match the list of OLD ids.
                if(count($fieldNamesNew)) {
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
                    $updateQuery .= 'WHERE "tablenew"."ID" '.$wherePhrase.';';
                    $this->flushNow(str_replace($wherePhrase, '........', $updateQuery));
                    $sqlResults = DB::query($updateQuery);
                    $this->flushNow('... DONE '.DB::affected_rows().' rows affected' );
                }
            }
        } catch (Exception $e) {
            $this->flushNow( "Unable to migrate $tableOld to $tableNew.", 'error');
            $this->flushNow($e->getMessage(), 'error');
        }

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


    private $_cacheTableExists = [];

    public function tableExists($tableName) : bool
    {
        if(! isset($this->_cacheTableExists[$tableName])) {
            $schema = $this->getSchema();
            $this->_cacheTableExists[$tableName] = $schema->hasTable($tableName);
        }

        return $this->_cacheTableExists[$tableName];
    }


    private $_cacheFieldExists = [];

    public function fieldExists($tableName, $fieldName) : bool
    {
        $key = $tableName.'_'.$fieldName;
        if(! isset($this->_cacheFieldExists[$key])) {
            $schema = $this->getSchema();
            $fieldList = $schema->fieldList($tableName);

            $this->_cacheFieldExists[$key] = isset($fieldList[$fieldName]);
        }

        return $this->_cacheFieldExists[$key];
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

    protected function getListOfIDs($tableName)
    {
        return $this->getListAsIterableQuery($tableName)
            ->keyedColumn('ID');
    }

    protected function getListAsIterableQuery($tableName)
    {
        $sqlSelect = new SQLSelect();
        $sqlSelect->setFrom($tableName);
        $sqlSelect->setOrderBy('ID');
        $sqlQuery = $sqlSelect->execute();

        return $sqlQuery;
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
