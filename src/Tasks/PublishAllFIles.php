<?php

namespace Sunnysideup\MigrateData\Tasks;

use SilverStripe\Dev\BuildTask;
use SilverStripe\Dev\Tasks\MigrateFileTask;
use SilverStripe\Control\Director;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\AssetAdmin\Controller\AssetAdmin;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Versioned\Versioned;

class PublishAllFiles extends MigrateDataTask
{
    protected $title = 'Publish All Files';

    protected $description = 'Get all files ready to go';

    public function performMigration()
    {

        $admin = self::singleton(AssetAdmin::class);

        $sqlQuery = new SQLSelect();
        $sqlQuery->setFrom('File');
        $sqlQuery->setOrderBy('ParentID');
        $sqlQuery->selectField('ID');
        //$sqlQuery->addWhere(['ClassName' => 'SilverStripe\Assets\File']);

        // Execute and return a Query object
        $result = $sqlQuery->execute();
        foreach ($result as $row) {
            $file = File::get()->byID($row['ID']);
            $name = $file->getFilename();
            if($file instanceof Folder) {
                $this->flushNow('Skipping Folder: '.$name);
            } else {
                $originalDir = ASSETS_PATH.'/';

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
                        ASSETS_PATH .'/.protected/'. dirname($name)
                            .'/'. substr($hash, 0, 10) . '/'
                    );


                    if(!file_exists($targetDir)) {
                        mkdir($targetDir, 0755, true);
                    }

                    $this->flushNow($originalDir . $name .' > '. $targetDir . basename($name), 'obsolete');

                    rename(
                        $originalDir . $name,
                        $targetDir . basename($name)
                    );


                } else {
                    $this->flushNow('Publishing: '.$name, 'created');
                    $admin->generateThumbnails($file);
                    $file->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
                }
            }

            $file->destroy();
        }

    }
}
