<?php

namespace Sunnysideup\MigrateData\Tasks;

use SilverStripe\Core\Config\Config;

use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use Sunnysideup\MigrateData\Tasks\MigrateDataTask;

/**
 * Used to debug a QueueJob
 *  @todo: UPGRADE: remove after upgrade
 */
class TextOrJSONToRelationshipMigration extends MigrateDataTaskBase
{
    /**
     * {@inheritDoc}
     * @var string
     */
    protected $title = 'Migrate Text Or JSON to Proper Relationship in DB';

    /**
     * {@inheritDoc}
     * @var string
     */
    protected $description = '
        For example, it can convert all the values of all DB columnns used by listbox fields
        from comma separated strings to JSON and then to Many Many';

    protected $enabled = true;


    protected $sanitiseCharList = [
        '"',
        '[',
        ']',
        "'",
    ];

    protected $dbTablesTypes = [
        '',
        '_Live',
        '_Versions',
    ];

    protected $lookupClassNames = [];

    protected $tables = [];

    /**
     * add your data here, like this:
     *     ClassNameA
     *         OldField => NewRelation
     *     ClassNameB
     *         OldField => NewRelation
     * @var array
     */
    private static $data_to_fix = [];

    /**
     * @throws Exception
     */
    public function performMigration()
    {
        $dataToFix = $this->Config()->data_to_fix;
        if(count($dataToFix) === 0) {
            user_error('You need to specify at least some data to fix!');
        }
        for ($i = 1; $i < 3; $i++) {
            $this->flushNow('LOOP LOOP: ' . $i);
            foreach ($dataToFix as $className => $columns) {
                $this->flushNow('... LOOP ClassName: ' . $className);
                foreach ($this->dbTablesTypes as $tableExtension) {
                    $this->flushNow('... ... LOOP Table Extension: ' . $tableExtension);
                    foreach ($columns as $column => $lookupMethod) {
                        $this->flushNow('... ... ... LOOP Field: ' . $column);
                        $this->updateRows($className, $tableExtension, $column, $lookupMethod);
                        $stage = null;
                        switch ($tableExtension) {
                            case '':
                                $stage = Versioned::DRAFT;
                                break;
                            case '_Live':
                                $stage = Versioned::LIVE;
                                break;
                        }
                        if ($stage !== null) {
                            $this->testRelationships($className, $lookupMethod, $stage);
                        }
                    }
                }
            }
        }
    }

    protected function updateRows(string $className, string $tableExtension, string $column, string $lookupMethod): void
    {
        $tableName = $this->getTableName($className, $tableExtension);
        $sql = '
            SELECT "ID", ' . $column . '
            FROM
            ' . $tableName . ';';
        $rows = DB::query($sql);
        foreach ($rows as $row) {
            $id = $row['ID'];
            $this->flushNow(
                '... ... ... ... ' .
                'LOOP Table: ' . $tableName . ' Row ID: ' . $row['ID']
            );
            $fieldValue = $row[$column];
            $fieldValue = $this->updateRow($tableName, $id, $column, $fieldValue);
            $fieldValue = $this->updateEmptyRows($tableName, $id, $column, $fieldValue);
            if ($tableExtension === '') {
                $this->addToRelationship($className, $id, $lookupMethod, $fieldValue);
            }
        }
    }

