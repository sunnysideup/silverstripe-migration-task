<?php

namespace Sunnysideup\MigrateData\Tasks;

use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;
use Sunnysideup\Flush\FlushNow;

 /**
  * SOURCE: https://gist.github.com/halkyon/ec08493c2906c1539a49
  * Remove old tables, columns, and indexes from a SilverStripe database.
  *
  * Define your obsolete tables, columns and indexes in {@link $deleted_tables},
  * {@link deleted_columns} and {@link deleted_indexes} and these will be deleted
  * from the database.
  *
  * In addition to that, it will automatically remove any tables and columns prefixed with "_obsolete".
  */

 /**
  * Update all systems.
  *
  * Class UpdateSystemsWithProductCodeVariantKeywords
  */
 class DeleteAllObsoleteFieldsAndTables extends BuildTask
 {
     protected $title = 'Delete all obsolete tables and fields';

     protected $description = 'Remove all tables and fields with the words _obsolete, _copy, or _backup in it. Set forreal=1 to run it for real';

     /**
      * If any of these tables are found in the database, they will be removed.
      *
      * @var array
      */
     private static $deleted_tables = [
         // 'SomeOldTable'
     ];

     /**
      * These columns should be deleted. * key indicates any table with columns listed in the array
      * value should be removed. If the key is a specific table, only columns listed in the array
      * for that table will be removed.
      *
      * @var array
      */
     private static $deleted_columns = [
         // '*' => array('SomeOldColumn'),
         // 'SomeOldTable' => array('Status', 'Version')
     ];

     /**
      * If any of these indexes are found in any tables, they will be removed.
      *
      * @var array
      */
     private static $deleted_indexes = [
         // 'SomeOldIndex'
     ];

     public function run($request)
     {
         if (empty($_REQUEST['forreal'])) {
             FlushNow::do_flush('=== Running in dry run mode. Add GET variable forreal to run for real. SQL will be displayed, but not executed ===');
         }

         if (empty($_REQUEST['flush'])) {
             FlushNow::do_flush('ERROR: Please run flush=1 to ensure manifest is up to date');

             return;
         }

         foreach ($this->config()->deleted_tables as $tableName) {
             if ('' === DB::query(sprintf("SHOW TABLES LIKE '%s'", $tableName))->value()) {
                 FlushNow::do_flush(sprintf('INFO: Table %s was not found ', $tableName));

                 continue;
             }

             $this->execute(sprintf('DROP TABLE "%s"', $tableName));
         }

         $obsoleteTables = $this->getTables(true);

         foreach ($obsoleteTables as $tableName) {
             $this->execute(sprintf('DROP TABLE "%s"', $tableName));
         }

         foreach ($this->getTables() as $tableName) {
             $this->deleteFieldsAndIndexes($tableName);
         }

         FlushNow::do_flush('Done');
     }

     protected function log($message)
     {
         FlushNow::do_flush($message);
     }

     protected function execute($sql)
     {
         if (empty($_REQUEST['forreal'])) {
             FlushNow::do_flush(sprintf('DRY RUN: Not running query: %s', $sql));

             return true;
         }

         DB::query($sql);
         FlushNow::do_flush(sprintf('INFO: Successfully executed SQL: %s', $sql));

         return true;
     }

     protected function getTables(?bool $obsoleteOnly = false)
     {
         $tables = [];
         $rows = DB::query('SHOW TABLES ');
         foreach ($rows as $row) {
             $table = array_pop($row);
             $in = true;
             if ($obsoleteOnly) {
                 $in = false;
                 foreach (['obsolete', 'copy', 'backup'] as $string) {
                     $haystack = strtolower($table);
                     $needle = '_' . $string;
                     if (false !== strpos($haystack, $needle)) {
                         $in = true;
                     }
                 }
             }
             if ($in) {
                 $tables[$table] = $table;
             }
         }

         return $tables;
     }

     protected function deleteFieldsAndIndexes(string $tableName)
     {
         // search through indexes, remove indexes marked for deletion
         foreach (DB::query(sprintf('SHOW INDEXES FROM "%s"', $tableName)) as $index) {
             if (in_array($index['Key_name'], $this->config()->deleted_indexes, true)) {
                 $this->execute(sprintf('DROP INDEX "%s" ON "%s"', $index['Key_name'], $tableName));
             }
         }

         // remove obsolete prefixed columns
         foreach (DB::query(sprintf('SHOW COLUMNS FROM "%s" WHERE "Field" LIKE \'_obsolete%%\'', $tableName)) as $column) {
             $this->execute(sprintf('ALTER TABLE "%s" DROP COLUMN "%s"', $tableName, $column['Field']));
         }

         // remove columns marked for deletion
         foreach ($this->config()->deleted_columns as $key => $columnNameArr) {
             // if the definitions were for a specific table that we're currently not processing
             if ('*' !== $key && $key !== $tableName) {
                 continue;
             }

             foreach ($columnNameArr as $columnName) {
                 foreach (DB::query(sprintf('SHOW COLUMNS FROM "%s" WHERE "Field" = \'%s\'', $tableName, $columnName)) as $column) {
                     $this->execute(sprintf('ALTER TABLE "%s" DROP COLUMN "%s"', $tableName, $column['Field']));
                 }
             }
         }
     }
 }
