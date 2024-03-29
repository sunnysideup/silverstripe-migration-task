<?php

namespace Sunnysideup\MigrateData\Tasks;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Versioned\Versioned;
use Sunnysideup\Flush\FlushNow;
use Sunnysideup\Flush\FlushNowImplementor;

/**
 * This code is stolen from somewhere, but unfortunately I am not sure exactly where.
 * my guess is that it used to be in the CMS.
 */
class PublishAllPages extends BuildTask
{
    protected $title = 'Publish All Pages';

    protected $description = 'Publish All Pages on the Entire Site (copy content from draft to live)';

    protected $step = 10;

    protected $allowed = true;

    protected $onlyPublishedPages = false;

    public function setAllowed(?bool $allowed = true): self
    {
        $this->allowed = $allowed;

        return $this;
    }

    public function setOnlyPublishedPages(?bool $bool = true): self
    {
        $this->onlyPublishedPages = $bool;

        return $this;
    }

    public function run($request)
    {
        Environment::increaseTimeLimitTo();
        Environment::increaseMemoryLimitTo();
        Config::modify()->set(DataObject::class, 'validation_enabled', false);
        if ($request->requestVar('confirm') || Director::is_cli() || $this->allowed) {
            // Protect against CSRF on destructive action
            if (Director::is_cli() || SecurityToken::inst()->checkRequest($request)) {
                $start = 0;
                $pages = SiteTree::get()->limit($this->step, $start);
                FlushNowImplementor:
                FlushNowImplementor::do_flush('<ol>');
                $count = 0;
                while ($pages->exists()) {
                    foreach ($pages as $page) {
                        $isPublished = $page->IsPublished() && !$page->isModifiedOnDraft();
                        FlushNowImplementor::do_flush('publishing: ' . $page->Title, 'created');
                        $page->writeToStage(Versioned::DRAFT, true);
                        if ($isPublished) {
                            $page->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
                            $page->publishRecursive();
                        }
                        $page->destroy();
                        unset($page);
                        ++$count;
                    }
                    $start += $this->step;
                    $pages = SiteTree::get()->limit($this->step, $start);
                }
                FlushNowImplementor::do_flush('</ol>');
                FlushNowImplementor::do_flush('<h2>--- PUBLISHED ' . $count . ' Pages ---</h2>');
            } else {
                Controller::curr()->httpError(400);

                return;
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
                    . "It's intended to be used after there have been massive edits of the content, such as when "
                    . 'the site was first built.'
            );
            $response .=
                '<h1>' . _t(__CLASS__ . '.PUBALLFUN', 'Publish All functionality') . '</h1>
                <p>' . $publishAllDescription . '</p>
                <form method="get" action="">
                    <input type="submit" name="confirm" value="'
                . _t(__CLASS__ . '.PUBALLCONFIRM', 'Please publish every page in the site', 'Confirmation button') . '" />'
                . $tokenHtml .
                '</form>';
            FlushNowImplementor::do_flush($response);
        }
    }
}
