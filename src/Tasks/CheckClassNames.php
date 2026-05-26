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
 * repair them.
 *
 * Resolution strategies, in order of confidence:
 *   1. Bulk fix by distinct broken value: if the short class name of the broken
 *      value uniquely identifies a known class — either by short-name match or
 *      by table-name match — every row with that value is updated in one
 *      statement.
 *   2. Per-row fix for whatever bulk could not resolve: try the same short-name
 *      lookup again, then a child-table existence check (only meaningful where
 *      the join on ID makes sense — i.e. NOT on _Versions tables), then fall
 *      back to bestClassName() (e.g. SiteTree → 'Page').
 *
 * Run modes:
 *   - $dryRun = true  : compute and log every proposed fix, do not touch the DB.
 *   - $dryRun = false : compute, log, and execute. (default — matches the
 *                       previous behaviour of $forReal = true & $fixErrors = true.)
 */
class CheckClassNames extends MigrateDataTaskBase
{
    protected $title = 'Check all tables for valid class names';

    protected $description = 'Check all tables for valid class names';

    private static $segment = 'check-class-names';

    protected $enabled = true;

    /**
     * When true, log every proposed change but do not write to the database.
     * When false, write changes for real.
     */
    protected $dryRun = true;

    /**
     * Extend ClassName columns to VARCHAR(255) before writing fixes.
     * Long FQCNs don't fit in some older schemas. Subject to $dryRun.
     */
    protected $extendFieldSize = true;

    /** Restrict the check to these classes (empty = all DataObject subclasses). */
    protected $onlyRunFor = [];

    /** Populated in performMigration(). Keys are addslashes()'d FQCNs. */
    protected $listOfAllClasses = [];

    /** Short-name → frequency map, derived from $listOfAllClasses. */
    protected $countsOfAllClasses = [];

    /** Set of every table currently in the database. */
    protected $dbTablesPresent = [];

    /** Cached service handle. */
    protected $dataObjectSchema;

    /** Memoised bestClassName() results, keyed by class+table+field. */
    protected $bestClassNameStore = [];

    /** Lazy table_name → [slashed FQCN, …] reverse lookup. */
    protected $tableNameToClassMap;

    /**
     * Extra non-ClassName fields that should also be checked, per owner class.
     *
     *   [ OwnerClass::class => ['SomeClassFieldA', 'SomeClassFieldB'] ]
     */
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