    protected function updateRow($tableName, $id, $column, $fieldValue): string
    {
        if (strpos($fieldValue, '["') === 0 && strpos($fieldValue, '"]')) {
            $this->flushNow(
                '... ... ... ... ... ' .
                'column ' . $column . ' in table: ' . $tableName . ' with row ID: ' . $id .
                ' already has the correct format, the value is: ' . $fieldValue,
                'created'
            );
        } else {
            //adding empty string ...
            $fieldValue = $this->sanitiseChars($fieldValue . '');
            if ($fieldValue) {
                $fieldValue = json_encode(explode(',', $fieldValue));
                $sql = '
                    UPDATE ' . $tableName . ' SET ' . $column . ' = \'' . $fieldValue . '\'
                    WHERE ' . $tableName . '."ID" = ' . $id . ';';
                $this->flushNow(
                    '... ... ... ... ... ' .
                    'updating value of column ' . $column . ' in table: ' . $tableName .
                    ' with row ID: ' . $id . ' to new value of ' . $fieldValue,
                    'repaired'
                );
                $this->runUpdateQuery($sql);
            } else {
                $this->flushNow(
                    '... ... ... ... ... ' .
                    'column ' . $column . ' in table: ' . $tableName .
                    ' with row ID: ' . $id . ' is empty so doesn\'t need to be updated',
                    'repaired'
                );
            }
        }

        return $fieldValue;
    }

    protected function updateEmptyRows(string $tableName, int $id, string $column, string $fieldValue): string
    {
        $array = @json_decode($fieldValue, false);
        if (empty($array)) {
            $fieldValue = '';
            $sql = '
                UPDATE ' . $tableName . ' SET ' . $column . ' = \'' . $fieldValue . '\'
                WHERE ' . $tableName . '."ID" = ' . $id . ';';
            $this->flushNow(
                '... ... ... ... ... ' .
                'column ' . $column . ' in table: ' . $tableName . ' with row ID: ' . $id .
                ' had an incorrect empty value so has been updated to an empty string',
                'repaired'
            );
            DB::query($sql);
        }

        return $fieldValue;
    }

    protected function addToRelationship(string $className, int $id, string $lookupMethod, string $fieldValue): void
    {
        if ($fieldValue) {
            $array = @json_decode($fieldValue, false);
            if (! empty($array)) {
                $obj = $className::get()->byID($id);
                $lookupClassName = $this->getlookupClassName($className, $lookupMethod);
                $obj->{$lookupMethod}()->removeAll();
                foreach ($array as $value) {
                    $lookupItem = $lookupClassName::find_or_create(['Code' => $value]);
                    $this->flushNow(
                        '... ... ... ... ... ... ' .
                        'adding ' . $value . ' as many-many relation',
                        'created'
                    );
                    $obj->{$lookupMethod}()->add($lookupItem);
                }
            }
        }
    }

    protected function sanitiseChars(string $value): string
    {
        foreach ($this->sanitiseCharList as $char) {
            if (strpos($value, $char) !== false) {
                $this->flushNow(
                    '... ... ... ... ... ... ' .
                    $char . ' was found in ' . $value . ' we are removing it',
                    'error'
                );
            }
            $value = str_replace($char, '', $value);
        }

        return $value;
    }

    protected function getTableName(string $className, string $tableExtension): string
    {
        $key = $className . '_' . $tableExtension;
        if (! isset($this->tables[$key])) {
            $dbtable = Config::inst()->get($className, 'table_name');
            $tableName = $dbtable . $tableExtension;
            $this->tables[$key] = $tableName;
        }

        return $this->tables[$key];
    }

    protected function getLookupClassName(string $className, string $lookupMethod): string
    {
        $key = $className . '_' . $lookupMethod;
        if (! isset($this->lookupClassNames[$key])) {
            $fields = Config::inst()->get($className, 'has_many');
            $fields += Config::inst()->get($className, 'many_many');
            $fields += Config::inst()->get($className, 'belongs_many_many');
            foreach ($fields as $methodsCheck => $lookupClassName) {
                if ($methodsCheck === $lookupMethod) {
                    $this->lookupClassNames[$key] = $lookupClassName;
                }
            }
        }

        return $this->lookupClassNames[$key];
    }

    protected function testRelationships(string $className, string $lookupMethod, $stage)
    {
        $objects = Versioned::get_by_stage($className, $stage);
        foreach ($objects as $obj) {
            $count = $obj->{$lookupMethod}()->count();
            $this->flushNow(
                '... ... ... ' .
                'Testing (' . $stage . ') - ' . $className . '.' . $lookupMethod . ' for ID ' . $obj->ID . ' => ' . $count
            );
        }
    }
}