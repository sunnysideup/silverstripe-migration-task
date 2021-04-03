<?php

namespace Sunnysideup\MigrateData\Tasks;

use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;

class WriteAllDataObjects extends MigrateDataTaskBase
{
    protected $title = 'Creates one of each DataObjects and then deletes it again';

    protected $description = 'Use this task to find errors in your setup';

    protected $enabled = true;

    protected $listOfFieldTypesRaw = [];

    protected $listOfFieldTypesClean = [];

    /**
     * example:
     *      [
     *          'MySensitiveGuy',
     *          'MySensitiveGal',
     *      ].
     *
     * @var array
     */
    private static $objects_to_ignore_for_creation = [
        DataObject::class,
    ];

    /**
     * example:
     *      [
     *          'MySensitiveGuy',
     *          'MySensitiveGal',
     *      ].
     *
     * @var array
     */
    private static $objects_to_ignore_for_updates = [
        DataObject::class,
    ];

    /**
     * example:
     *      [
     *          'MySensitiveGuy',
     *          'MySensitiveGal',
     *      ].
     *
     * @var array
     */
    private static $fields_to_ignore_for_updates = [
        'ID',
        'Created',
        'LastEdited',
        'TempIDHash',
        'TempIDExpired',
        'Password',
        'AutoLoginHash',
        'AutoLoginExpired',
        'PasswordEncryption',
        'Salt',
        'ExtraMeta', //causes error, not sure why ...
    ];

    /**
     * example:
     *      [
     *          'MySensitiveGuyTwo' =>
     *          [
     *              'Title' => 'AAA',
     *          ]
     *      ].
     *
     * @var array
     */
    private static $required_defaults = [];

    public function getExampleValue($obj, $name, $type)
    {
        $value = null;
        $ignoreList = $this->Config()->get('fields_to_ignore_for_updates');
        if (isset($this->listOfFieldTypesRaw[$type])) {
            ++$this->listOfFieldTypesRaw[$type];
        } else {
            $this->listOfFieldTypesRaw[$type] = 1;
        }
        if (in_array($name, $ignoreList, true)) {
            $this->flushNow('... ... SKIPPING ' . $name);
        } else {
            $typeArray = explode('(', $type);
            $realType = $typeArray[0];

            switch ($realType) {
                case 'Varchar':
                case 'Text':
                    $value = 'TestValue';

                    break;
                case 'Boolean':
                    $value = 1;

                    break;
                case 'HTMLText':
                case 'HTMLFragment':
                    $value = '<p>Hello World';

                    break;
                case 'Int':
                    $value = 2;

                    break;
                case 'Enum':
                    $values = $obj->dbObject($name)->enumValues(false);
                    $value = key($values);

                    break;
                case 'DBFile':
                    break;
                case 'Datetime':
                    $value = date('Y-m-d h:i:s');

                    break;
                case 'Date':
                    $value = date('Y-m-d');

                    break;
            }
            if (isset($this->listOfFieldTypesClean[$realType])) {
                ++$this->listOfFieldTypesClean[$realType];
            } else {
                $this->listOfFieldTypesClean[$realType] = 1;
            }
        }

        return $value;
    }

    protected function performMigration()
    {
        $ignoreForCreationArray = $this->Config()->get('objects_to_ignore_for_creation');
        $ignoreForUpdatesArray = $this->Config()->get('objects_to_ignore_for_updates');
        $defaults = $this->Config()->get('objects_to_ignore');

        //make a list of all classes
        $objectClassNames = ClassInfo::subclassesFor(DataObject::class);
        $this->flushNow(' ');
        $this->flushNowLine();
        $this->flushNow('FOUND ' . count($objectClassNames) . ' classes');
        $this->flushNowLine();
        $this->flushNowLine();
        foreach ($objectClassNames as $objectClassName) {
            $this->flushNowLine();
            $this->flushNow($objectClassName . ': ');
            $this->flushNowLine();
            if (in_array($objectClassName, $ignoreForCreationArray, true)) {
                $this->flushNow('... IGNORING ');
            } else {
                $defaultFields = isset($defaults[$objectClassName]) ?
                    $defaults[$objectClassName] : [];
                $this->flushNow('... CREATING ');
                $obj = $objectClassName::create($defaultFields);
                $this->flushNow('... WRITING ');
                $obj->write();
                $fields = $obj->Config()->get('db');
                if (in_array($objectClassName, $ignoreForUpdatesArray, true)) {
                    $this->flushNow('... IGNORING UPDATE');
                } else {
                    $this->flushNow('... UPDATING ');
                    foreach ($fields as $name => $type) {
                        $value = $this->getExampleValue($obj, $name, $type);
                        if (null !== $value) {
                            $obj->{$name} = $value;
                        }
                    }
                    $obj->write();
                }
                $this->flushNow('... DELETING ');
                $obj->delete();
            }
        }
        $this->flushNowLine();
        $this->flushNow(print_r($this->listOfFieldTypesRaw, 1));
        $this->flushNowLine();
        $this->flushNow(print_r($this->listOfFieldTypesClean, 1));
        $this->flushNowLine();
    }
}
