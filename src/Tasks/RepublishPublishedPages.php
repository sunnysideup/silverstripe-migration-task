<?php

namespace Sunnysideup\MigrateData\Tasks;

use Symfony\Component\Console\Input\InputInterface;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;

/**
 * This code is stolen from somewhere, but unfortunately I am not sure exactly where.
 * my guess is that it used to be in the CMS.
 */
class RepublishPublishedPages extends PublishAllPages
{
    protected string $title = 'Republish Published Pages';

    protected static string $description = 'Publish All Pages on the Entire Site that area already published';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $this->setOnlyPublishedPages(true);
        parent::run($request);
        return Command::SUCCESS;
    }
}
