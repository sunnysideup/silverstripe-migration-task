<?php

namespace Sunnysideup\MigrateData\Tasks;

use Page;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\CMS\Model\SiteTreeLink;
use SilverStripe\Control\Controller;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectSchema;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;

class CheckClassNames extends MigrateDataTaskBase
{
    protected $title = 'Check all tables for valid class names';

    protected $description = 'Check all tables for valid class names';

    private static $segment = 'check-class-names';

    protected $enabled = true;

    protected $listOfAllClasses = [];

    protected $countsOfAllClasses = [];

    protected $dbTablesPresent = [];

    protected $extendFieldSize = true;

    protected $forReal = false;

    protected $dataObjectSchema;

    protected $onlyRunFor = [];

    protected $bestClassNameStore = [];

    protected $unfindableClassNames = [];

    protected $processedFields = [];

    /**
     * @var array
     */
    private static $other_fields_to_check = [
        '\\DNADesign\\\Elemental\\Models\\ElementalArea' => [
            'OwnerClassName',
        ],
        SiteTreeLink::class => [
            'ParentClass',
        ],
    ];

    protected function performMigration()
    {
        $request = Controller::curr()->getRequest();
        $this->forReal = (bool) $request->getVar('forreal');

        if (!$this->forReal) {
            $this->flushNow('RUNNING IN DRY-RUN MODE (pass ?forreal=1 in the URL to apply fixes)', 'deleted');
        } else {
            $this->flushNow('RUNNING IN FIX MODE (?forreal=1 detected)', 'created');
        }
        $this->flushNowLine();

        $this->dbTablesPresent = [];
        $rows = DB::query('SHOW tables');
        foreach ($rows as $row) {
            $table = array_pop($row);
            $this->dbTablesPresent[$table] = $table;
        }

        $this->dataObjectSchema = Injector::inst()->get(DataObjectSchema::class);

        $objectClassNames = ClassInfo::subclassesFor(DataObject::class, false);
        foreach ($objectClassNames as $objectClassName) {
            // Pure class names to prevent double slashes in output
            $this->listOfAllClasses[$objectClassName] = ClassInfo::shortName($objectClassName);
        }
        $this->countsOfAllClasses = array_count_values($this->listOfAllClasses);

        foreach ($objectClassNames as $objectClassName) {
            if (count($this->onlyRunFor) && !in_array($objectClassName, $this->onlyRunFor, true)) {
                continue;
            }
            $fields = $this->dataObjectSchema->databaseFields($objectClassName, false);
            if (count($fields) > 0) {
                $tableName = $this->dataObjectSchema->tableName($objectClassName);

                if ($this->tableExists($tableName)) {
                    $allFields = ['ClassName'];
                    $moreFields = $this->Config()->other_fields_to_check;
                    if (isset($moreFields[$objectClassName])) {
                        foreach ($moreFields[$objectClassName] as $additionalField) {
                            $allFields[] = $additionalField;
                        }
                    }
                    foreach ($allFields as $fieldName) {
                        if ($this->fieldExists($tableName, $fieldName)) {
                            $this->fixClassNames($tableName, $objectClassName, $fieldName);
                        }
                    }
                }
            }
        }

        $this->findSuspiciousClassNames();

        if (!empty($this->unfindableClassNames)) {
            $this->flushNowLine();
            $this->flushNow('--- UNIQUE UNFINDABLE CLASSNAMES ---', 'error');
            foreach (array_keys($this->unfindableClassNames) as $unfindable) {
                $this->flushNow('- ' . $unfindable);
            }
        }
    }

