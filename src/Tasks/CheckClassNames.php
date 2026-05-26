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
 * 3. If unresolved, fall back to bestClassName() for the table.
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
            // Store raw FQCN instead of addslashes(), as we now use prepared queries
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
            $this->flushNow('... No table needed');
            return;
        }

        $tableName = $this->dataObjectSchema->tableName($objectClassName);
        $this->flushNow('');
        $this->flushNowLine();
        $this->flushNow('Checking ' . $objectClassName . ' => ' . $tableName);
        $this->flushNowLine();

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
        $this->flushNow('... ' . $count . ' rows');

        foreach ($this->fieldsToCheckFor($objectClassName) as $fieldName) {
            if (!$this->fieldExists($tableName, $fieldName)) {
                $this->flushNow('... Can not find: ' . $tableName . '.' . $fieldName . ' in database.');
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
        $this->flushNow('... CHECKING ' . $tableName . '.' . $fieldName . ' ...');

        $where = $this->buildWhereClause($fieldName);
        $rowsToFix = (int) DB::query('SELECT COUNT("ID") FROM "' . $tableName . '" WHERE ' . $where)->value();

        if ($rowsToFix === 0) {
            $this->flushNow('... no broken values', 'created');
        } else {
            $this->reportErrorCounts($tableName, $fieldName, $where, $rowsToFix);

            if ($this->extendFieldSize) {
                $this->fixFieldSize($tableName);
            }

            if ('ClassName' === $fieldName) {
                $this->bulkFixByDistinctValue($tableName, $objectClassName, $fieldName, $where);
            } else {
                $this->flushNow('... Setting broken "' . $tableName . '"."' . $fieldName . '" to NULL in bulk', 'created');
                $this->applyUpdate('UPDATE "' . $tableName . '" SET "' . $fieldName . '" = NULL WHERE ' . $where);
            }
        }

        // Recurse into versioned variants
        if (false === $versionedTable) {
            foreach (['_Live', '_Versions'] as $extension) {
                $testTable = $tableName . $extension;
                if ($this->tableExists($testTable)) {
                    $this->fixClassNames($testTable, $objectClassName, $fieldName, true);
                } else {
                    $this->flushNow('... ... there is no table called: ' . $testTable);
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

    /**
     * Value-to-Value Bulk Fix
     * Finds every distinct broken value and maps it to a new value via a single UPDATE query.
     */
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

            // 2. Fallback to best overall class for this table
            if (!$resolved) {
                $resolved = $this->bestClassName($objectClassName, $tableName, $fieldName);
                $reason = 'fallback to best class';
            }

            $this->flushNow(
                '... ' . $countForValue . ' row(s): ' . $displayValue . ' → ' . $resolved . ' [' . $reason . ']',
                $reason === 'short-name match' ? 'created' : 'error'
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
        // Safe implosion for keys since they are valid FQCNs
        $known = implode("', '", array_keys($this->listOfAllClasses));
        // We ensure known class strings are escaped purely for the WHERE IN clause
        $known = addslashes($known);

        return '"' . $fieldName . '" NOT IN (\'' . $known . '\')';
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
        $values = $obj->dbObject($fieldName)->enumValues(false);
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
        if (!$best) {
            $best = key($values);
        }
        return $this->bestClassNameStore[$key] = $best;
    }

    // ----------------------------------------------------------------
    //              Suspicious-value sweep over every column
    // ----------------------------------------------------------------

    protected function findSuspiciousClassNames()
    {
        $this->flushNow('');
        $this->flushNowLine();
        $this->flushNow('Scanning all tables for suspicious class-name-looking values');
        $this->flushNowLine();

        $unresolved = [];
        foreach ($this->dbTablesPresent as $tableName) {
            $columns = DB::query('SHOW COLUMNS FROM "' . $tableName . '"');
            foreach ($columns as $col) {
                $fieldName = $col['Field'];

                // Fetch distinct values containing a backslash, processed in PHP for DB compatibility
                $rows = DB::query(
                    'SELECT "' . $fieldName . '" AS row_value
                    FROM "' . $tableName . '"
                    WHERE "' . $fieldName . '" LIKE \'%\\\\%\'
                    GROUP BY "' . $fieldName . '"'
                );

                foreach ($rows as $row) {
                    $value = $row['row_value'] ?? '';

                    // Simple PHP regex to replace the old MySQL REGEXP implementation
                    if ($value === '' || class_exists($value) || !preg_match('/^[A-Z][A-Za-z0-9_]*(\\\\[A-Z][A-Za-z0-9_]*)+$/', $value)) {
                        continue;
                    }

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
                }
            }
        }

        if (count($unresolved) === 0) {
            $this->flushNow('... no unresolved suspicious values', 'created');
            return;
        }

        $this->flushNow('... ' . count($unresolved) . ' suspicious values could not be auto-remapped:', 'error');
        foreach ($unresolved as $u) {
            $this->flushNow('... ... ' . $u['Table'] . '.' . $u['Field'] . ' Value: ' . $u['Value']);
        }
    }

    // ----------------------------------------------------------------
    //                            Write gate
    // ----------------------------------------------------------------

    /**
     * Single chokepoint for every DB write. Uses DB::prepared_query().
     */
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
            // Attempt to expand field safely (still mostly MySQL syntax, but safely wrapped)
            DB::query('ALTER TABLE "' . $tableName . '" MODIFY "ClassName" VARCHAR(255)');
        } catch (\Exception $e) {
            // Fails silently if syntax is unsupported on Postgres/SQLite or column is already sized
        }
    }
}
