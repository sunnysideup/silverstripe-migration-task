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
                        if($this->fieldExists($tableName, 'ClassName')) {
                            $this->fixingClassNames($tableName, $objectClassName, false);
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
                $this->flushNow('... OK');
            }
        }

    }


    protected function fixingClassNames($tableName, $objectClassName, $fake = false) {
        $count = DB::query('SELECT COUNT("ID") FROM "'.$tableName.'"')->value();
        $where = '"ClassName" NOT IN (\''.implode("', '", array_keys($this->listOfAllClasses) ).'\')';
        $whereA = $where." AND ( \"ClassName\" IS NULL OR ClassName = '' )";
        $whereB = $where." AND NOT ( \"ClassName\" IS NULL OR ClassName = '' )";
        $rowsToFix = DB::query('SELECT COUNT("ID") FROM "'.$tableName.'" WHERE '.$where)->value();
        $rowsToFixA = DB::query('SELECT COUNT("ID") FROM "'.$tableName.'" WHERE '.$whereA)->value();
        $rowsToFixB = DB::query('SELECT COUNT("ID") FROM "'.$tableName.'" WHERE '.$whereB)->value();
        if($rowsToFix > 0) {
            if($count === $rowsToFix) {
                $this->flushNow('... All rows '.$count.' in table '.$tableName.' are broken: ', 'error');
            } else {
                $this->flushNow('... '.$rowsToFix.' errors in ClassName values:');
                if($rowsToFixA) {
                    $this->flushNow('... ... '.$rowsToFixA.' in table '.$tableName.' do not have a ClassName at all and ', 'error');
                }
                if($rowsToFixB) {
                    $this->flushNow('... ... '.$rowsToFixB.' in table '.$tableName.' have a bad ClassName');
                }
            }
            $rows = DB::query('SELECT ClassName, COUNT("ID") AS C FROM '.$tableName.' GROUP BY "ClassName" HAVING '.$where.' ORDER BY C DESC');
            foreach($rows as $row){
                if(! $row['ClassName']) {
                    $row['ClassName'] = '--- NO VALUE ---';
                }
                $this->flushNow('... ... '.$row['C'].' '.$row['ClassName']);
                if($this->fixErrors && isset($this->countsOfAllClasses[$row['ClassName']])) {
                    if($this->countsOfAllClasses[$row['ClassName']] === 1) {
                        $longNameAlreadySlashed = array_search($row['ClassName'], $this->listOfAllClasses);
                        if($longNameAlreadySlashed) {
                            $this->flushNow('... ... ... Updating '.$row['ClassName'].' to '.$longNameAlreadySlashed.' - based in short to long ClassName mapping', 'created');
                            if($this->forReal) {
                                DB::query('
                                    UPDATE "'.$tableName.'"
                                    SET "'.$tableName.'"."ClassName" = \''.$longNameAlreadySlashed.'\'
                                    WHERE "ClassName" = \''.$row['ClassName'].'\''
                                );
                                $this->flushNow('... ... updated '.DB::affected_rows().' rows');
                            }
                        }
                    }
                }
            }
            if($this->fixErrors) {
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
                $rows = DB::query('SELECT "ID", "ClassName" FROM "'.$tableName.'" WHERE '.$where);
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
                        if(! $row['ClassName']) {
                            $row['ClassName'] = '--- NO VALUE ---';
                        }
                        $this->flushNow('... Updating ClassName to '.$objectClassName.' for ID = '.$row['ID'].', ClassName = '.$row['ClassName'].' - based on inability to find matching IDs in any child class tables', 'created');
                        if($this->forReal) {
                            DB::query('
                                UPDATE "'.$tableName.'"
                                SET "'.$tableName.'"."ClassName" = \''.addslashes($objectClassName).'\'
                                WHERE ID = '.$row['ID']
                            );
                        }
                    } elseif($optionCount === 1 && $matchedClassName) {
                        $this->flushNow('... Updating ClassName to '.$matchedClassName.' ID = '.$row['ID'].', ClassName = '.$row['ClassName'].' - based on matching row in exactly one child class table', 'created');
                        if($this->forReal) {
                            DB::query('
                                UPDATE "'.$tableName.'"
                                SET "'.$tableName.'"."ClassName" = \''.addslashes($matchedClassName).'\'
                                WHERE ID = '.$row['ID']
                            );
                        }
                    } else {
                        $this->flushNow('... ERROR: can not find best ClassName for '.$tableName.'.ID = '.$row['ID'].' current value: '.$row['ClassName'], 'error');
                    }
                }
            }
            //run again ...
            if($fake === false) {
                if($this->tableExists($tableName.'_Versions')) {
                    $this->fixingClassNames($tableName.'_Versions', $objectClassName, true);
                }
                if($this->tableExists($tableName.'_Live')) {
                    $this->fixingClassNames($tableName.'_Live', $objectClassName, true);
                }
            }

        }
    }

}
