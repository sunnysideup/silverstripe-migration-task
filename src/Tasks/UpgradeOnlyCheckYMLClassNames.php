<?php

namespace Sunnysideup\MigrateData\Tasks;

use SilverStripe\Control\Director;

/**
 * Reorganizing the SiteTree led to AsbestosLicenceHolders records being in the _Live table but not in the
 * draft table. This tasks should be run once to get rid of them.
 */
class UpgradeOnlyCheckYMLClassNames extends MigrateDataTaskBase
{
    /**
     * @var string
     */
    protected $title = 'Load your list of class names';

    protected $description = 'Upgrade ONLY!';

    protected $enabled = true;

    private static $folders_to_ignore = [
        'public',
        'resources',
        'themes',
        'vendor',
    ];

    private static $files_to_ignore = [
        'database.legacy.yml',
    ];

    /**
     * Implement this method in the task subclass to
     * execute via the TaskRunner.
     */
    protected function performMigration()
    {
        $count = 0;
        $subDirs = $this->getSubDirectories(Director::baseFolder() . '/');
        $ymlFiles = [];
        foreach ($subDirs as $subDir) {
            $subDir = rtrim($subDir, '/') . '/';
            $fullSubDir = $subDir . '_config/';
            if (file_exists($fullSubDir)) {
                $toAdds = $this->getYMLFiles($fullSubDir);
                foreach ($toAdds as $toAdd) {
                    if (! in_array(basename($toAdd), $this->Config()->get('files_to_ignore'), true)) {
                        $ymlFiles[] = $toAdd;
                    }
                }
            }
        }
        foreach ($ymlFiles as $fileName) {
            $this->flushNowLine();
            $this->flushNow('STARTING TESTING: ' . $fileName . '');
            $this->flushNowLine();
            $count = 0;
            $alreadySet = [];
            if (! file_exists($fileName)) {
                die(' Could not find ' . $fileName);
            }
            $fp = fopen($fileName, 'r');
            $hasLine = true;
            while ($hasLine) {
                $line = stream_get_line($fp, 1024 * 1024, "\n");
                if (! $line) {
                    break;
                }
                ++$count;
                $isProperty = false;
                // $this->flushNow( '...';
                //skip lines that are indented
                if (' ' === substr($line, 0, 1)) {
                    $isProperty = true;
                }

                if ($isProperty) {
                    $className = end($alreadySet);
                    if ($className && class_exists($className)) {
                        if (strpos($line, ':')) {
                            $myItems = explode(':', $line);
                            if (2 === count($myItems) && $myItems[0] && $myItems[1]) {
                                $property = trim($myItems[0]);
                                $property = trim($myItems[0], "'");
                                $property = trim($myItems[0], '"');
                                if (strpos($property, '\\')) {
                                    if (! class_exists($property)) {
                                        $this->flushNow('
ERROR ' . $className . '.' . $property . ' may not exist as class name but looks like one.<br>');
                                    }
                                } elseif (strpos($property, '*')) {
                                    $this->flushNow('
ERROR ' . $className . '.' . $property . ' contains *.<br>');
                                } elseif (! property_exists($className, $property)) {
                                    $this->flushNow('
ERROR ' . $className . '.' . $property . ' property could not be found<br>');
                                } else {
                                    $this->flushNow('
SUCCESS ' . $className . '.' . $property . '');
                                }
                            } else {
                                $this->flushNow('
    ' . $line . ' ... not two items<br>');
                            }
                        } else {
                            $this->flushNow('
' . $line . ' ... no colon<br>');
                        }
                    } elseif ($className) {
                        $this->flushNow('
COULD NOT FIND ' . $className . '<br>');
                    }
                } else {
                    if (! strpos($line, '\\')) {
                        continue;
                    }
                    if (strpos($line, '*')) {
                        continue;
                    }
                    $line = str_replace(':', '', $line);
                    $line = trim($line);
                    if (isset($alreadySet[$line])) {
                        $this->flushNow('

ERROR: Two mentions of ' . $line . '<br>');
                    } else {
                        $alreadySet[$line] = $line;
                        if (! class_exists($line)) {
                            $this->flushNow('

ERROR: Could not find class ' . $line . '<br>');
                        }
                    }
                }
                //not a class name
            }
            fclose($fp);
            $this->flushNowLine();
            $this->flushNow('' . $count . ' lines');
            $this->flushNowLine();
            $count = 0;
        }
    }

    private function getSubDirectories($dir)
    {
        $subDirs = [];
        $ignore = $this->Config()->folders_to_ignore;
        $dir = rtrim($dir, '/') . '/';
        // Get and add directories of $dir
        $directories = array_filter(glob($dir . '*'), 'is_dir');
        // $subDir = array_merge($subDir, $directories);
        // Foreach directory, recursively get and add sub directories
        foreach ($directories as $directory) {
            if (! in_array(basename($directory), $ignore, true)) {
                $subDirs[] = $directory;
            }
        }

        return $subDirs;
        // Return list of sub directories
    }

    private function getYMLFiles($dir)
    {
        $dir = rtrim($dir, '/') . '/';
        $files = glob($dir . '*.yaml');
        foreach (glob($dir . '*.yml') as $file) {
            $files[] = $file;
        }

        return $files;
    }
}
