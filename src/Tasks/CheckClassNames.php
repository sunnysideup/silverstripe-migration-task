<?php

namespace Sunnysideup\MigrateData\Tasks;

use SilverStripe\ORM\DB;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\ORM\DataObjectSchema;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Control\Director;
use SilverStripe\Versioned\Versioned;

class CheckClassNames extends MigrateDataTask
{

    /**
     * example:
     *     [
     *         ClassName => [
     *             FieldA,
     *             FieldB,
     *     ]
     * @var array
     */
    private static $other_fields_to_check = [];

    protected $title = 'Check all tables for valid class names';

    protected $description = 'Migrates specific data defined in yml';

    protected $enabled = true;

    protected $listOfAllClasses = [];

    protected $countsOfAllClasses = [];

    protected $dbTablesPresent = [];

    protected $fixErrors = true;

    protected $forReal = true;

    protected $dataObjectSchema = null;

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

        //make a list of all classes
        $objectClassNames = ClassInfo::subclassesFor(DataObject::class);
        foreach($objectClassNames as $objectClassName) {
            $slashed = addslashes($objectClassName);
            $this->listOfAllClasses[$slashed] = ClassInfo::shortName($objectClassName);
        }
        $this->countsOfAllClasses = array_count_values($this->listOfAllClasses);

