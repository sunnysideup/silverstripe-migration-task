<?php

namespace Sunnysideup\MigrateData\Tasks;

use Page;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\CMS\Model\SiteTreeLink;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectSchema;
use SilverStripe\ORM\DB;

/**
 * Scan every DataObject table for invalid ClassName (and similar) values and
 * repair them in bulk.
 *
 * Resolution strategy (Value-Based Bulk Update):
 * 1. Find all distinct invalid values in a column.
 * 2. For each invalid value, attempt a short-name or table-name match.
 * 3. If unresolved, fall back to bestClassName() for the table (ClassName only).
 * 4. Issue a single parameterized UPDATE query replacing the old value with the new one.
 *
 * Run modes:
 * - $dryRun = true  : compute and log every proposed fix, do not touch the DB.
 * - $dryRun = false : compute, log, and execute.
 */
class CheckClassNames extends MigrateDataTaskBase
{
    protected $title = 'Check all tables for valid class names (Bulk Update)';

    protected $description = 'Check all tables for valid class names and resolve errors via bulk value-to-value updates.';

    private static $segment = 'check-class-names';

    protected $enabled = true;

    protected $dryRun = false;

    protected $extendFieldSize = true;

    protected $onlyRunFor = [];

    protected $listOfAllClasses = [];

    protected $countsOfAllClasses = [];

    protected $dbTablesPresent = [];

    protected $dataObjectSchema;

    protected $bestClassNameStore = [];

    protected $tableNameToClassMap;

    protected $verbose = 'v'; // 'v' = basic logging, 'vv' = log every proposed fix, 'vvv' = log even more

    private static $other_fields_to_check = [
        'DNADesign\\Elemental\\Models\\ElementalArea' => [
            'OwnerClassName',
        ],
        SiteTreeLink::class => [
            'ParentClass',
        ],
    ];

    protected function performMigration()
    {
        $this->announceRunMode();
        $this->loadKnownClasses();
        $this->loadDbTables();

        $this->dataObjectSchema = Injector::inst()->get(DataObjectSchema::class);

        $objectClassNames = array_keys($this->listOfAllClasses);
        foreach ($objectClassNames as $objectClassName) {
            if (count($this->onlyRunFor) && !in_array($objectClassName, $this->onlyRunFor, true)) {
                continue;
            }
            $this->processClass($objectClassName);
        }
        $this->findSuspiciousClassNames();
    }

    // ----------------------------------------------------------------
    //                         Setup helpers
    // ----------------------------------------------------------------

    protected function announceRunMode()
    {
        $this->flushNowLine();
        if ($this->dryRun) {
            $this->flushNow('DRY RUN — nothing will be written to the database.');
        } else {
            $this->flushNow('REAL RUN — changes will be applied to the database.');
        }
        $this->flushNowLine();
    }

    protected function loadKnownClasses()
    {
        $this->listOfAllClasses = [];
        foreach (ClassInfo::subclassesFor(DataObject::class, false) as $className) {
            $this->listOfAllClasses[$className] = ClassInfo::shortName($className);
        }
        $this->countsOfAllClasses = array_count_values($this->listOfAllClasses);
        $this->tableNameToClassMap = null;
    }

    protected function loadDbTables()
    {
        $this->dbTablesPresent = [];
        foreach (DB::query('SHOW TABLES') as $row) {
            $table = array_pop($row);
            $this->dbTablesPresent[$table] = $table;
        }
    }

    // ----------------------------------------------------------------
    //                       Per-class orchestration
    // ----------------------------------------------------------------

    protected function processClass(string $objectClassName)
    {
        $fields = $this->dataObjectSchema->databaseFields($objectClassName, false);
        if (count($fields) === 0) {
            if ($this->verbose === 'vvv') {
                $this->flushNow('... ' . $objectClassName . ' has no database fields, skipping.');
            }
            return;
        }

        $tableName = $this->dataObjectSchema->tableName($objectClassName);
        $this->flushNow('');
        $this->flushNowLine();
        $this->flushNow('Checking ' . $objectClassName . ' => ' . $tableName);

        $declared = Config::inst()->get($objectClassName, 'table_name');
        if ($declared !== $tableName && 'Page' !== $objectClassName) {
            $this->flushNow(
                '... ' . $objectClassName . ' POTENTIALLY has a table with a full class name: '
                . $tableName . ' — recommend setting private static $table_name explicitly',
                'error'
            );
        }

        if (!$tableName) {
            $this->flushNow('... Can not find: ' . $objectClassName . '.table_name in code ', 'error');
            return;
        }
        if (!$this->tableExists($tableName)) {
            $this->flushNow('... Can not find: ' . $tableName . ' in database.', 'error');
            return;
        }

        $count = DB::query('SELECT COUNT("ID") FROM "' . $tableName . '"')->value();
        if ($count === 0) {
            if ($this->verbose === 'vvv') {
                $this->flushNow('... Table exists but has no records.');
            }
            return;
        }
        if ($this->verbose === 'vvv') {
            $this->flushNow('... ' . $count . ' rows');
        }

        foreach ($this->fieldsToCheckFor($objectClassName) as $fieldName) {
            if (!$this->fieldExists($tableName, $fieldName)) {
                if ($this->verbose === 'vvv') {
                    $this->flushNow('... Can not find: ' . $tableName . '.' . $fieldName . ' in database.', 'error');
                }
                continue;
            }
            $this->fixClassNames($tableName, $objectClassName, $fieldName);
        }
    }

