<?php

namespace Sunnysideup\MigrateData\Tasks;

use SilverStripe\AssetAdmin\Controller\AssetAdmin;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Versioned\Versioned;
use Sunnysideup\Flush\FlushNow;
use Sunnysideup\Flush\FlushNowImplementor;

class PublishAllFiles extends MigrateDataTaskBase
{
    /**
     * @var mixed
     */
    public $admin;

    protected $title = 'Publish All Files';

    protected $description = 'Get all files ready to go - useful in SS3 to SS4 conversion.';

    protected $updateLocation = false;

    protected $generateThumbnails = false;

    protected $oneFolderOnlyID = 0;

    protected $oneFileOnlyID = 0;

    protected $enabled = true;

    /**
     * @return PublishAllFiles
     */
    public function setUpdateLocation(bool $b)
    {
        $this->updateLocation = $b;

        return $this;
    }

    /**
     * @return PublishAllFiles
     */
    public function setGenerateThumbnails(bool $b)
    {
        $this->generateThumbnails = $b;

        return $this;
    }

    protected function performMigration()
    {
        $this->admin = Injector::inst()->get(AssetAdmin::class);
        $this->runForFolder(0);
        $this->compareCount();
    }

    protected function runForFolder($parentID)
    {
        if ($this->oneFolderOnlyID && $this->oneFolderOnlyID !== $parentID) {
            return;
        }
        if ($parentID) {
            $folder = Folder::get_by_id($parentID);
            FlushNowImplementor::do_flush('<h3>Processing Folder: ' . $folder->getFilename() . '</h3>');
        } else {
            FlushNowImplementor::do_flush('<h3>Processing Root Folder</h3>');
        }
        $sqlQuery = new SQLSelect();
        $sqlQuery->setFrom('File');
        $sqlQuery->selectField('ID');
        $sqlQuery->addWhere(['ParentID' => $parentID]);
        $sqlQuery->setOrderBy('Name');

        // Execute and return a Query object
        $result = $sqlQuery->execute();
        foreach ($result as $row) {
            $file = File::get_by_id($row['ID']);
            if (null !== $file) {
                $name = $file->getFilename();
                if (!$name) {
                    $file->write();
                    $name = $file->getFilename();
                }
                if ($this->oneFileOnlyID && $this->oneFileOnlyID !== $file->ID) {
                    continue;
                }
                if ($name) {
                    if ($this->updateLocation) {
                        $this->updateLocationForOneFile($file, $name);
                        $file = File::get_by_id($row['ID']);
                    }

                    try {
                        if ($file->exists()) {

                            $this->publishFile($file, $name);
                        } else {
                            FlushNowImplementor::do_flush('... Error in publishing V2 ...' . print_r($file->toMap(), 1), 'deleted');
                        }
                    } catch (\Exception $exception) {
                        FlushNowImplementor::do_flush('... Error in publishing V1 ...' . print_r($file->toMap(), 1), 'deleted');
                    }
                } else {
                    $fix = false;
                    foreach ([''] as $suffix) {
                        $fileNameOld = DB::query('SELECT "Filename" FROM "File' . $suffix . '" WHERE ID = ' . $file->ID)->value();
                        $fileNameNewTest = DB::query('SELECT "FileFilename" FROM "File' . $suffix . '" WHERE ID = ' . $file->ID)->value();
                        if ($fileNameOld && !$fileNameNewTest) {
                            $newFileName = str_replace(
                                'assets/',
                                '',
                                $fileNameOld
                            );
                            DB::query(
                                'UPDATE "File' . $suffix . '" SET "FileFilename" = \'' . $newFileName . '\' WHERE "ID" = \'' . $file->ID . '\' LIMIT 1;'
                            );
                            $fix = true;
                        }
                    }
                    if ($fix) {
                        FlushNowImplementor::do_flush(
                            '... Fixed file name for ' . $file->ID . ' - run this task again to complete.',
                            'created'
                        );
                    } else {
                        FlushNowImplementor::do_flush('... Error in finding name for ' . print_r($file->toMap(), 1), 'deleted');
                    }
                }

                $file->destroy();
            }

            if ($file instanceof Folder) {
                $this->runForFolder($file->ID);
            }
        }
    }

    protected function updateLocationForOneFile($file, $name)
    {
        $originalDir = ASSETS_PATH . '/';
        if (file_exists($originalDir . $name) && !is_dir($originalDir . $name)) {
            if (!$file->getField('FileHash')) {
                $hash = sha1_file($originalDir . $name);
                $this->runUpdateQuery('UPDATE "File" SET "FileHash" = \'' . $hash . '\' WHERE "ID" = \'' . $file->ID . "' LIMIT 1;");
            } else {
                $hash = $file->FileHash;
            }

            $targetDir = str_replace(
                './',
                '',
                ASSETS_PATH . '/.protected/' . dirname($name)
                    . '/' . substr((string) $hash, 0, 10) . '/'
            );

            if (!file_exists($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            FlushNowImplementor::do_flush($originalDir . $name . ' > ' . $targetDir . basename($name), 'obsolete');

            rename(
                $originalDir . $name,
                $targetDir . basename($name)
            );
        }
    }

    /**
     * @param null|int $parentID
     */
    protected function compareCount($parentID = null)
    {
        $where = '';
        if (null === $parentID) {
        } else {
            $where = ' WHERE ParentID = ' . $parentID;
        }
        $count1 = DB::query('SELECT COUNT("ID") FROM "File" ' . $where)->value();
        $count2 = DB::query('SELECT COUNT("ID") FROM "File_Live" ' . $where)->value();
        if ($count1 === $count2) {
            FlushNowImplementor::do_flush('<h1>Draft and Live have the same amount of items ' . $where . '</h1>', 'created');
        } else {
            FlushNowImplementor::do_flush(
                '
                Draft and Live DO NOT have the same amount of items ' . $where . ', ' . $count1 . ' not equal ' . $count2 . '',
                'deleted'
            );
            if (null === $parentID) {
                $parentIDs = File::get()->column('ParentID');
                $parentIDs = array_unique($parentIDs);
                foreach ($parentIDs as $parentID) {
                    $this->compareCount($parentID);
                }
            }
        }
    }

    protected function publishFile($file, $name)
    {
        FlushNowImplementor::do_flush('... Publishing: ' . $name . ', ID = ' . $file->ID);
        if ($this->generateThumbnails) {
            $this->admin->generateThumbnails($file);
        }
        $file->forceChange();
        $file->write();
        $file->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        $test = DB::query('SELECT COUNT(ID) FROM File_Live WHERE ID = ' . $file->ID)->value();
        if (0 === (int) $test) {
            FlushNowImplementor::do_flush('... error finding: ' . $name, 'deleted');
        }
    }
}