        //check all classes
        foreach($objectClassNames as $objectClassName) {
            if($objectClassName === DataObject::class) {
                continue;
            }
            $allOK = true;
            $tableName = $this->dataObjectSchema->tableName($objectClassName);
            $this->flushNow('');
            $this->flushNow('-----');
            $this->flushNow('Checking '.$objectClassName.' => '.$tableName);
            $this->flushNow('-----');
            if(strpos($tableName, '_') !== false) {
                $this->flushNow('... '.$objectClassName.' POTENTIALLY has a table with a full class name: '.$tableName.' it is recommended that you set the private static table_name', 'error');
                $allOK = false;
            }
            if(ClassInfo::hasTable($tableName)) {
                if(! $tableName) {
                    $this->flushNow('... Can not find: '.$objectClassName. '.table_name in code ', 'error');
                    $allOK = false;
                } else {
                    if($this->tableExists($tableName)) {
                        $count = DB::query('SELECT COUNT("ID") FROM "'.$tableName.'"')->value();
                        $this->flushNow('... '.$count.' rows');
                        if($count > 0) {
                            $allFields = ['ClassName'];
                            $moreFields = $this->Config()->other_fields_to_check;
                            if(isset($moreFields[$objectClassName])) {
                                foreach($moreFields[$objectClassName] as $additionalField) {
                                    $allFields[] = $additionalField;
                                }
                            }
                            foreach($allFields as $fieldName) {
                                if($this->fieldExists($tableName, $fieldName)) {
                                    $this->fixingClassNames($tableName, $objectClassName, $fieldName, false);
                                } else {
                                    $this->flushNow('... Can not find: '.$tableName.'.'.$fieldName.' in database.');
                                }
                            }
                        }
                    } else {
                        $this->flushNow('... Can not find: '.$tableName.' in database.', 'error');
                        $allOK = false;
                    }
                }
            } else {
                $this->flushNow('... No table needed');
            }
            if($allOK) {
                $this->flushNow('... OK', 'created');
            } else {
                $this->flushNow('... ERRORS', 'error');
            }
        }

    }


    protected function fixingClassNames($tableName, $objectClassName, $fieldName = 'ClassName', $fake = false) {
        $this->flushNow('... CHECKING '.$tableName.'.'.$fieldName.' ...');
        $count = DB::query('SELECT COUNT("ID") FROM "'.$tableName.'"')->value();
        $where = '"'.$fieldName.'" NOT IN (\''.implode("', '", array_keys($this->listOfAllClasses) ).'\')';
        $whereA = $where." AND ( \"".$fieldName."\" IS NULL OR \"".$fieldName."\" = '' )";
        $whereB = $where." AND NOT ( \"".$fieldName."\" IS NULL OR \"".$fieldName."\" = '' )";
        $rowsToFix = DB::query('SELECT COUNT("ID") FROM "'.$tableName.'" WHERE '.$where)->value();
        $rowsToFixA = DB::query('SELECT COUNT("ID") FROM "'.$tableName.'" WHERE '.$whereA)->value();
        $rowsToFixB = DB::query('SELECT COUNT("ID") FROM "'.$tableName.'" WHERE '.$whereB)->value();
        if($rowsToFix > 0) {
            if($count === $rowsToFix) {
                $this->flushNow('... All rows '.$count.' in table '.$tableName.' are broken: ', 'error');
            } else {
                $this->flushNow('... '.$rowsToFix.' errors in "'.$fieldName.'" values:');
                if($rowsToFixA) {
                    $this->flushNow('... ... '.$rowsToFixA.' in table '.$tableName.' do not have a '.$fieldName.' at all and ', 'error');
                }
                if($rowsToFixB) {
                    $this->flushNow('... ... '.$rowsToFixB.' in table '.$tableName.' have a bad '.$fieldName.'');
                }
            }
            if($this->fixErrors) {

                //work out if we can set it to the long form of a short ClassName
                $rows = DB::query('SELECT '.$fieldName.', COUNT("ID") AS C FROM '.$tableName.' GROUP BY "'.$fieldName.'" HAVING '.$where.' ORDER BY C DESC');
                foreach($rows as $row){
                    if(! $row[$fieldName]) {
                        $row[$fieldName] = '--- NO VALUE ---';
                    }
                    $this->flushNow('... ... '.$row['C'].' '.$row[$fieldName]);
                    if(isset($this->countsOfAllClasses[$row[$fieldName]])) {
                        if($this->countsOfAllClasses[$row[$fieldName]] === 1) {
                            $longNameAlreadySlashed = array_search($row[$fieldName], $this->listOfAllClasses);
                            if($longNameAlreadySlashed) {
                                $this->flushNow('... ... ... Updating '.$row[$fieldName].' to '.$longNameAlreadySlashed.' - based in short to long mapping of the '.$fieldName.' field. ', 'created');
                                if($this->forReal) {
                                    DB::query('
                                        UPDATE "'.$tableName.'"
                                        SET "'.$tableName.'"."'.$fieldName.'" = \''.$longNameAlreadySlashed.'\'
                                        WHERE "'.$fieldName.'" = \''.$row[$fieldName].'\''
                                    );
                                    $this->flushNow('... ... updated '.DB::affected_rows().' rows');
                                }
                            }
                        }
                    }
                }

                //only try to work out what is going on when it is a ClassName Field!
                if($fieldName === 'ClassName') {
                    $options = ClassInfo::subclassesFor($objectClassName);
                    $checkTables = [];
                    foreach($options as $key => $optionClassName) {
                        if($optionClassName !== $objectClassName) {
                            $optionTableName = $this->dataObjectSchema->tableName($objectClassName);
                            if(! $this->tableExists($optionTableName) || $optionTableName === $tableName) {
                                unset($options[$key]);
                            } else {
                                $checkTables[$optionClassName] = $optionTableName;
                            }
                        }
                    }
                    $rows = DB::query('SELECT "ID", "'.$fieldName.'" FROM "'.$tableName.'" WHERE '.$where);
                    foreach($rows as $row) {
                        //check if it is the short name ...
                        $optionCount = 0;
                        $matchedClassName = '';
                        foreach($checkTables as $optionClassName => $optionTableName) {
                            $hasMatch = DB::query('
                                    SELECT COUNT("'.$tableName.'"."ID")
                                    FROM "'.$tableName.'"
                                        INNER JOIN "'.$optionTableName.'"
                                            ON "'.$optionTableName.'"."ID" = "'.$tableName.'"."ID"
                                    WHERE "'.$tableName.'"."ID" = '.$row['ID']
                                )->value();
                            if($hasMatch === 1) {
                                $optionCount++;
                                $matchedClassName = $optionClassName;
                                if($optionCount > 1) {
                                    break;
                                }
                            }
                        }
                        if($optionCount === 0) {
                            if(! $row[$fieldName]) {
                                $row[$fieldName] = '--- NO VALUE ---';
                            }
                            $this->flushNow('... Updating '.$fieldName.' to '.$objectClassName.' for ID = '.$row['ID'].', '.$fieldName.' = '.$row[$fieldName].' - based on inability to find matching IDs in any child class tables', 'created');
                            if($this->forReal) {
                                DB::query('
                                    UPDATE "'.$tableName.'"
                                    SET "'.$tableName.'"."'.$fieldName.'" = \''.addslashes($objectClassName).'\'
                                    WHERE ID = '.$row['ID']
                                );
                                $this->flushNow('... ... DONE updated '.DB::affected_rows().' rows');
                            }
                        } elseif($optionCount === 1 && $matchedClassName) {
                            $this->flushNow('... Updating '.$fieldName.' to '.$matchedClassName.' ID = '.$row['ID'].', '.$fieldName.' = '.$row[$fieldName].' - based on matching row in exactly one child class table', 'created');
                            if($this->forReal) {
                                DB::query('
                                    UPDATE "'.$tableName.'"
                                    SET "'.$tableName.'"."'.$fieldName.'" = \''.addslashes($matchedClassName).'\'
                                    WHERE ID = '.$row['ID']
                                );
                            }
                        } else {
                            $this->flushNow('... ERROR: can not find best '.$fieldName.' for '.$tableName.'.ID = '.$row['ID'].' current value: '.$row[$fieldName], 'error');
                        }
                    }
                } else {
                    $this->flushNow('... Updating "'.$tableName.'"."'.$fieldName.'" TO NULL WHERE '.$where, 'created');
                    if($this->forReal) {
                        DB::query('UPDATE "'.$tableName.'" SET "'.$fieldName.'" = \'\' WHERE '.$where);
                        $this->flushNow('... ... DONE updated '.DB::affected_rows().' rows');
                    }
                }
            }
        }

        //run again with versioned tables ...
        if($fake === false) {
            foreach(['_Live', '_Versions'] as $extension) {
                $testTable = $tableName.$extension;
                if($this->tableExists($testTable)) {
                    $this->fixingClassNames($testTable, $objectClassName, $fieldName, true);
                } else {
                    $this->flushNow('... ... there is no table called: '.$testTable);
                }
            }
        }
    }

}
