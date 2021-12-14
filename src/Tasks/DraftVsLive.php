<?php

namespace Sunnysideup\MigrateData\Tasks;

use SilverStripe\ORM\DB;

class DraftVsLive extends MigrateDataTaskBase
{
    protected $title = 'Compare Drafts vs Live';

    protected $description = 'Go through every table and compare DRAFT vs Live';

    protected $enabled = true;

    protected $selectedTables = [];

    protected $missingColumns = [];

    protected $deleteLiveOnlyRecords = true;

    protected function performMigration()
    {
        //get tables in DB
        $dbTablesPresent = [];
        if (empty($this->selectedTables)) {
            $rows = DB::query('SHOW tables');
            foreach ($rows as $row) {
                $table = array_pop($row);
                $dbTablesPresent[$table] = $table;
            }
        } else {
            $dbTablesPresent = $this->selectedTables;
        }
        foreach ($dbTablesPresent as $table) {
            $this->missingColumns[$table] = [];
            $liveTable = $table . '_Live';
            if ($this->tableExists($liveTable)) {
                if($this->deleteLiveOnlyRecords) {
                    $this->deleteLiveOnlyRecords($table, $liveTable);
                }
                //check count
                $draftCount = (int) DB::query('SELECT COUNT(ID) FROM ' . $table . ' ORDER BY ID;')->value();
                $liveCount = (int) DB::query('SELECT COUNT(ID) FROM ' . $liveTable . ' ORDER BY ID;')->value();
                if ((int) $draftCount !== (int) $liveCount) {
                    $this->flushNow(
                        'TABLE ' . $table . ' count (' . $draftCount . ')
                        is not the same as count for ' . $liveTable . ' (' . $liveCount . '),
                        DIFFERENCE: ' . ($draftCount - $liveCount) . ' more entries on DRAFT.',
                        'deleted'
                    );
                } else {
                    $this->flushNow('TABLE ' . $table . ' DRAFT and LIVE count is identical ...', 'created');
                }

                //check columns
                $this->compareColumnsOuter($table, $liveTable);
                $allOk = true;
                $draftRows = DB::query('SELECT * FROM ' . $table . ' ORDER BY ID;');

                //check rows
                foreach ($draftRows as $draftRow) {
                    $allOk = $this->compareOneRow($draftRow, $table, $liveTable);
                }
                if ($allOk) {
                    $this->flushNow('... For matching rows, DRAFT and LIVE are identical.');
                }
            } else {
                $this->flushNow('No Live version for ' . $table);
            }
        }
    }

    protected function compareOneRow($draftRow, $table, $liveTable)
    {
        $allOk = true;
        $liveRows = DB::query('SELECT * FROM ' . $liveTable . ' WHERE ID = ' . $draftRow['ID'] . ';');
        foreach ($liveRows as $liveRow) {
            $results = array_diff($draftRow, $liveRow) + array_diff($liveRow, $draftRow);
            foreach (array_keys($results) as $key) {
                if (! isset($draftRow[$key])) {
                    $draftRow[$key] = '???';
                }
                if (! isset($liveRow[$key])) {
                    $liveRow[$key] = '???';
                }
                $allOk = false;
                $this->flushNow(
                    '... ... DRAFT !== LIVE for <strong>' . $table . '</strong>, ' .
                    'ID <strong>' . $draftRow['ID'] . '</strong>, ' .
                    'FIELD: <strong>' . $key . '</strong>:
                    <span style="color: purple">' . strip_tags(substr(print_r($draftRow[$key], 1), 0, 100)) . '</span> !==
                    <span style="color: orange">' . strip_tags(substr(print_r($liveRow[$key], 1), 0, 100)) . '</span>  ',
                    'deleted'
                );
            }
        }

        return $allOk;
    }

    protected function compareColumnsOuter($table, $liveTable, $backwards = false)
    {
        $draftRows1 = DB::query('SELECT * FROM ' . $table . ' ORDER BY ID LIMIT 1;');
        $LiveRows1 = DB::query('SELECT * FROM ' . $liveTable . ' ORDER BY ID LIMIT 1;');
        foreach ($draftRows1 as $draftRow) {
            foreach ($LiveRows1 as $liveRow) {
                $this->compareColumnsInner($draftRow, $liveRow, $table);
            }
        }
    }

    protected function compareColumnsInner($rowA, $rowB, $table, $backwards = false)
    {
        $tableA = 'DRAFT';
        $tableB = 'LIVE';
        if ($backwards) {
            $tableB = 'DRAFT';
            $tableA = 'LIVE';
        }
        $result = array_diff_key($rowA, $rowB);
        foreach (array_keys($result) as $key) {
            $this->missingColumns[$table][$key] = $key;
            $this->flushNow(
                '... Found a column in the ' . $tableA . ' table that did not match the ' . $tableB . ' table: ' . $key,
                'deleted'
            );
        }
        if (! $backwards) {
            $this->compareColumnsInner($rowB, $rowA, $table, true);
        }
    }

    protected function deleteLiveOnlyRecords(string $tableNameDraft, string $tableNameLive)
    {
        $rows = DB::query('
            SELECT "'.$tableNameLive.'"."ID"
            FROM "'.$tableNameLive.'"
            LEFT JOIN  "'.$tableNameDraft.'" ON "'.$tableNameLive.'"."ID" = "'.$tableNameDraft.'"."ID"
            WHERE "'.$tableNameDraft.'"."ID" IS NULL;
        ');
        foreach($rows as $row) {
            $this->flushNow(
                'Deleting from '.$tableNameLive.' where ID = '.$row['ID'],
                'deleted'
            );
            DB::query('
                DELETE FROM "'.$tableNameLive.'" WHERE ID = '.$row['ID'].';
            ');
        }
    }
}
