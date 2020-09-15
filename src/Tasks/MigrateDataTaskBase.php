<?php

namespace Sunnysideup\MigrateData\Tasks;

use Exception;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLSelect;

use Sunnysideup\Flush\FlushNow;

abstract class MigrateDataTaskBase extends BuildTask
{
    use FlushNow;

    protected $title = 'Abstract Migration Class';

    protected $description = 'Please extend this class';

    protected $enabled = false;

    protected $_schema = null;

    protected $_schemaForDataObject = null;

    private $_cacheTableExists = [];

    private $_cacheFieldExists = [];

    public function run($request)
    {
        $this->flushNowLine();
        $this->flushNow('THE START - look out for THE END ...');
        $this->flushNowLine();
        $this->flushNow(
            '
            <link href="/resources/vendor/silverstripe/framework/client/styles/debug.css" rel="stylesheet">
            <ul class="build">
            ',
            '',
            false
        );
        DataObject::Config()->set('validation_enabled', false);
        ini_set('memory_limit', '1024M');
        Environment::increaseMemoryLimitTo();
        Environment::increaseTimeLimitTo(7200);

        $this->performMigration();

        $this->flushNow('</ul>', '', false);
        $this->flushNow('');
        $this->flushNow('');
        $this->flushNow('');
        $this->flushNowLine();
        $this->flushNow('THE END');
        $this->flushNowLine();
    }

    /**
     * Queries the config for Migrate definitions, and runs migrations
     * if you extend this task then overwrite it this method
     */
    abstract protected function performMigration();

    /**
     * @param  array $queries list of queries
     * @param  string $name what is this list about?
     */
    protected function runSQLQueries($queries, $name = 'UPDATE QUERIES')
    {
        if ($queries) {
            $this->flushNow('<h3>Performing ' . $name . ' Queries</h3>');
            foreach ($queries as $sqlQuery) {
                $this->runUpdateQuery($sqlQuery);
            }
        }
    }

    /**
     * @param  array $sqlQuery list of queries
     * @param  string $indents what is this list about?
     */
    protected function runUpdateQuery(string $sqlQuery, $indents = 1)
    {
        $this->flushNow(str_replace('"', '`', $sqlQuery), 'created');
        $prefix = str_repeat(' ... ', $indents);
        try {
            DB::query($sqlQuery);
            $this->flushNow($prefix . ' DONE ' . DB::affected_rows() . ' rows affected');
        } catch (Exception $e) {
            $this->flushNow($prefix . "ERROR: Unable to run '${sqlQuery}'", 'deleted');
            $this->flushNow('' . $e->getMessage() . '', 'deleted');
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
            $this->flushNow('<h3>Migrating data - Core Migration</h3>');
            foreach ($data as $dataItem) {
                if (! isset($dataItem['include_inserts'])) {
                    $dataItem['include_inserts'] = true;
                }
                if (! isset($dataItem['leftJoin'])) {
                    $dataItem['leftJoin'] = [];
                }
                if (! isset($dataItem['where'])) {
                    $dataItem['where'] = [];
                }
                if (! isset($dataItem['include_inserts'])) {
                    $dataItem['include_inserts'] = true;
                }
                if (! isset($dataItem['new_table'])) {
                    $dataItem['new_table'] = $dataItem['old_table'];
                }
                $dataItem['old_fields'] = [];
                $dataItem['new_fields'] = [];

                if (isset($dataItem['simple_move_fields'])) {
                    $dataItem['old_fields'] = $dataItem['simple_move_fields'];
                    $dataItem['new_fields'] = $dataItem['simple_move_fields'];
                } elseif (isset($dataItem['complex_move_fields'])) {
                    $dataItem['old_fields'] = array_keys($dataItem['complex_move_fields']);
                    $dataItem['new_fields'] = array_values($dataItem['complex_move_fields']);
                } else {
                    $this->flushNow('Could not find simple_move_fields or complex_move_fields.');
                }

                if (count($dataItem['new_fields']) !== count($dataItem['old_fields'])) {
                    user_error('Count of new fields does not match old fields');
                    foreach ($dataItem['old_fields'] as $value) {
                        if (intval($value) === $value) {
                            $this->flushNow('Potential error in fields: ' . print_r($dataItem['old_fields'], 1), 'error');
                        }
                    }
                    foreach ($dataItem['new_fields'] as $value) {
                        if (intval($value) === $value) {
                            $this->flushNow('Potential error in fields: ' . print_r($dataItem['new_fields'], 1), 'error');
                        }
                    }
                }
                $this->flushNow('<h6>Migrating data ' . $dataItem['old_table'] . ' to ' . $dataItem['new_table'] . '</h6>');
                $this->migrateSimple(
                    $dataItem['include_inserts'],
                    $dataItem['old_table'],
                    $dataItem['new_table'],
                    $dataItem['old_fields'],
                    $dataItem['new_fields'],
                    $dataItem['leftJoin'],
                    $dataItem['where']
                );
            }
        }
    }

