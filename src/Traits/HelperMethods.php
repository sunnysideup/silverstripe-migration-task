<?php

namespace Sunnysideup\MigrateData\Traits;

use Exception;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Versioned\Versioned;
use Sunnysideup\Flush\FlushNow;

trait HelperMethods
{
    use FlushNow;

    public function deleteObject($obj)
    {
        if ($obj->exists()) {
            FlushNow::do_flush('DELETING ' . $obj->ClassName . '.' . $obj->ID, 'deleted');
            if ($obj->hasExtension(Versioned::class)) {
                $obj->DeleteFromStage(Versioned::LIVE);
                $obj->DeleteFromStage(Versioned::DRAFT);
            } else {
                $obj->delete();
            }
            @$obj->flushCache();
        } else {
            FlushNow::do_flush('DOES NOT EXIST', 'added');
        }
    }

    /**
     * @param array  $queries list of queries
     * @param string $name    what is this list about?
     */
    protected function runSQLQueries($queries, $name = 'UPDATE QUERIES')
    {
        if ([] !== $queries) {
            $this->flushNow('<h3>Performing ' . $name . ' Queries</h3>');
            foreach ($queries as $sqlQuery) {
                $this->runUpdateQuery($sqlQuery);
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
        } catch (Exception $exception) {
            $this->flushNow($prefix . "ERROR: Unable to run '{$sqlQuery}'", 'deleted');
            $this->flushNow('' . $exception->getMessage() . '', 'deleted');
        }
    }

    /**
     * @param array $publishClasses list of class names to write / publish
     */
    protected function runPublishClasses(array $publishClasses)
    {
        if ([] !== $publishClasses) {
            $this->flushNow('<h3>Publish classes</h3>');
            foreach ($publishClasses as $publishClass) {
                $this->flushNow('<h6>Publishing ' . $publishClass . '</h6>');

                try {
                    $count = 0;
                    $publishItems = $publishClass::get();
                    foreach ($publishItems as $publishItem) {
                        ++$count;
                        $this->flushNow(
                            'Publishing ' . $count . ' of ' . $publishItems->count() .
                                ' ' .
                                $publishClass .
                                ' item' . (1 === $publishItems->exists() ? '' : 's') . '.'
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

    protected function makeTableObsolete(string $tableName): bool
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

    protected function tableExists(string $tableName, ?bool $forceRefresh = false): bool
    {
        $schema = $this->getSchema();
        return ((bool) $schema->hasTable($tableName));
    }

    protected function clearTable(string $tableName)
    {
        DB::get_conn()->clearTable($tableName);
    }

    protected function replaceTable(string $a, string $b, ?bool $keepBackup = false)
    {
        if ($this->tableExists($a)) {
            if ($this->tableExists($b)) {
                $itemsInDB = DB::query('SELECT DISTINCT ID FROM  ' .  stripslashes($b) . ';');
                if ($itemsInDB->numRecords() > 0 && $keepBackup) {
                    $this->makeObsolete($b, $b.'_BACKUP');
                    $this->flushNow('Backing up ' . $b, 'deleted');
                }
                $this->dropTable(stripslashes($b));
            }

            if (! $this->tableExists($b)) {
                $this->renameTable($a, $b);
            } else {
                $this->flushNow('Could not delete ' . $b, 'deleted');
            }
        }
    }

    protected function makeObsolete(string $tableName)
    {
        $this->flushNow('Making obsolete ' . $tableName, 'deleted');
        $this->getSchema()->dontRequireTable($tableName);

        //backup!
        $this->replaceTable($tableName, '_obsolete'.$tableName.'_99');
    }

    protected function dropTable(string $tableName)
    {
        $this->flushNow('Deleting ' . $tableName, 'deleted');
        DB::query('DROP TABLE "' . stripslashes($tableName). '";');
    }

    protected function renameTable(string $a, string $b)
    {
        if(
            $a !== stripslashes($a) ||
            $b !==  stripslashes($b)
        ) {
            $this->flushNow('Special slashes case "' . $a . '" to "' . $b.'"', 'warning');
            DB::query("ALTER TABLE \"".stripslashes($a)."\" RENAME \"".stripslashes($b)."\"");
            return;
        }
        $this->flushNow('Moving "' . $a . '" to "' . $b.'"', 'warning');
        if(!$this->tableExists($a)) {
            $this->flushNow(' -- Could not find "' . $a . '", consider using replaceTable', 'deleted');
            return;
        }
        if($this->tableExists($b)) {
            $this->flushNow(' -- Destination table already exists "' . $b . '", consider using replaceTable', 'deleted');
            return;
        }
        $this->getSchema()->renameTable($a, $b);
    }

    protected function fieldExists(string $tableName, string $fieldName): bool
    {
        $key = $tableName . '_' . $fieldName;
        if (! isset($this->_cacheFieldExists[$key])) {
            $schema = $this->getSchema();
            $fieldList = $schema->fieldList($tableName);

            $this->_cacheFieldExists[$key] = isset($fieldList[$fieldName]);
        }

        return $this->_cacheFieldExists[$key];
    }

    protected function renameField(string $table, string $oldFieldName, string $newFieldName)
    {
        $this->getSchema()->dontRequireField($table, $oldFieldName, $newFieldName);
    }

    protected function getSchema()
    {
        if (null === $this->_schema) {
            $this->_schema = DB::get_schema();
            $this->_schema->schemaUpdate(function () {
                return true;
            });
        }

        return $this->_schema;
    }

    protected function getSchemaForDataObject()
    {
        if (null === $this->_schemaForDataObject) {
            $this->_schemaForDataObject = DataObject::getSchema();
        }

        return $this->_schemaForDataObject;
    }

    protected function getListOfIDs(string $tableName, ?array $leftJoin = [], ?string $where = '')
    {
        return $this->getListAsIterableQuery($tableName, $leftJoin = [], $where = '')
            ->keyedColumn('ID')
        ;
    }

    protected function getListAsIterableQuery(string $tableName, ?array $leftJoin = [], ?string $where = '')
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

    protected function writeObject($obj, ?array $row = [], ?bool $isPage = false)
    {
        DataObject::Config()->set('validation_enabled', false);
        if ($obj->hasMethod('writeToStage')) {
            $obj->writeToStage(Versioned::DRAFT);
            $obj->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
            $obj->publishRecursive();
        } else {
            $obj->write();
        }
        $obj->flushCache();
    }

    protected function writePage($obj, $row)
    {
        return $this->writeObject($obj, $row, $isPage = true);
    }

}