    protected function fixClassNames(string $tableName, string $objectClassName, ?string $fieldName = 'ClassName', ?bool $versionedTable = false)
    {
        $this->processedFields[$tableName . '.' . $fieldName] = true;

        $slashedClasses = array_map('addslashes', array_keys($this->listOfAllClasses));
        $where = '"' . $fieldName . '" NOT IN (\'' . implode("', '", $slashedClasses) . "')";

        $rowsToFix = DB::query('SELECT COUNT("ID") FROM "' . $tableName . '" WHERE ' . $where)->value();

        if ($rowsToFix > 0) {

            if ($this->forReal && $this->extendFieldSize) {
                $this->fixFieldSize($tableName);
            }

            // Get grouped counts of bad values
            $sql = "SELECT \"{$fieldName}\" AS BadName, COUNT(\"ID\") AS C
                    FROM \"{$tableName}\"
                    WHERE {$where}
                    GROUP BY \"{$fieldName}\"
                    ORDER BY C DESC";
            $rows = DB::query($sql);

            $this->flushNow($tableName);
            $this->flushNow('---' . $fieldName);

            foreach ($rows as $row) {
                $badName = $row['BadName'] ?: '--- NO VALUE ---';
                $count = $row['C'];
                $suggested = null;
                $mappedByString = false;

                if ($badName !== '--- NO VALUE ---') {
                    $longName = array_search($badName, $this->listOfAllClasses, true);
                    if ($longName && isset($this->countsOfAllClasses[$badName]) && 1 === $this->countsOfAllClasses[$badName]) {
                        $suggested = $longName;
                        $mappedByString = true;
                    } elseif ($match = $this->findMatchingClassname($badName)) {
                        $suggested = $match;
                        $mappedByString = true;
                    }
                }

                // UI Display
                $display = $suggested ?: '[NO MATCH]';
                $this->flushNow("------X {$count} {$badName} => {$display}");

                if (!$suggested && $badName !== '--- NO VALUE ---') {
                    $this->unfindableClassNames[$badName] = true;
                }

                // Bulk Updates
                if ($this->forReal) {
                    if ($badName === '--- NO VALUE ---') {
                        // Fix NULLs / empty strings
                        $bestValue = ('ClassName' === $fieldName) ? $this->bestClassName($objectClassName, $tableName, $fieldName) : '';
                        $this->runUpdateQuery('UPDATE "' . $tableName . '" SET "' . $fieldName . '" = \'' . addslashes($bestValue) . '\' WHERE "' . $fieldName . '" IS NULL OR "' . $fieldName . '" = \'\'', 2);
                    } else {
                        if ($mappedByString && $suggested) {
                            // 1. We know the match via string. Bulk update!
                            $this->runUpdateQuery('UPDATE "' . $tableName . '" SET "' . $fieldName . '" = \'' . addslashes($suggested) . '\' WHERE "' . $fieldName . '" = \'' . addslashes($badName) . '\'', 2);
                        } elseif ('ClassName' === $fieldName) {
                            // 2. Unfindable by string. Let's use bulk child-table IN() checks!
                            $options = ClassInfo::subclassesFor($objectClassName);
                            foreach ($options as $optionClassName) {
                                if ($optionClassName === $objectClassName) {
                                    continue;
                                }
                                $optionTableName = $this->dataObjectSchema->tableName($optionClassName);
                                if ($this->tableExists($optionTableName) && $optionTableName !== $tableName) {
                                    // Set the ClassName only for rows that ALSO exist in the specific child table
                                    $this->runUpdateQuery(
                                        'UPDATE "' . $tableName . '" SET "' . $fieldName . '" = \'' . addslashes($optionClassName) . '\' WHERE "' . $fieldName . '" = \'' . addslashes($badName) . '\' AND "ID" IN (SELECT "ID" FROM "' . $optionTableName . '")',
                                        2
                                    );
                                }
                            }
                            // 3. Fallback for any rows holding this badName that didn't exist in any child tables
                            $bestValue = $this->bestClassName($objectClassName, $tableName, $fieldName);
                            $this->runUpdateQuery('UPDATE "' . $tableName . '" SET "' . $fieldName . '" = \'' . addslashes($bestValue) . '\' WHERE "' . $fieldName . '" = \'' . addslashes($badName) . '\'', 2);
                        } else {
                            // Not a classname field, clear the bad value
                            $this->runUpdateQuery('UPDATE "' . $tableName . '" SET "' . $fieldName . '" = \'\' WHERE "' . $fieldName . '" = \'' . addslashes($badName) . '\'', 2);
                        }
                    }
                }
            }
        }

        if (false === $versionedTable) {
            foreach (['_Live', '_Versions'] as $extension) {
                $testTable = $tableName . $extension;
                if ($this->tableExists($testTable)) {
                    $this->fixClassNames($testTable, $objectClassName, $fieldName, true);
                }
            }
        }
    }