    protected function fieldsToCheckFor(string $objectClassName): array
    {
        $fields = ['ClassName'];
        $extra = $this->Config()->other_fields_to_check;
        if (isset($extra[$objectClassName])) {
            foreach ($extra[$objectClassName] as $f) {
                $fields[] = $f;
            }
        }
        return array_unique($fields);
    }

    // ----------------------------------------------------------------
    //                           Fixing logic
    // ----------------------------------------------------------------

    protected function fixClassNames(
        string $tableName,
        string $objectClassName,
        ?string $fieldName = 'ClassName',
        ?bool $versionedTable = false
    ) {
        $this->flushNow('Checking ' . $objectClassName . ' => ' . $tableName . '.' . $fieldName);

        $where = $this->buildWhereClause($fieldName);
        $rowsToFix = (int) DB::query('SELECT COUNT("ID") FROM "' . $tableName . '" WHERE ' . $where)->value();

        if ($rowsToFix === 0) {
            if ($this->verbose === 'vvv') {
                $this->flushNow('... no broken values');
            }
        } else {
            $this->reportErrorCounts($tableName, $fieldName, $where, $rowsToFix);

            if ($this->extendFieldSize && $fieldName === 'ClassName') {
                $this->fixFieldSize($tableName);
            }

            // Route ALL fields through the resolution logic now, not just ClassName
            $this->bulkFixByDistinctValue($tableName, $objectClassName, $fieldName, $where);
        }

        // Recurse into versioned variants
        if (false === $versionedTable) {
            foreach (['_Live', '_Versions'] as $extension) {
                $testTable = $tableName . $extension;
                if ($this->tableExists($testTable)) {
                    $this->fixClassNames($testTable, $objectClassName, $fieldName, true);
                } else {
                    if ($this->verbose === 'vvv') {
                        $this->flushNow('... No versioned table found for ' . $tableName . ' (' . $testTable . ')');
                    }
                }
            }
        }
    }

    protected function reportErrorCounts(string $tableName, string $fieldName, string $where, int $rowsToFix)
    {
        $totalCount = (int) DB::query('SELECT COUNT("ID") FROM "' . $tableName . '"')->value();
        if ($totalCount === $rowsToFix) {
            $this->flushNow('... All ' . $totalCount . ' rows in ' . $tableName . ' are broken', 'error');
            return;
        }
        $whereNull = $where . ' AND ("' . $fieldName . '" IS NULL OR "' . $fieldName . '" = \'\')';
        $whereBad = $where . ' AND NOT ("' . $fieldName . '" IS NULL OR "' . $fieldName . '" = \'\')';

        $nullCount = (int) DB::query('SELECT COUNT("ID") FROM "' . $tableName . '" WHERE ' . $whereNull)->value();
        $badCount = (int) DB::query('SELECT COUNT("ID") FROM "' . $tableName . '" WHERE ' . $whereBad)->value();

        $this->flushNow('... ' . $rowsToFix . ' errors in "' . $fieldName . '":');
        if ($nullCount) {
            $this->flushNow('... ... ' . $nullCount . ' rows have no ' . $fieldName . ' at all', 'error');
        }
        if ($badCount) {
            $this->flushNow('... ... ' . $badCount . ' rows have a bad ' . $fieldName);
        }
    }

