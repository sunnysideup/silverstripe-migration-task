<?php

namespace Sunnysideup\MigrateData\Tasks;

use SilverStripe\Control\Director;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Security\SecurityToken;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use Sunnysideup\Flush\FlushNow;

/**
 * This code is stolen from somewhere, but unfortunately I am not sure exactly where.
 * my guess is that it used to be in the CMS.
 */
class PublishAll extends BuildTask
{
    protected $title = 'Publish All Pages';

    protected $description = 'Publish All Pages on the Entire Site (copy content from draft to live)';

    protected $step = 10;

    public function run($request)
    {
        Environment::increaseTimeLimitTo();
        Environment::increaseMemoryLimitTo();
        if ($request->requestVar('confirm') || Director::is_cli()) {
            // Protect against CSRF on destructive action
            if (Director::is_cli() || SecurityToken::inst()->checkRequest($request)) {
                $start = 0;
                $pages = SiteTree::get()->limit($this->step, $start);
                $this->flushNow('<ol>');
                $count = 0;
                while ($pages && $pages->count()) {
                    foreach ($pages as $page) {
                        $this->flushNow('publishing: '.$page->Title, 'created');
                        $page->writeToStage(Versioned::DRAFT);
                        $page->publish(Versioned::DRAFT, Versioned::LIVE);
                        $page->publishRecursive();
                        $page->destroy();
                        unset($page);
                        $count++;
                    }
                    $start += $this->step;
                    $pages = SiteTree::get()->limit($this->step, $start);
                }
                $this->flushNow('</ol>');
                $this->flushNow('<h2>--- PUBLISHED '.$count.' Pages ---</h2>');
            } else {
                return Controller::curr()->httpError(400);
            }
        } else {
            $response = '';
            $token = SecurityToken::inst();
            $fields = FieldList::create();
            $token->updateFieldSet($fields);
            $tokenField = $fields->first();
            $tokenHtml = ($tokenField) ? $tokenField->FieldHolder() : '';
            $publishAllDescription = _t(
                __CLASS__ . '.PUBALLFUN2',
                'Pressing this button will do the equivalent of going to every page and pressing "publish".  '
                . 'It\'s intended to be used after there have been massive edits of the content, such as when '
                . 'the site was first built.'
            );
            $response .=
                '<h1>' . _t(__CLASS__ . '.PUBALLFUN', 'Publish All functionality') . '</h1>
                <p>' . $publishAllDescription . '</p>
                <form method="get" action="">
                    <input type="submit" name="confirm" value="'
                    . _t(__CLASS__ . '.PUBALLCONFIRM', 'Please publish every page in the site', 'Confirmation button') .'" />'
                    . $tokenHtml .
                '</form>';
            $this->flushNow($response);
        }
    }

    public function flushNow($content, $style = '')
    {
        FlushNow::do_flush($content, $style);
    }
}