    protected function findSuspiciousClassNames()
    {
        foreach ($this->dbTablesPresent as $tableName) {
            $fields = DB::query('SHOW COLUMNS FROM "' . $tableName . '"');
            $tablePrinted = false;

            foreach ($fields as $fieldDetails) {
                $fieldName = $fieldDetails['Field'];

                if (isset($this->processedFields[$tableName . '.' . $fieldName])) {
                    continue;
                }

                $sql = 'SELECT "' . $fieldName . '" as BadName, COUNT(ID) as C FROM "' . $tableName . '" WHERE "' . $fieldName . '" REGEXP \'^[A-Z][A-Za-z0-9_]*(\\\\\\\\[A-Z][A-Za-z0-9_]*)+$\' GROUP BY "' . $fieldName . '"';
                $rows = DB::query($sql);
                $fieldPrinted = false;

                foreach ($rows as $row) {
                    $badName = $row['BadName'];

                    if ($badName && !class_exists($badName)) {
                        $count = $row['C'];
                        $suggested = $this->findMatchingClassname($badName);

                        if (!$tablePrinted) {
                            $this->flushNow($tableName);
                            $tablePrinted = true;
                        }
                        if (!$fieldPrinted) {
                            $this->flushNow('---' . $fieldName);
                            $fieldPrinted = true;
                        }

                        $display = $suggested ?: '[NO MATCH]';
                        $this->flushNow("------X {$count} {$badName} => {$display}");

                        if (!$suggested) {
                            $this->unfindableClassNames[$badName] = true;
                        }

                        if ($this->forReal && $suggested) {
                            // Already bulk updates!
                            DB::query('UPDATE "' . $tableName . '" SET "' . $fieldName . '" = \'' . addslashes($suggested) . '\' WHERE "' . $fieldName . '" = \'' . addslashes($badName) . '\'');
                        }
                    }
                }
            }
        }
    }

    protected function fixFieldSize($tableName)
    {
        $databaseName = DB::get_conn()->getSelectedDatabase();
        DB::query('ALTER TABLE "' . $databaseName . '"."' . $tableName . '" CHANGE ClassName ClassName VARCHAR(255);');
    }

    protected function bestClassName(string $objectClassName, string $tableName, string $fieldName): string
    {
        $keyForStore = $objectClassName . '_' . $tableName . '_' . $fieldName;
        if (!isset($this->bestClassNameStore[$keyForStore])) {
            $obj = Injector::inst()->get($objectClassName);
            if ($obj instanceof SiteTree && class_exists(Page::class)) {
                $this->bestClassNameStore[$keyForStore] = 'Page';
                return $this->bestClassNameStore[$keyForStore];
            }
            $values = $obj->dbObject($fieldName)->enumValues(false);
            $sql = 'SELECT "' . $fieldName . '", COUNT(*) AS magnitude FROM "' . $tableName . '" GROUP BY "' . $fieldName . '" ORDER BY magnitude DESC LIMIT 1';

            $bestValue = '';
            $rowsForBestValue = DB::query($sql);
            foreach ($rowsForBestValue as $rowForBestValue) {
                if (in_array($rowForBestValue[$fieldName], $values, true)) {
                    $bestValue = $rowForBestValue[$fieldName];
                    break;
                }
            }
            if (!$bestValue && !empty($values)) {
                $bestValue = key($values);
            }
            $this->bestClassNameStore[$keyForStore] = $bestValue ?: $objectClassName;
        }

        return $this->bestClassNameStore[$keyForStore];
    }

    protected function findMatchingClassname(string $className): ?string
    {
        $shortName = substr(strrchr($className, '\\') ?: $className, 1);
        $matches = [];

        foreach ($this->listOfAllClasses as $fqcn => $fqcnShort) {
            if ($shortName === $fqcnShort) {
                $matches[] = $fqcn;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }
}
