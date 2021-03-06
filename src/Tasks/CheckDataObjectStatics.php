<?php

namespace Sunnysideup\MigrateData\Tasks;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectSchema;

class CheckDataObjectStatics extends MigrateDataTaskBase
{
    /**
     * @var mixed
     */
    public $dataObjectSchema;

    protected $title = 'Data Object Statics Check';

    protected $description = 'Goes through all Data Object classes and checks for missing statics';

    protected $enabled = true;

    protected $objectClassNames = [];

    protected $listOfAllClasses = [];

    protected $countsOfAllClasses = [];

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
    private static $no_searchable_fields = [
        'Sort',
        'SortOrder',
        'ID',
        'Version',
    ];

    private static $other_fields_to_check = [];

    protected function performMigration()
    {
        $this->dataObjectSchema = Injector::inst()->get(DataObjectSchema::class);

        // make a list of all classes
        // include baseclass = false
        $this->objectClassNames = ClassInfo::subclassesFor(DataObject::class, false);
        foreach ($this->objectClassNames as $objectClassName) {
            $slashed = addslashes($objectClassName);
            $this->listOfAllClasses[$slashed] = ClassInfo::shortName($objectClassName);
        }
        $this->countsOfAllClasses = array_count_values($this->listOfAllClasses);
        $this->summaryAndSearchableCheck();
    }

    protected function summaryAndSearchableCheck()
    {
        $values = [];
        //check all classes
        foreach ($this->objectClassNames as $objectClassName) {
            if (count($this->onlyRunFor) && ! in_array($objectClassName, $this->onlyRunFor, true)) {
                continue;
            }

            $summaryFields = Config::inst()->get($objectClassName, 'summary_fields');
            // $searchableFields = Config::inst()->get($objectClassName, 'searchable_fields');
            if (! empty($summaryFields)) {
                $dbFields = array_keys(Config::inst()->get($objectClassName, 'db'));
                if (count($dbFields) > 5) {
                    $recommended = array_intersect($dbFields, $summaryFields);
                    if (count($recommended) < 2) {
                        $recommended = $dbFields;
                    }
                } else {
                    $recommended = $dbFields;
                }
                if (count($recommended) > 0) {
                    foreach ($recommended as $k => $v) {
                        if (in_array($v, $this->Config()->no_searchable_fields, true)) {
                            unset($recommended[$k]);
                        }
                    }
                    $values[$objectClassName] = $objectClassName . ':' .
                        "\n  searchable_fields:\n    - " .
                        implode("\n    - ", $recommended)
                        . "\n\n";
                    $this->flushNow('... Error in ' . $objectClassName . ' there are summary fields, but no searchable fields', 'error');
                }
            }
            // $this->flushNow('... OK', 'created');
        }
        echo '<pre>';
        echo "\n# Autocreated from: Sunnysideup-MigrateData-Tasks-CheckDataObjectStatics\n\n";
        echo implode('', $values);
        echo '</pre>';
    }
}
