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

    protected $listOfAllClassesSlashed = [];

    protected $dbTablesPresent = [];

    protected $fixErrors = false;

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

        $schema = Injector::inst()->get(DataObjectSchema::class);

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
            $this->listOfAllClasses[$objectClassName] = $objectClassName;
            $slashed = addslashes($objectClassName);
            $this->listOfAllClassesSlashed[$slashed] = addslashes($objectClassName);
        }

        //check all classes
        foreach($objectClassNames as $objectClassName) {
            if($objectClassName === DataObject::class) {
                continue;
            }
            $allOK = true;
            $tableName = $schema->tableName($objectClassName);
            $this->flushNow('');
            $this->flushNow('-----');
            $this->flushNow('Checking '.$objectClassName.' => '.$tableName);
            $this->flushNow('-----');
            if(! $tableName) {
                $this->flushNow('... Can not find: '.$objectClassName. '.table_name in code ', 'error');
                $allOK = false;
            } else {
                if($this->tableExists($tableName)) {
                    $count = DB::query('SELECT COUNT("ID") FROM "'.$tableName.'"')->value();
                    $this->flushNow('... '.$count.' rows', 'error');
                    if($this->fieldExists($tableName, 'ClassName')) {
                        $this->fixingClassNames($tableName, $objectClassName, false);
                    }
                } else {
                    $this->flushNow('... Can not find: '.$tableName.' in database.', 'error');
                    $allOK = false;
                }
                if(strpos($tableName, '_') !== false) {
                    $this->flushNow('... '.$objectClassName.' has a table with a full class name: '.$tableName, 'error');
                    $allOK = false;
                }
            }
            if($allOK) {
                $this->flushNow('... OK');
            }

        }

        $this->flushNow('-----------------------------');
        $this->flushNow('THE END');
        $this->flushNow('-----------------------------');
    }


    protected function fixingClassNames($tableName, $objectClassName, $fake = false) {
        $count = DB::query('SELECT COUNT("ID") FROM "'.$tableName.'"')->value();
        $where = '"ClassName" NOT IN (\''.implode("', '", $this->listOfAllClassesSlashed).'\')';
        $whereA = $where.' AND ( "ClassName" IS NULL OR ClassName = \'\' )';
        $whereB = $where.' AND NOT ( "ClassName" IS NULL OR ClassName = \'\' )';
        $rowsToFix = DB::query('SELECT COUNT("ID") FROM "'.$tableName.'" WHERE '.$where)->value();
        $rowsToFixA = DB::query('SELECT COUNT("ID") FROM "'.$tableName.'" WHERE '.$whereA)->value();
        $rowsToFixB = DB::query('SELECT COUNT("ID") FROM "'.$tableName.'" WHERE '.$whereB)->value();
        if($rowsToFix > 0) {
            if($count === $rowsToFix) {
                $this->flushNow('... All rows '.$count.' in table '.$tableName.' are broken: ', 'error');
            } else {
                $this->flushNow(
                    '... '.$rowsToFix.' ERRORS! => '.
                    $rowsToFixA.' in table '.$tableName.' do not have a ClassName at all and '.
                    $rowsToFixB.' in table '.$tableName.' have a bad ClassName');
            }
            $rows = DB::query('SELECT ClassName, COUNT("ID") AS C FROM '.$tableName.' GROUP BY "ClassName" HAVING '.$where.' ORDER BY C DESC');
            foreach($rows as $row){
                if(! $row['ClassName']) {
                    $row['ClassName'] = '--- NO VALUE ---';
                }
                $this->flushNow('... ... '.$row['C'].' '.$row['ClassName'], 'error');
            }
            if($this->fixErrors) {
                DB::query('UPDATE "'.$tableName.'" SET "ClassName" = \''.$objectClassName.'\' WHERE '.$where);
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

}
