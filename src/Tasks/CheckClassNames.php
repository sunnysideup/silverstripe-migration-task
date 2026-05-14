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
            $slashed = addslashes($objectClassName);
            $this->listOfAllClasses[$slashed] = ClassInfo::shortName($objectClassName);
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

        $where = '"' . $fieldName . '" NOT IN (\'' . implode("', '", array_keys($this->listOfAllClasses)) . "')";
        $rowsToFix = DB::query('SELECT COUNT("ID") FROM "' . $tableName . '" WHERE ' . $where)->value();

        if ($rowsToFix > 0) {

            // 1. GENERATE THE CLEAN GROUPED OUTPUT
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

                // Guess for reporting purposes
                if ($badName !== '--- NO VALUE ---') {
                    $longNameAlreadySlashed = array_search($badName, $this->listOfAllClasses, true);
                    if ($longNameAlreadySlashed && isset($this->countsOfAllClasses[$badName]) && 1 === $this->countsOfAllClasses[$badName]) {
                        $suggested = $longNameAlreadySlashed;
                    } elseif ($match = $this->findMatchingClassname($badName)) {
                        $suggested = $match;
                    }
                }

                $display = $suggested ?: '[NO MATCH]';
                $this->flushNow("------X {$count} {$badName} => {$display}");

                if (!$suggested && $badName !== '--- NO VALUE ---') {
                    $this->unfindableClassNames[$badName] = true;
                }
            }

            // 2. EXECUTE YOUR ORIGINAL ROBUST FIXING LOGIC IN THE BACKGROUND
            if ($this->forReal) {
                if ($this->extendFieldSize) {
                    $this->fixFieldSize($tableName);
                }

                // Phase A: Bulk mapping for Short to Long ClassNames
                $groupRows = DB::query('SELECT "' . $fieldName . '", COUNT("ID") AS C FROM "' . $tableName . '" GROUP BY "' . $fieldName . '" HAVING ' . $where . ' ORDER BY C DESC');
                foreach ($groupRows as $row) {
                    if ($row[$fieldName] && isset($this->countsOfAllClasses[$row[$fieldName]]) && 1 === $this->countsOfAllClasses[$row[$fieldName]]) {
                        $longNameAlreadySlashed = array_search($row[$fieldName], $this->listOfAllClasses, true);
                        if ($longNameAlreadySlashed) {
                            $this->runUpdateQuery('UPDATE "' . $tableName . '" SET "' . $tableName . '"."' . $fieldName . '" = \'' . $longNameAlreadySlashed . '\' WHERE "' . $fieldName . '" = \'' . $row[$fieldName] . "'", 2);
                        }
                    }
                }

                // Phase B: Row-by-Row Child Table Inference
                if ('ClassName' === $fieldName) {
                    $options = ClassInfo::subclassesFor($objectClassName);
                    $checkTables = [];
                    foreach ($options as $key => $optionClassName) {
                        if ($optionClassName !== $objectClassName) {
                            $optionTableName = $this->dataObjectSchema->tableName($objectClassName);
                            if (!$this->tableExists($optionTableName) || $optionTableName === $tableName) {
                                unset($options[$key]);
                            } else {
                                $checkTables[$optionClassName] = $optionTableName;
                            }
                        }
                    }

                    $rows = DB::query('SELECT "ID", "' . $fieldName . '" FROM "' . $tableName . '" WHERE ' . $where);
                    foreach ($rows as $row) {
                        $optionCount = 0;
                        $matchedClassName = '';
                        foreach ($checkTables as $optionClassName => $optionTableName) {
                            $hasMatch = DB::query('SELECT COUNT("' . $tableName . '"."ID") FROM "' . $tableName . '" INNER JOIN "' . $optionTableName . '" ON "' . $optionTableName . '"."ID" = "' . $tableName . '"."ID" WHERE "' . $tableName . '"."ID" = ' . $row['ID'])->value();
                            if (1 === $hasMatch) {
                                ++$optionCount;
                                $matchedClassName = $optionClassName;
                                if ($optionCount > 1) {
                                    break;
                                }
                            }
                        }

                        if (0 === $optionCount) {
                            $this->runUpdateQuery('UPDATE "' . $tableName . '" SET "' . $tableName . '"."' . $fieldName . '" = \'' . addslashes($objectClassName) . '\' WHERE ID = ' . $row['ID'], 2);
                        } elseif (1 === $optionCount && $matchedClassName) {
                            $this->runUpdateQuery('UPDATE "' . $tableName . '" SET "' . $tableName . '"."' . $fieldName . '" = \'' . addslashes($matchedClassName) . '\' WHERE ID = ' . $row['ID'], 2);
                        } else {
                            $bestValue = $this->bestClassName($objectClassName, $tableName, $fieldName);
                            $this->runUpdateQuery('UPDATE "' . $tableName . '" SET "' . $tableName . '"."' . $fieldName . '" = \'' . addslashes($bestValue) . '\' WHERE ID = ' . $row['ID'], 2);
                        }
                    }
                } else {
                    $this->runUpdateQuery('UPDATE "' . $tableName . '" SET "' . $fieldName . '" = \'\' WHERE ' . $where, 2);
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
