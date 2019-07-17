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

class CheckDataObjectStatics extends MigrateDataTask
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

    protected $title = 'Data Object Statics Check';

    protected $description = 'Goes through all Data Object classes and checks for missing statics';

    protected $enabled = true;

    protected $listOfAllClasses = [];

    protected $countsOfAllClasses = [];

    protected $onlyRunFor = [];

    protected function performMigration()
    {

        $this->dataObjectSchema = Injector::inst()->get(DataObjectSchema::class);

        // make a list of all classes
        // include baseclass = false
        $objectClassNames = ClassInfo::subclassesFor(DataObject::class, false);
        foreach ($objectClassNames as $objectClassName) {
            $slashed = addslashes($objectClassName);
            $this->listOfAllClasses[$slashed] = ClassInfo::shortName($objectClassName);
        }
        $this->countsOfAllClasses = array_count_values($this->listOfAllClasses);

        //check all classes
        foreach ($objectClassNames as $objectClassName) {
            if(count($this->onlyRunFor) && ! in_array($objectClassName, $this->onlyRunFor)) {
                continue;
            }

            $this->flushNow($objectClassName);
            $summaryFields = Config::inst()->get('summary_fields');
            $searchableFields = Config::inst()->get('searchable_fields');
            if(count($summaryFields) && ! count($searchableFields)) {
                $this->flushNow('... Error in '.$objectClassName.' there are summary fields, but no searchable fields', 'error');
            } else {
                $this->flushNow('... OK', 'created');
            }
        }
    }

}
