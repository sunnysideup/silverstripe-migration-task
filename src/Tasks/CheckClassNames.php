<?php

namespace Sunnysideup\MigrateData\Tasks;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectSchema;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use Page;

use SilverStripe\CMS\Model\SiteTree;

class CheckClassNames extends MigrateDataTaskBase
{
    protected $title = 'Check all tables for valid class names';

    protected $description = 'Check all tables for valid class names';

    protected $enabled = true;

    protected $listOfAllClasses = [];

    protected $countsOfAllClasses = [];

    protected $dbTablesPresent = [];

    protected $fixErrors = true;

    protected $extendFieldSize = true;

    protected $forReal = true;

    protected $dataObjectSchema;

    protected $onlyRunFor = [];

    /**
     * example:
     *     [
     *         ClassName => [
     *             FieldA,
     *             FieldB,
     *     ].
     *
     * @var array
     */
    private static $other_fields_to_check = [
        'ElementalArea' => [
            'OwnerClassName',
        ],
    ];

    protected function performMigration()
    {
        $this->dataObjectSchema = Injector::inst()->get(DataObjectSchema::class);

        //get tables in DB
        $this->dbTablesPresent = [];
        $rows = DB::query('SHOW tables');
        foreach ($rows as $row) {
            $table = array_pop($row);
            $this->dbTablesPresent[$table] = $table;
        }

        // make a list of all classes
        // include baseclass = false
        $objectClassNames = ClassInfo::subclassesFor(DataObject::class, false);
        foreach ($objectClassNames as $objectClassName) {
            $slashed = addslashes($objectClassName);
            $this->listOfAllClasses[$slashed] = ClassInfo::shortName($objectClassName);
        }
        $this->countsOfAllClasses = array_count_values($this->listOfAllClasses);
        $allOK = true;

        //check all classes
        foreach ($objectClassNames as $objectClassName) {
            if (count($this->onlyRunFor) && ! in_array($objectClassName, $this->onlyRunFor, true)) {
                continue;
            }
            $fields = $this->dataObjectSchema->databaseFields($objectClassName, false);
            if (count($fields) > 0) {
                $tableName = $this->dataObjectSchema->tableName($objectClassName);
                $this->flushNow('');
                $this->flushNowLine();
                $this->flushNow('Checking ' . $objectClassName . ' => ' . $tableName);
                $this->flushNowLine();
                $tableNameStaticValue = Config::inst()->get($objectClassName, 'table_name');
                if ($tableNameStaticValue !== $tableName) {
                    $this->flushNow('... ' . $objectClassName . ' POTENTIALLY has a table with a full class name: ' . $tableName . ' it is recommended that you set the private static table_name', 'error');
                    $allOK = false;
                }
                if (! $tableName) {
                    $this->flushNow('... Can not find: ' . $objectClassName . '.table_name in code ', 'error');
                    $allOK = false;
                } elseif ($this->tableExists($tableName)) {
                    // NB. we still run for zero rows, because we may need to fix versioned records
                    $count = DB::query('SELECT COUNT("ID") FROM "' . $tableName . '"')->value();
                    $this->flushNow('... ' . $count . ' rows');
                    $allFields = [
                        'ClassName',
                    ];
                    $moreFields = $this->Config()->other_fields_to_check;
                    if (isset($moreFields[$objectClassName])) {
                        foreach ($moreFields[$objectClassName] as $additionalField) {
                            $allFields[] = $additionalField;
                        }
                    }
                    foreach ($allFields as $fieldName) {
                        if ($this->fieldExists($tableName, $fieldName)) {
                            $this->fixClassNames($tableName, $objectClassName, $fieldName);
                        } else {
                            $this->flushNow('... Can not find: ' . $tableName . '.' . $fieldName . ' in database.');
                        }
                    }
                } else {
                    $this->flushNow('... Can not find: ' . $tableName . ' in database.', 'error');
                    $allOK = false;
                }
            } else {
                $this->flushNow('... No table needed');
            }
            if ($allOK) {
                $this->flushNow('... OK', 'created');
            } else {
                $this->flushNow('... ERRORS', 'error');
            }
        }
    }