    /**
     * @param  array $publishClasses list of class names to write / publish
     */
    protected function runPublishClasses($publishClasses)
    {
        if ($publishClasses) {
            $this->flushNow('<h3>Publish classes</h3>');
            foreach ($publishClasses as $publishClass) {
                $this->flushNow('<h6>Publishing ' . $publishClass . '</h6>');
                try {
                    $count = 0;
                    $publishItems = $publishClass::get();
                    foreach ($publishItems as $publishItem) {
                        $count++;
                        $this->flushNow(
                            'Publishing ' . $count . ' of ' . $publishItems->count() .
                                ' ' .
                                $publishClass .
                                ' item' . ($publishItems->count() === 1 ? '' : 's') . '.'
                        );
                        $publishItem->write();
                        if ($publishItem->hasMethod('publishRecursive')) {
                            $publishItem->doPublish();
                            $publishItem->publishRecursive();
                            $this->flushNow('... DONE - PUBLISHED');
                        } else {
                            $this->flushNow('... DONE - WRITE ONLY');
                        }
                    }
                } catch (Exception $e) {
                    $this->flushNow('Unable to publish ' . $publishClass . '', 'error');
                    $this->flushNow('' . $e->getMessage() . '', 'error');
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
    protected function migrateSimple($includeInserts, $tableOld, $tableNew, $fieldNamesOld, $fieldNamesNew, $leftJoin, $where)
    {
        if (! $this->tableExists($tableOld)) {
            $this->flushNow("${tableOld} (old table) does not exist", 'error');
        }

        if (! $this->tableExists($tableNew)) {
            $this->flushNow("${tableNew} (new table) does not exist", 'error');
        }

        try {
            $this->flushNow('getting new table IDs.');
            $newEntryIDs = $this->getListOfIDs($tableNew);

            $this->flushNow('getting old IDs.');
            $oldEntries = $this->getListAsIterableQuery($tableOld, $leftJoin, $where);
            $oldEntryIDs = [];

            //add a new line using the ID as identifier
            foreach ($oldEntries as $oldEntry) {
                if ($includeInserts) {
                    if (! in_array($oldEntry['ID'], $newEntryIDs, true)) {
                        $this->flushNow('Added row ' . $oldEntry['ID'] . ' to ' . $tableNew . '.');
                        $this->runUpdateQuery('INSERT INTO "' . $tableNew . '" ("ID") VALUES (' . $oldEntry['ID'] . ');');
                    }
                }

                array_push($oldEntryIDs, $oldEntry['ID']);
            }

            //update fields
            if (count($oldEntryIDs)) {
                //work out what option is shorter in terms of ID count:
                $this->flushNow('working out update SQL..');
                $allIDs = $this->getListOfIDs($tableNew);
                $allIDCount = count($allIDs);
                $oldIDCount = count($oldEntryIDs);
                if ($oldIDCount > ($allIDCount - $oldIDCount)) {
                    $excludeIDs = array_diff($allIDs, $oldEntryIDs);
                    if (count($excludeIDs) === 0) {
                        $excludeIDs = [0];
                    }
                    $wherePhrase = ' NOT IN (' . implode(', ', $excludeIDs) . ')';
                } else {
                    if (count($oldEntryIDs) === 0) {
                        $oldEntryIDs = [0];
                    }
                    $wherePhrase = ' IN (' . implode(', ', $oldEntryIDs) . ')';
                }

                //update the new table with the old values
                //for the rows that join with the ID and match the list of OLD ids.
                if (count($fieldNamesNew)) {
                    $updateQuery = 'UPDATE "' . $tableNew . '" AS "tablenew" ';
                    $updateQuery .= 'INNER JOIN "' . $tableOld . '" AS "tableold" ON "tablenew"."ID" = "tableold"."ID" ';
                    if (substr($tableNew, -9) === '_versions') {
                        $updateQuery .= ' AND "tablenew"."RecordID" = "tableold"."RecordID" ';
                        // also link to RecordID ...
                    }
                    $updateQuery .= 'SET ';

                    for ($i = 0; $i < count($fieldNamesNew) && $i < count($fieldNamesOld); $i++) {
                        if ($i > 0) {
                            $updateQuery .= ', ';
                        }
                        $updateQuery .= '"tablenew"."' . $fieldNamesNew[$i] . '" = "tableold"."' . $fieldNamesOld[$i] . '" ';
                    }
                    $updateQuery .= 'WHERE "tablenew"."ID" ' . $wherePhrase . ';';
                    $this->flushNow(str_replace($wherePhrase, '........', $updateQuery));
                    $this->runUpdateQuery($updateQuery, 1);
                }
            }
        } catch (Exception $e) {
            $this->flushNow("Unable to migrate ${tableOld} to ${tableNew}.", 'error');
            $this->flushNow($e->getMessage(), 'error');
        }
    }

    protected function makeTableObsolete($tableName): bool
    {
        $schema = $this->getSchema();
        if ($this->tableExists($tableName)) {
            if (! $this->tableExists('_obsolete_' . $tableName)) {
                $schema->dontRequireTable($tableName);
                return true;
            }
            $this->flushNow('Table ' . $tableName . ' is already obsolete');
        } else {
            $this->flushNow('Table ' . $tableName . ' does not exist.');
        }
        return false;
    }

    protected function tableExists($tableName): bool
    {
        if (! isset($this->_cacheTableExists[$tableName])) {
            $schema = $this->getSchema();
            $this->_cacheTableExists[$tableName] = ($schema->hasTable($tableName) ? true : false);
        }

        return $this->_cacheTableExists[$tableName];
    }

    protected function fieldExists($tableName, $fieldName): bool
    {
        $key = $tableName . '_' . $fieldName;
        if (! isset($this->_cacheFieldExists[$key])) {
            $schema = $this->getSchema();
            $fieldList = $schema->fieldList($tableName);

            $this->_cacheFieldExists[$key] = isset($fieldList[$fieldName]);
        }

        return $this->_cacheFieldExists[$key];
    }

    protected function renameField($table, $oldFieldName, $newFieldName)
    {
        $this->getSchema()->dontRequireField($table, $oldFieldName, $newFieldName);
    }

    protected function getSchema()
    {
        if ($this->_schema === null) {
            $this->_schema = DB::get_schema();
            $this->_schema->schemaUpdate(function () {
                return true;
            });
        }
        return $this->_schema;
    }

    protected function getSchemaForDataObject()
    {
        if ($this->_schemaForDataObject === null) {
            $this->_schemaForDataObject = DataObject::getSchema();
        }
        return $this->_schemaForDataObject;
    }

    protected function getListOfIDs($tableName, $leftJoin = [], $where = '')
    {
        return $this->getListAsIterableQuery($tableName, $leftJoin = [], $where = '')
            ->keyedColumn('ID');
    }

    protected function getListAsIterableQuery($tableName, $leftJoin = [], $where = '')
    {
        $sqlSelect = new SQLSelect();
        $sqlSelect->setFrom($tableName);

        if ($leftJoin) {
            $sqlSelect->addLeftJoin($leftJoin['table'], $leftJoin['onPredicate']);
        }

        if ($where) {
            $sqlSelect->addWhere($where);
        }

        $sqlSelect->setOrderBy($tableName . '.ID');
        return $sqlSelect->execute();
    }
}
