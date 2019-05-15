<?php

namespace Sunnysideup\MigrateData\Tasks;

use SilverStripe\Dev\BuildTask;
use SilverStripe\Dev\Tasks\MigrateFileTask;
use SilverStripe\Control\Director;
use SilverStripe\Assets\File;
use SilverStripe\AssetAdmin\Controller\AssetAdmin;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLSelect;

class PublishAllFiles extends MigrateDataTask
{
    protected $title = 'Publish All Files';

    protected $description = 'Get all files ready to go';

    public function performMigration()
    {

        $admin = self::singleton(AssetAdmin::class);

        $sqlQuery = new SQLSelect();
        $sqlQuery->setFrom('File');
        $sqlQuery->selectField('ID');
        //$sqlQuery->addWhere(['ClassName' => 'SilverStripe\Assets\File']);

        // Execute and return a Query object
        $result = $sqlQuery->execute();

        foreach ($result as $row) {
            $file = File::get()->byID($row['ID']);

            $name = $file->getFilename();
            $originalDir = BASE_PATH . '/'.Director::publicDir().'/assets/';

            if(file_exists($originalDir.$name) && !is_dir($originalDir.$name)) {
                if(!$file->getField('FileHash')) {
                    $hash = sha1_file($originalDir.$name);
                    DB::query('UPDATE "File" SET "FileHash" = \''.$hash.'\' WHERE "ID" = \''.$file->ID.'\' LIMIT 1;');
                } else {
                    $hash = $file->FileHash;
                }

                $targetDir = str_replace(
                    './',
                    '',
                    BASE_PATH . '/' . Director::publicDir() . '/assets/.protected/'. dirname($name)
                        .'/'. substr($hash, 0, 10) . '/'
                );


                if(!file_exists($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }

                rename($originalDir . $name, $targetDir . basename($name));


                $this->flushNow($originalDir . $name .' > '. $targetDir . basename($name), 'obsolete');
            }else{
                $admin->generateThumbnails($file);
                $file->copyVersionToStage('Stage', 'Live');
                $this->flushNow('Published: '.$name, 'created');
            }

            $file->destroy();
        }

    }
}