    protected function fixClassNames(string $tableName, string $objectClassName, ?string $fieldName = 'ClassName', ?bool $versionedTable = false)
    {
        $this->flushNow('... CHECKING ' . $tableName . '.' . $fieldName . ' ...');
        $count = DB::query('SELECT COUNT("ID") FROM "' . $tableName . '"')->value();
        $where = '"' . $fieldName . '" NOT IN (\'' . implode("', '", array_keys($this->listOfAllClasses)) . "')";
        $whereA = $where . ' AND ' . '(' . '"' . $fieldName . '" IS NULL OR "' . $fieldName . '" = \'\' )';
        $whereB = $where . ' AND NOT ' . '(' . '"' . $fieldName . '" IS NULL OR "' . $fieldName . '" = \'\' )';
        $rowsToFix = DB::query('SELECT COUNT("ID") FROM "' . $tableName . '" WHERE ' . $where)->value();
        $rowsToFixA = DB::query('SELECT COUNT("ID") FROM "' . $tableName . '" WHERE ' . $whereA)->value();
        $rowsToFixB = DB::query('SELECT COUNT("ID") FROM "' . $tableName . '" WHERE ' . $whereB)->value();
        if ($rowsToFix > 0) {
            if ($count === $rowsToFix) {
                $this->flushNow('... All rows ' . $count . ' in table ' . $tableName . ' are broken: ', 'error');
            } else {
                $this->flushNow('... ' . $rowsToFix . ' errors in "' . $fieldName . '" values:');
                if ($rowsToFixA) {
                    $this->flushNow('... ... ' . $rowsToFixA . ' in table ' . $tableName . ' do not have a ' . $fieldName . ' at all and ', 'error');
                }
                if ($rowsToFixB) {
                    $this->flushNow('... ... ' . $rowsToFixB . ' in table ' . $tableName . ' have a bad ' . $fieldName . '');
                }
            }
            if ($this->fixErrors) {
                if($this->extendFieldSize) {
                    $this->fixFieldSize($tableName);
                }
                //work out if we can set it to the long form of a short ClassName
                $rows = DB::query('SELECT ' . $fieldName . ', COUNT("ID") AS C FROM ' . $tableName . ' GROUP BY "' . $fieldName . '" HAVING ' . $where . ' ORDER BY C DESC');
                foreach ($rows as $row) {
                    if (! $row[$fieldName]) {
                        $row[$fieldName] = '--- NO VALUE ---';
                    }
                    $this->flushNow('... ... ' . $row['C'] . ' ' . $row[$fieldName]);
                    if (isset($this->countsOfAllClasses[$row[$fieldName]])) {
                        if (1 === $this->countsOfAllClasses[$row[$fieldName]]) {
                            $longNameAlreadySlashed = array_search($row[$fieldName], $this->listOfAllClasses, true);
                            if ($longNameAlreadySlashed) {
                                $this->flushNow('... ... ... Updating ' . $row[$fieldName] . ' to ' . $longNameAlreadySlashed . ' - based in short to long mapping of the ' . $fieldName . ' field. ', 'created');
                                if ($this->forReal) {
                                    $this->runUpdateQuery(
                                        '
                                        UPDATE "' . $tableName . '"
                                        SET "' . $tableName . '"."' . $fieldName . '" = \'' . $longNameAlreadySlashed . '\'
                                        WHERE "' . $fieldName . '" = \'' . $row[$fieldName] . "'",
                                        2
                                    );
                                }
                            }
                        }
                    }
                }

                //only try to work out what is going on when it is a ClassName Field!
                if ('ClassName' === $fieldName) {
                    $options = ClassInfo::subclassesFor($objectClassName);
                    $checkTables = [];
                    foreach ($options as $key => $optionClassName) {
                        if ($optionClassName !== $objectClassName) {
                            $optionTableName = $this->dataObjectSchema->tableName($objectClassName);
                            if (! $this->tableExists($optionTableName) || $optionTableName === $tableName) {
                                unset($options[$key]);
                            } else {
                                $checkTables[$optionClassName] = $optionTableName;
                            }
                        }
                    }
                    //fix bad rows....
                    $rows = DB::query('SELECT "ID", "' . $fieldName . '" FROM "' . $tableName . '" WHERE ' . $where);
                    foreach ($rows as $row) {
                        //check if it is the short name ...
                        $optionCount = 0;
                        $matchedClassName = '';
                        foreach ($checkTables as $optionClassName => $optionTableName) {
                            $hasMatch = DB::query('
                                    SELECT COUNT("' . $tableName . '"."ID")
                                    FROM "' . $tableName . '"
                                        INNER JOIN "' . $optionTableName . '"
                                            ON "' . $optionTableName . '"."ID" = "' . $tableName . '"."ID"
                                    WHERE "' . $tableName . '"."ID" = ' . $row['ID'])->value();
                            if (1 === $hasMatch) {
                                ++$optionCount;
                                $matchedClassName = $optionClassName;
                                if ($optionCount > 1) {
                                    break;
                                }
                            }
                        }
                        if (0 === $optionCount) {
                            if (! $row[$fieldName]) {
                                $row[$fieldName] = '--- NO VALUE ---';
                            }
                            $this->flushNow('... Updating ' . $fieldName . ' to ' . $objectClassName . ' for ID = ' . $row['ID'] . ', ' . $fieldName . ' = ' . $row[$fieldName] . ' - based on inability to find matching IDs in any child class tables', 'created');
                            if ($this->forReal) {
                                $this->runUpdateQuery(
                                    '
                                    UPDATE "' . $tableName . '"
                                    SET "' . $tableName . '"."' . $fieldName . '" = \'' . addslashes($objectClassName) . '\'
                                    WHERE ID = ' . $row['ID'],
                                    2
                                );
                            }
                        } elseif (1 === $optionCount && $matchedClassName) {
                            $this->flushNow('... Updating ' . $fieldName . ' to ' . $matchedClassName . ' ID = ' . $row['ID'] . ', ' . $fieldName . ' = ' . $row[$fieldName] . ' - based on matching row in exactly one child class table', 'created');
                            if ($this->forReal) {
                                $this->runUpdateQuery(
                                    'UPDATE "' . $tableName . '"
                                    SET "' . $tableName . '"."' . $fieldName . '" = \'' . addslashes($matchedClassName) . '\'
                                    WHERE ID = ' . $row['ID'],
                                    2
                                );
                            }
                        } else {
                            $bestValue = $this->bestClassName($objectClassName, $tableName, $fieldName);
                            $this->flushNow('... ERROR: can not find best ' . $fieldName . ' for ' . $tableName . '.ID = ' . $row['ID'] . ' current value: ' . $row[$fieldName] . ' we recommend: ' . $bestValue, 'error');
                            $this->runUpdateQuery(
                                'UPDATE "' . $tableName . '"
                                SET "' . $tableName . '"."' . $fieldName . '" = \'' . addslashes($bestValue) . '\'
                                WHERE ID = ' . $row['ID'],
                                2
                            );
                        }
                    }
                } else {
                    $this->flushNow('... Updating "' . $tableName . '"."' . $fieldName . '" TO NULL WHERE ' . $where, 'created');
                    if ($this->forReal) {
                        $this->runUpdateQuery('UPDATE "' . $tableName . '" SET "' . $fieldName . '" = \'\' WHERE ' . $where, 2);
                    }
                }
            }
        }
        //run again with versioned tables ...
        if (false === $versionedTable) {
            foreach (['_Live', '_Versions'] as $extension) {
                $testTable = $tableName . $extension;
                if ($this->tableExists($testTable)) {
                    $this->fixClassNames($testTable, $objectClassName, $fieldName, true);
                } else {
                    $this->flushNow('... ... there is no table called: ' . $testTable);
                }
            }
        }
    }

    protected function fixFieldSize($tableName)
    {
        $databaseName = DB::get_conn()->getSelectedDatabase();
        DB::query('ALTER TABLE "' . $databaseName . '"."'.$tableName.'" CHANGE ClassName ClassName VARCHAR(255);');
    }

    protected $bestClassNameStore = [];

    protected function bestClassName( string $objectClassName, string $tableName, string $fieldName) : string
    {
        $keyForStore = $objectClassName.'_'.$tableName .'_'. $fieldName;
        if (! isset($this->bestClassNameStore[$keyForStore])) {
            $obj = Injector::inst()
                ->get($objectClassName);
            if($obj instanceof SiteTree) {
                if(class_exists(Page::class)) {
                    $this->bestClassNameStore[$keyForStore] = 'Page';
                    return $this->bestClassNameStore[$keyForStore];
                }
            }
            $values = $obj
                ->dbObject($fieldName)
                ->enumValues(false)
            ;
            $sql = '
                SELECT ' . $fieldName . ', COUNT(*) AS magnitude
                FROM ' . $tableName . '
                GROUP BY ' . $fieldName . '
                ORDER BY magnitude DESC
                LIMIT 1';
            $bestValue = '';
            $rowsForBestValue = DB::query($sql);
            foreach ($rowsForBestValue as $rowForBestValue) {
                if (in_array($rowForBestValue[$fieldName], $values, true)) {
                    $bestValue = $rowForBestValue[$fieldName];

                    break;
                }
            }
            if (! $bestValue) {
                $bestValue = key($values);
            }
            $this->bestClassNameStore[$keyForStore] = $bestValue;
        }
        return $this->bestClassNameStore[$keyForStore];
    }
}