    protected function bulkFixByDistinctValue(string $tableName, string $objectClassName, string $fieldName, string $where)
    {
        $rows = DB::query(
            'SELECT "' . $fieldName . '" AS bad_value, COUNT("ID") AS c
            FROM "' . $tableName . '"
            WHERE ' . $where . '
            GROUP BY "' . $fieldName . '"
            ORDER BY c DESC'
        );

        foreach ($rows as $row) {
            $originalValue = $row['bad_value'];
            $countForValue = (int) $row['c'];
            $isEmpty = ($originalValue === null || $originalValue === '');
            $displayValue = !$isEmpty ? $originalValue : '<empty/null>';

            // 1. Try resolving via short name match
            $resolved = !$isEmpty ? $this->findMatchingClassname($originalValue) : null;
            $reason = 'short-name match';

            // 2. Fallbacks
            if (!$resolved) {
                if ($fieldName === 'ClassName') {
                    // Only guess the "best" class for actual ClassName columns
                    $resolved = $this->bestClassName($objectClassName, $tableName, $fieldName);
                    $reason = 'fallback to best class';
                } else {
                    // For polymorphic relation fields, guessing is dangerous. Safest to wipe it.
                    $resolved = null;
                    $reason = 'unresolvable relation class (set to NULL)';
                }
            }

            $this->flushNow(
                '... ' . $countForValue . ' row(s): ' . $displayValue . ' → ' . ($resolved ?? 'NULL') . ' [' . $reason . ']',
                $resolved ? 'created' : 'deleted'
            );

            if ($isEmpty) {
                $this->applyUpdate(
                    'UPDATE "' . $tableName . '" SET "' . $fieldName . '" = ? WHERE "' . $fieldName . '" IS NULL OR "' . $fieldName . '" = \'\'',
                    [$resolved]
                );
            } else {
                $this->applyUpdate(
                    'UPDATE "' . $tableName . '" SET "' . $fieldName . '" = ? WHERE "' . $fieldName . '" = ?',
                    [$resolved, $originalValue]
                );
            }
        }
    }

    // ----------------------------------------------------------------
    //                       Lookup / resolution
    // ----------------------------------------------------------------

    protected function findMatchingClassname(string $className): ?string
    {
        if ($className === '') {
            return null;
        }
        $shortName = $this->getShortClassName($className);
        if ($shortName === '') {
            return null;
        }

        // (a) match by short class name
        $byShort = [];
        foreach ($this->listOfAllClasses as $fqcn => $fqcnShort) {
            if ($shortName === $fqcnShort) {
                $byShort[$fqcn] = $fqcn;
            }
        }
        if (count($byShort) === 1) {
            return array_values($byShort)[0];
        }
        if (count($byShort) > 1) {
            return null; // ambiguous — bail
        }

        // (b) match by table name
        $tableMap = $this->getTableNameToClassMap();
        if (isset($tableMap[$shortName]) && count($tableMap[$shortName]) === 1) {
            return $tableMap[$shortName][0];
        }

        $trailing = [];
        $suffix = '_' . $shortName;
        $suffixLen = strlen($suffix);
        foreach ($tableMap as $tableName => $fqcnList) {
            if (strlen($tableName) > $suffixLen && substr($tableName, -$suffixLen) === $suffix) {
                foreach ($fqcnList as $fqcn) {
                    $trailing[$fqcn] = $fqcn;
                }
            }
        }

        return count($trailing) === 1 ? array_values($trailing)[0] : null;
    }

    protected function getShortClassName(string $className): string
    {
        $pos = strrpos($className, '\\');
        return false === $pos ? $className : substr($className, $pos + 1);
    }

    protected function getTableNameToClassMap(): array
    {
        if (null !== $this->tableNameToClassMap) {
            return $this->tableNameToClassMap;
        }
        $map = [];
        foreach ($this->listOfAllClasses as $fqcn => $fqcnShort) {
            if (!class_exists($fqcn)) {
                continue;
            }
            try {
                $tableName = $this->dataObjectSchema->tableName($fqcn);
            } catch (\Throwable $e) {
                continue;
            }
            if ($tableName) {
                $map[$tableName][] = $fqcn;
            }
        }
        return $this->tableNameToClassMap = $map;
    }

    protected function buildWhereClause(string $fieldName): string
    {
        $escapedClasses = array_map('addslashes', array_keys($this->listOfAllClasses));
        return '"' . $fieldName . '" NOT IN (\'' . implode("', '", $escapedClasses) . '\')';
    }

    protected function bestClassName(string $objectClassName, string $tableName, string $fieldName): string
    {
        $key = $objectClassName . '_' . $tableName . '_' . $fieldName;
        if (isset($this->bestClassNameStore[$key])) {
            return $this->bestClassNameStore[$key];
        }

        $obj = Injector::inst()->get($objectClassName);
        if ($obj instanceof SiteTree && class_exists(Page::class)) {
            return $this->bestClassNameStore[$key] = 'Page';
        }

        // Safety check in case bestClassName is accidentally called on a non-enum field
        $dbField = $obj->dbObject($fieldName);
        $values = ($dbField && method_exists($dbField, 'enumValues')) ? $dbField->enumValues(false) : [];

        $best = '';
        $rowsForBest = DB::query(
            'SELECT "' . $fieldName . '", COUNT(*) AS magnitude
            FROM "' . $tableName . '"
            GROUP BY "' . $fieldName . '"
            ORDER BY magnitude DESC
            LIMIT 1'
        );

        foreach ($rowsForBest as $r) {
            if (in_array($r[$fieldName], $values, true)) {
                $best = $r[$fieldName];
                break;
            }
        }

        if (!$best && !empty($values)) {
            $best = key($values);
        }

        return $this->bestClassNameStore[$key] = $best ?: $objectClassName;
    }

