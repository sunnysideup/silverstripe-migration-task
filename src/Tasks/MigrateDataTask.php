<?php

namespace Sunnysideup\MigrateData\Tasks;

use SilverStripe\ORM\DB;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Control\Director;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\Requirements;

class MigrateDataTask extends MigrateDataTaskBase
{

    /**
     * an array that is formatted like this:
     *     Name => [
     *         pre_sql_queries: [
     *             - 'SELECT * FROM FOO'
     *         ],
     *         data => [
     *               [
     *                   'include_inserts' => true|false, #assumed true if not provided
     *                   'old_table' => 'foo',
     *                   'new_table' => 'bar' (can be the same!)
     *
     *                   'simple_move_fields' => ['A', 'B', 'C']
     *                       ---  OR ----
     *                   'complex_move_fields' => ['A' => 'Anew', 'B' => 'BBew', 'C2' => 'Cnew']
     *                ]
     *         ]
     *         publish_classes => [
     *             - MyClassName1
     *             - MyClassName2
     *         ]
     *         post_sql_queries => [
     *             - 'SELECT * FROM FOO'
     *         ]
     *
     *     ]
     * @var array
     */
    private static $items_to_migrate = [];

    protected $title = 'Migrate Data';

    protected $description = 'Migrates specific data defined in yml';

    protected $enabled = true;

    /**
     * Queries the config for Migrate definitions, and runs migrations
     * if you extend this task then overwrite it this method
     *
     */
    protected function performMigration()
    {
        $fullList = Config::inst()->get(self::class, 'items_to_migrate');
        foreach ($fullList as $item => $details) {
            $this->flushNow('<h2>Starting Migration for '.$item.'</h2>');

            if (isset($details['pre_sql_queries'])) {
                $preSqlQueries = $details['pre_sql_queries'];
                $this->runSQLQueries($preSqlQueries, 'PRE');
            }

            if (isset($details['data'])) {
                $data = $details['data'];
                $this->runMoveData($data);
            }

            if (isset($details['publish_classes'])) {
                $publishClasses = $details['publish_classes'];
                $this->runPublishClasses($publishClasses);
            }

            if (isset($details['post_sql_queries'])) {
                $postSqlQueries = $details['post_sql_queries'];
                $this->runSQLQueries($postSqlQueries, 'POST');
            }

            $this->flushNow('<h2>Finish Migration for '.$item.'</h2>');
        }
    }

}
