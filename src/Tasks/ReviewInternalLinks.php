<?php

namespace Sunnysideup\MigrateData\Tasks;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Environment;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DB;

class ReviewInternalLinks extends MigrateDataTaskBase
{
    protected $title = 'Review Internal Links';

    protected $description = 'Goes through all the imported content and reviews internal links';

    protected $type = 0;

    protected $count = 0;

    protected $step = 10;

    protected $filter = 10;

    protected $allLinks = [];

    protected $replacements = [];

    protected $fieldsToTest = [
        'Content',
    ];

    protected static $filtered_class_names = [];

    public function run($request)
    {
        Environment::increaseTimeLimitTo();
        Environment::increaseMemoryLimitTo();

        $tableHTML = '
            <table>
                <thead>
                    <tr>
                        <th>Count</th>
                        <th>Links</th>
                        <th>Title</th>
                        <th>Content</th>
                    </tr>
                </thead>
                <tbody>
        ';
        $ids = [];
        if ($request->getVar('ids')) {
            $ids = explode(',', $request->getVar('ids'));
        }
        if ($request->getVar('type')) {
            $this->type = $request->getVar('type');
        }
        $objects = DataList::create();
        if (count($ids)) {
            echo $tableHTML;
            $objects = SiteTree::get()->sort('ID', 'ASC')->filter(['ID' => $ids]);
            foreach ($objects as $object) {
                $this->printFields($object);
            }
        } else {
            echo '
                By default 200 random pages are loaded.

            ';

            echo $tableHTML;

            if ($request->getVar('page')) {
                if ('all' === $request->getVar('page')) {
                    $isPage = false;
                    $limit = 5000;
                    $this->step = 10;
                    $start = 0;
                } else {
                    $isPage = true;
                    $limit = 500;
                    $start = $limit * ((int) $request->getVar('page') - 1);
                    echo '<h1>Page: ' . (int) $request->getVar('page') . '</h1>';
                }
            } else {
                $isPage = false;
                $limit = 50;
                $this->step = 51;
                $start = 0;
                echo '<h1>Random Selection</h1>';
            }
            for ($i = 0; $i < $limit; $i += $this->step) {
                $objects = null;
                if ($isPage) {
                    $objects = SiteTree::get()->sort('ID', 'ASC')->limit($this->step, $i + $start);
                }
                $filter = $this->Config()->get('filtered_class_names');
                if (! empty($filter)) {
                    $objects = $objects->filter($filter);
                }
                foreach ($objects as $object) {
                    $this->printFields($object);
                }
            }
        }
        $linksAll = [];
        ksort($this->allLinks);
        foreach ($this->allLinks as $url => $details) {
            $linksAll[] = $url . ' | ' . $details['count'];
        }
        echo  '
                    <tr>
                        <th>---</th>
                        <th>---</th>
                        <th>Full List of Links</th>
                        <th>
                        <ul>
                            <li>
                                ' . implode('</li><li>', $linksAll) . '
                            </li>
                        </ul>
                        </th>
                    </tr>
        ';
        $replacementsAll = [];
        ksort($this->replacements);
        foreach ($this->replacements as $details) {
            $replacementsAll[] = 'FR: ' . $details['from'] . '<br />TO: ' . $details['to'] . '<br />RS: ' . $details['result'] . '<br /><br />';
        }
        echo  '
                    <tr>
                        <th>---</th>
                        <th>---</th>
                        <th>Full List of Links</th>
                        <th>
                        <ul>
                            <li>
                                ' . implode('</li><li>', $replacementsAll) . '
                            </li>
                        </ul>
                        </th>
                    </tr>
        ';
        echo '</tbody></table>';
    }

    public function printFields($object)
    {
        ++$this->count;
        $links = [];
        foreach ($this->fieldsToTest as $field) {
            if (! empty($object->{$field})) {
                $dom = new \DOMDocument();

                @$dom->loadHTML(
                    mb_convert_encoding($object->{$field}, 'HTML-ENTITIES', 'UTF-8'),
                    LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
                );
                if (! $dom) {
                    $links[] = 'Error 1 in ' . $field;

                    continue;
                }
                if (! $this->type || 'a' === strtolower($this->type)) {
                    $hrefs = $dom->getElementsByTagName('a');
                    for ($i = 0; $i < $hrefs->length; ++$i) {
                        $href = $hrefs->item($i);
                        $url = $href->getAttribute('href');
                        $this->cleanupLittleMistake($object, $field, $url);

                        $links[$url] = $url . ' | A | ' . $field;

                        if (! isset($this->allLinks[$url])) {
                            $this->allLinks[$url] = [
                                'count' => 0,
                                'type' => 'A',
                            ];
                        }
                        ++$this->allLinks[$url]['count'];
                    }
                }
                if (! $this->type || 'img' === strtolower($this->type)) {
                    $hrefs = $dom->getElementsByTagName('img');
                    for ($i = 0; $i < $hrefs->length; ++$i) {
                        $href = $hrefs->item($i);
                        $url = $href->getAttribute('src');
                        $this->cleanupLittleMistake($object, $field, $url);

                        $links[$url] = $url . ' | IMG | ' . $field;

                        if (! isset($this->allLinks[$url])) {
                            $this->allLinks[$url] = [
                                'count' => 0,
                                'type' => 'IMG',
                            ];
                        }
                        ++$this->allLinks[$url]['count'];
                    }
                } else {
                    $links[] = 'Error 2 in ' . $field;
                }
            }
        }
        echo
        '<tr>
            <td>' . $this->count . '</td>
            <td>
                <h6><a href="' . $object->CMSEditLink() . '">CMS</></h6>
                <h6><a href="' . $object->Link() . '">Site</a></h6>
            </td>
            <td>
                ' . $object->Title . '
            </td>
            <td>
                <ul>
                    <li>
                        ' . implode('</li><li>', $links) . '
                    </li>
                </ul>
            </td>
        </tr>';
    }

    protected function performMigration()
    {
    }

    private function cleanupLittleMistake($object, $field, $url)
    {
        $oldNeedles = [
            'assets/oldsite/assets/' => 'images/assets/',
            'assets/oldsite/' => 'images/',
        ];
        foreach ($oldNeedles as $oldNeedle => $newNeedle) {
            if (false !== strpos($url, $oldNeedle)) {
                $replacementURL = str_replace($oldNeedle, $newNeedle, $url);
                $table = '';
                // if($this->urlExists($url) === false && $this->urlExists($replacementURL) === true) {
                if (strpos($url, "'")) {
                    user_error('bad url: ' . $url);
                }
                if ('Content' === $field) {
                    $table = 'SiteTree';
                }
                foreach (['', '_Live', '_Versions'] as $stage) {
                    DB::query('
                        UPDATE "' . $table . $stage . '"
                        SET "' . $field . '" = REPLACE(
                            "' . $field . '",
                            \'' . $url . '\',
                            \'' . $replacementURL . '\'
                        )
                        WHERE ID = ' . $object->ID . ';
                    ');
                }
                $this->replacements[] = [
                    'from' => $url,
                    'to' => $replacementURL,
                    'result' => SiteTree::get()->byID($object->ID)->{$field},
                ];
            }
        }
    }
}