    // ----------------------------------------------------------------
    //              Suspicious-value sweep over every column
    // ----------------------------------------------------------------

    // ----------------------------------------------------------------
    //              Suspicious-value sweep over every column
    // ----------------------------------------------------------------

    protected function findSuspiciousClassNames()
    {
        $this->flushNow('');
        $this->flushNowLine();
        $this->flushNow('Scanning all tables for suspicious class-name-esque values');
        $this->flushNowLine();

        $unresolved = [];
        $manualPotentials = []; // Track the broad matches here

        foreach ($this->dbTablesPresent as $tableName) {
            $columns = DB::query('SHOW COLUMNS FROM "' . $tableName . '"');

            foreach ($columns as $col) {
                $fieldName = $col['Field'];

                // Fetch distinct values containing a backslash
                $rows = DB::query(
                    'SELECT "' . $fieldName . '" AS row_value
                    FROM "' . $tableName . '"
                    WHERE "' . $fieldName . '" LIKE \'%\\\\%\'
                    GROUP BY "' . $fieldName . '"'
                );

                foreach ($rows as $row) {
                    $value = $row['row_value'] ?? '';

                    if ($value === '' || class_exists($value)) {
                        continue;
                    }

                    // 1. STRICT MATCH (Original behavior)
                    $isStrictMatch = preg_match('/^[A-Z][A-Za-z0-9_]*(\\\\[A-Z][A-Za-z0-9_]*)+$/', $value);

                    if ($isStrictMatch) {
                        $better = $this->findMatchingClassname($value);
                        if ($better) {
                            $this->flushNow(
                                '... ' . $tableName . '.' . $fieldName . ': ' . $value . ' → ' . $better . ' (Bulk updated)',
                                'created'
                            );
                            $this->applyUpdate(
                                'UPDATE "' . $tableName . '" SET "' . $fieldName . '" = ? WHERE "' . $fieldName . '" = ?',
                                [$better, $value]
                            );
                        } else {
                            $unresolved[] = [
                                'Table' => $tableName,
                                'Field' => $fieldName,
                                'Value' => $value,
                            ];
                        }
                    } else {
                        // 2. BROAD MATCH (Potentials for manual inclusion)
                        // Fails strict casing, but has no spaces/dashes and has an internal backslash.
                        $isBroadMatch = preg_match('/^[^\s\\\\\-]+(\\\\[^\s\\\\\-]+)+$/', $value);
                        if ($isBroadMatch) {
                            $manualPotentials[] = [
                                'Table' => $tableName,
                                'Field' => $fieldName,
                                'Value' => $value,
                            ];
                        }
                    }
                }
            }
        }

        // --- Output Results ---

        if (count($unresolved) > 0) {
            $this->flushNow('... ' . count($unresolved) . ' strict suspicious values could not be auto-remapped:', 'error');
            foreach ($unresolved as $u) {
                $this->flushNow('... ... ' . $u['Table'] . '.' . $u['Field'] . ' Value: ' . $u['Value']);
            }
        } else {
            $this->flushNow('... no unresolved strict suspicious values', 'created');
        }

        if (count($manualPotentials) > 0) {
            $this->flushNow('');
            $this->flushNow('... ' . count($manualPotentials) . ' potential values for manual inclusion (did not match strict casing):', 'notice');
            foreach ($manualPotentials as $m) {
                $this->flushNow('... ... [MANUAL CHECK] ' . $m['Table'] . '.' . $m['Field'] . ' Value: ' . $m['Value']);
            }
        }
    }

    // ----------------------------------------------------------------
    //                            Write gate
    // ----------------------------------------------------------------

    protected function applyUpdate(string $sql, array $params = [])
    {
        if ($this->dryRun) {
            return;
        }

        if (empty($params)) {
            DB::query($sql);
        } else {
            DB::prepared_query($sql, $params);
        }
    }

    protected function fixFieldSize(string $tableName)
    {
        if ($this->dryRun) {
            return;
        }

        try {
            DB::query('ALTER TABLE "' . $tableName . '" MODIFY "ClassName" VARCHAR(255)');
        } catch (\Exception $e) {
            // Silently skip
        }
    }
}