        $objectClassNames = array_keys($this->listOfAllClasses); // slashed
        foreach ($objectClassNames as $slashed) {
            $objectClassName = stripslashes($slashed);
            if (count($this->onlyRunFor) && ! in_array($objectClassName, $this->onlyRunFor, true)) {
                continue;
            }
            $this->processClass($objectClassName);
        }
        $this->findSuspiciousClassNames();
    }

    // ----------------------------------------------------------------
    //                          Setup helpers
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
            $this->listOfAllClasses[addslashes($className)] = ClassInfo::shortName($className);
        }
        $this->countsOfAllClasses = array_count_values($this->listOfAllClasses);
        $this->tableNameToClassMap = null; // invalidate cache
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

        if (! $tableName) {
            $this->flushNow('... Can not find: ' . $objectClassName . '.table_name in code ', 'error');
            return;
        }
        if (! $this->tableExists($tableName)) {
            $this->flushNow('... Can not find: ' . $tableName . ' in database.', 'error');
            return;
        }

        $count = DB::query('SELECT COUNT("ID") FROM "' . $tableName . '"')->value();
        $this->flushNow('... ' . $count . ' rows');

        foreach ($this->fieldsToCheckFor($objectClassName) as $fieldName) {
            if (! $this->fieldExists($tableName, $fieldName)) {
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
        return $fields;
    }

    // ----------------------------------------------------------------
    //                          Fixing logic
    // ----------------------------------------------------------------

    /**
     * Top-level fixer for one (table, field) pair. Two passes, then recursion
     * into _Live / _Versions variants.
     */
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

            // ===== Pass 1: bulk fix by distinct broken value =====
            $this->bulkFixByDistinctValue($tableName, $fieldName, $where);

            // ===== Pass 2: per-row (ClassName only) =====
            if ('ClassName' === $fieldName) {
                $remaining = (int) DB::query('SELECT COUNT("ID") FROM "' . $tableName . '" WHERE ' . $where)->value();
                if ($remaining > 0) {
                    $this->flushNow('... ' . $remaining . ' row(s) still to resolve individually:');
                    $this->perRowFixClassName($tableName, $objectClassName, $fieldName, $where);
                }
            } else {
                $this->flushNow('... Setting "' . $tableName . '"."' . $fieldName . '" TO empty WHERE ' . $where, 'created');
                $this->applyUpdate('UPDATE "' . $tableName . '" SET "' . $fieldName . '" = \'\' WHERE ' . $where);
            }
        }

        // recurse into versioned variants
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
     * Pass 1: for each distinct broken value, try the short-name/table-name
     * lookup. If it returns a unique known class, bulk-update every row with
     * that value in a single UPDATE.
     */
    protected function bulkFixByDistinctValue(string $tableName, string $fieldName, string $where)
    {
        $rows = DB::query(
            'SELECT "' . $fieldName . '" AS bad_value, COUNT("ID") AS c
            FROM "' . $tableName . '"
            GROUP BY "' . $fieldName . '"
            HAVING ' . $where . '
            ORDER BY c DESC'
        );
        foreach ($rows as $row) {
            $originalValue = $row['bad_value'] ?? '';
            $countForValue = (int) $row['c'];
            $displayValue = $originalValue !== '' ? $originalValue : '<empty/null>';
            $resolved = $originalValue !== '' ? $this->findMatchingClassname($originalValue) : null;

            if ($resolved) {
                $this->flushNow(
                    '... ' . $countForValue . ' row(s): ' . $displayValue . ' → ' . $resolved
                    . ' [bulk fix — short-name match against known class or table]',
                    'created'
                );
                $this->applyUpdate(
                    'UPDATE "' . $tableName . '"
                    SET "' . $tableName . '"."' . $fieldName . '" = \'' . $resolved . '\'
                    WHERE "' . $fieldName . '" = \'' . addslashes($originalValue) . "'"
                );
            } else {
                $this->flushNow(
                    '... ' . $countForValue . ' row(s): ' . $displayValue
                    . ' → no short-name resolution; will try per-row'
                );
            }
        }
    }

    /**
     * Pass 2: per-row resolution for the ClassName field. Each row gets one of:
     *   (1) short-name match (safety net — same as Pass 1, in case Pass 1
     *       didn't fire for some reason);
     *   (2) row exists in exactly one child-class table;
     *   (3) bestClassName() fallback.
     *
     * Results are grouped by (oldValue, newValue, reason) and applied as one
     * UPDATE per group, so output is one line per group instead of per row.
     */
    protected function perRowFixClassName(string $tableName, string $objectClassName, string $fieldName, string $where)
    {
        $childTables = $this->isVersionsTable($tableName)
            ? []
            : $this->buildChildTableMap($tableName, $objectClassName);

        $rows = DB::query('SELECT "ID", "' . $fieldName . '" FROM "' . $tableName . '" WHERE ' . $where);

        $actions = []; // key = old||new||reason
        foreach ($rows as $row) {
            $originalValue = $row[$fieldName] ?? '';
            $id = (int) $row['ID'];

            [$newClassSlashed, $reason] = $this->resolveOneRow($id, $originalValue, $childTables, $objectClassName, $tableName, $fieldName);

            $key = $originalValue . '||' . $newClassSlashed . '||' . $reason;
            if (! isset($actions[$key])) {
                $actions[$key] = ['ids' => [], 'old' => $originalValue, 'new' => $newClassSlashed, 'reason' => $reason];
            }
            $actions[$key]['ids'][] = $id;
        }

        foreach ($actions as $action) {
            $this->reportAndApplyGroup($tableName, $fieldName, $action);
        }
    }

    /**
     * Decide the new ClassName for one row. Returns [slashedFqcn, reason].
     */
    protected function resolveOneRow(
        int $id,
        string $originalValue,
        array $childTables,
        string $objectClassName,
        string $tableName,
        string $fieldName
    ): array {
        // (1) short-name safety net
        if ($originalValue !== '') {
            $match = $this->findMatchingClassname($originalValue);
            if ($match) {
                return [$match, 'short-name match (safety net)'];
            }
        }

        // (2) child-table existence check
        if (! empty($childTables)) {
            $matched = [];
            foreach ($childTables as $optionClassName => $optionTableName) {
                $hasMatch = (int) DB::query(
                    'SELECT COUNT(*) FROM "' . $optionTableName . '" WHERE "ID" = ' . $id
                )->value();
                if ($hasMatch > 0) {
                    $matched[] = $optionClassName;
                    if (count($matched) > 1) {
                        break;
                    }
                }
            }
            if (count($matched) === 1) {
                return [addslashes($matched[0]), 'row exists in exactly one child table (' . $matched[0] . ')'];
            }
        }

        // (3) fallback
        $best = $this->bestClassName($objectClassName, $tableName, $fieldName);
        return [addslashes($best), 'fallback: best guess (' . $best . ') — no other heuristic resolved this row'];
    }

    protected function reportAndApplyGroup(string $tableName, string $fieldName, array $action)
    {
        $rowCount = count($action['ids']);
        $oldDisplay = $action['old'] !== '' ? $action['old'] : '<empty/null>';
        $isFallback = strpos($action['reason'], 'fallback') === 0;
        $level = $isFallback ? 'error' : 'created';

        $sampleIds = array_slice($action['ids'], 0, 5);
        $idsDisplay = implode(', ', $sampleIds);
        if ($rowCount > count($sampleIds)) {
            $idsDisplay .= ', … (' . $rowCount . ' total)';
        }

        $this->flushNow(
            '... ' . $rowCount . ' row(s): ' . $oldDisplay . ' → ' . $action['new']
            . ' [' . $action['reason'] . ']',
            $level
        );
        $this->flushNow('... ... IDs: ' . $idsDisplay);

        $idList = implode(',', array_map('intval', $action['ids']));
        $this->applyUpdate(
            'UPDATE "' . $tableName . '"
            SET "' . $tableName . '"."' . $fieldName . '" = \'' . $action['new'] . '\'
            WHERE "ID" IN (' . $idList . ')'
        );
    }

    // ----------------------------------------------------------------
    //                       Lookup / resolution
    // ----------------------------------------------------------------

    /**
     * Given a (possibly stale) class name, find the single best matching known
     * class. Returns the addslashes()'d FQCN (so it can be dropped straight
     * into single-quoted SQL), or null if no unique match.
     *
     * Strategies:
     *   (a) extract the short class name and match it against known classes;
     *   (b) if (a) has no unique match, match the short name against known
     *       table names (exact, then trailing "_<shortName>").
     */
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
        foreach ($this->listOfAllClasses as $fqcnSlashed => $fqcnShort) {
            if ($shortName === $fqcnShort) {
                $byShort[$fqcnSlashed] = $fqcnSlashed;
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
                foreach ($fqcnList as $fqcnSlashed) {
                    $trailing[$fqcnSlashed] = $fqcnSlashed;
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

    /** @return array<string,string[]> */
    protected function getTableNameToClassMap(): array
    {
        if (null !== $this->tableNameToClassMap) {
            return $this->tableNameToClassMap;
        }
        $map = [];
        foreach ($this->listOfAllClasses as $fqcnSlashed => $fqcnShort) {
            $fqcn = stripslashes($fqcnSlashed);
            if (! class_exists($fqcn)) {
                continue;
            }
            try {
                $tableName = $this->dataObjectSchema->tableName($fqcn);
            } catch (\Throwable $e) {
                continue;
            }
            if ($tableName) {
                $map[$tableName][] = $fqcnSlashed;
            }
        }
        return $this->tableNameToClassMap = $map;
    }

    /**
     * Build the per-call child-table lookup. Suffix-aware: a recursive call
     * on a _Live table will check _Live subclass tables (not the base ones).
     *
     * Bug fix: the previous code passed $objectClassName here, so every
     * subclass row received the parent table — the join was therefore always
     * true and $optionCount blew past 1, falling into the wrong fallback.
     *
     * @return array<string,string>  [classFqcn => tableName]
     */
    protected function buildChildTableMap(string $tableName, string $objectClassName): array
    {
        $suffix = $this->getTableSuffix($tableName);
        $checkTables = [];
        foreach (ClassInfo::subclassesFor($objectClassName) as $optionClassName) {
            if ($optionClassName === $objectClassName) {
                continue;
            }
            $base = $this->dataObjectSchema->tableName($optionClassName);
            if (! $base) {
                continue;
            }
            $optionTableName = $base . $suffix;
            if ($optionTableName === $tableName) {
                continue;
            }
            if (! $this->tableExists($optionTableName)) {
                continue;
            }
            $checkTables[$optionClassName] = $optionTableName;
        }
        return $checkTables;
    }

    protected function getTableSuffix(string $tableName): string
    {
        if (substr($tableName, -5) === '_Live') {
            return '_Live';
        }
        if (substr($tableName, -9) === '_Versions') {
            return '_Versions';
        }
        return '';
    }

    protected function isVersionsTable(string $tableName): bool
    {
        return substr($tableName, -9) === '_Versions';
    }

    protected function buildWhereClause(string $fieldName): string
    {
        return '"' . $fieldName . '" NOT IN (\'' . implode("', '", array_keys($this->listOfAllClasses)) . "')";
    }

    /**
     * Best valid value for a ClassName when no other heuristic works.
     * Memoised per class+table+field.
     */
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
            'SELECT ' . $fieldName . ', COUNT(*) AS magnitude
            FROM ' . $tableName . '
            GROUP BY ' . $fieldName . '
            ORDER BY magnitude DESC
            LIMIT 1'
        );
        foreach ($rowsForBest as $r) {
            if (in_array($r[$fieldName], $values, true)) {
                $best = $r[$fieldName];
                break;
            }
        }
        if (! $best) {
            $best = key($values);
        }
        return $this->bestClassNameStore[$key] = $best;
    }

    // ----------------------------------------------------------------
    //              Suspicious-value sweep over every column
    // ----------------------------------------------------------------

    /**
     * Broader sweep: regex every column in every table for anything that
     * looks like an FQCN, and try to remap it through findMatchingClassname()
     * if the class doesn't actually exist.
     */
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
                $rows = DB::query(
                    'SELECT "ID" AS row_id, "' . $fieldName . '" AS row_value
                    FROM "' . $tableName . '"
                    WHERE "' . $fieldName . '" REGEXP \'^[A-Z][A-Za-z0-9_]*(\\\\\\\\[A-Z][A-Za-z0-9_]*)+$\';'
                );
                foreach ($rows as $row) {
                    $value = $row['row_value'] ?? '';
                    if ($value === '' || class_exists($value)) {
                        continue;
                    }
                    $id = $row['row_id'] ?? null;
                    $better = $this->findMatchingClassname($value);
                    if ($better) {
                        $this->flushNow(
                            '... ' . $tableName . '.' . $fieldName . ' #' . $id
                            . ': ' . $value . ' → ' . $better,
                            'created'
                        );
                        $this->applyUpdate(
                            'UPDATE "' . $tableName . '"
                            SET "' . $fieldName . '" = \'' . $better . '\'
                            WHERE "ID" = ' . (int) $id
                        );
                    } else {
                        $unresolved[] = [
                            'Table' => $tableName,
                            'Field' => $fieldName,
                            'ID' => $id,
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
            $this->flushNow(
                '... ... ' . $u['Table'] . '.' . $u['Field'] . ' #' . $u['ID'] . ': ' . $u['Value']
            );
        }
    }

    // ----------------------------------------------------------------
    //                            Write gate
    // ----------------------------------------------------------------

    /**
     * Single chokepoint for every DB write produced by this task.
     * Honours $dryRun: in dry-run mode the SQL is not executed.
     */
    protected function applyUpdate(string $sql)
    {
        if ($this->dryRun) {
            return;
        }
        $this->runUpdateQuery($sql, 2);
    }

    protected function fixFieldSize(string $tableName)
    {
        $database = DB::get_conn()->getSelectedDatabase();
        if ($this->dryRun) {
            return;
        }
        DB::query('ALTER TABLE "' . $database . '"."' . $tableName . '" CHANGE ClassName ClassName VARCHAR(255);');
    }
}
