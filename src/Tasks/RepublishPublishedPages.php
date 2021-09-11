<?php

namespace Sunnysideup\MigrateData\Tasks;

/**
 * This code is stolen from somewhere, but unfortunately I am not sure exactly where.
 * my guess is that it used to be in the CMS.
 */
class RepublishPublishedPages extends PublishAllPages
{
    protected $title = 'Republish Published Pages';

    protected $description = 'Publish All Pages on the Entire Site that area already published';

    public function run($request)
    {
        $this->setOnlyPublishedPages(true);
        parent::run($request);
    }
}
