<?php

namespace Sunnysideup\MigrateData\Tasks;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\DataList;
use SilverStripe\UserForms\Model\EditableFormField;
use SilverStripe\UserForms\Model\Recipient\EmailRecipient;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;
use SilverStripe\UserForms\Model\UserDefinedForm;
use SilverStripe\Versioned\Versioned;

class UserFormFixes extends MigrateDataTaskBase
{
    protected $title = 'Publish Userforms Models';

    protected $description = 'Some items in UserForms need to be saved / published - see: https://github.com/silverstripe/silverstripe-userforms/issues/804';

    protected $enabled = true;

    protected function performMigration()
    {
        // EditableFormField
        $this->flushNowLine();
        $this->flushNow('fixing EditableFormField');
        $this->flushNowLine();
        $objects = EditableFormField::get();
        $parentClassName = SiteTree::class;
        $field = 'Parent';
        $this->writeObjects($objects, $parentClassName, $field);

        // EmailRecipient::get()
        $this->flushNowLine();
        $this->flushNow('fixing EmailRecipient');
        $this->flushNowLine();
        $objects = EmailRecipient::get();
        $parentClassName = SiteTree::class;
        $field = 'Parent';
        $this->writeObjects($objects, $parentClassName, $field);

        // SubmittedForm
        $this->flushNowLine();
        $this->flushNow('fixing SubmittedForm');
        $this->flushNowLine();
        $objects = SubmittedForm::get();
        $parentClassName = SiteTree::class;
        $this->writeObjects($objects, $parentClassName, $field);

        $this->flushNowLine();
        $this->flushNow('fixing UserForm');
        $this->flushNowLine();
        $objects = UserDefinedForm::get();
        foreach ($objects as $object) {
            $this->flushNow('Publishing ' . $object->getTitle());
            $isPublished = $object->IsPublished();
            $object->writeToStage(Versioned::DRAFT);
            $object->publishRecursive();
            if (! $isPublished) {
                $object->doUnpublish();
            }
        }
    }

    /**
     * @param DataList $objects
     */
    protected function writeObjects($objects, string $parentClassName, string $field)
    {
        $classField = $field . 'Class';
        $idField = $field . 'ID';
        foreach ($objects as $object) {
            $this->flushNow('-');
            $relationClassValue = $object->{$classField};
            $relationIDValue = $object->{$idField};
            $this->writeInner($object);
            if (class_exists($relationClassValue)) {
                if ($relationIDValue) {
                    $relation = $relationClassValue::get()->byID($relationIDValue);
                    if ($relation && $relation->ClassName === $relationClassValue) {
                        $this->flushNow('... OK: ' . $object->ClassName . ' relation => ' . $relationClassValue . ' WHERE ID = ' . $relationIDValue);

                        continue;
                    }
                    $this->flushNow('... ERROR: : ' . $object->ClassName . ' relation => could not find: ' . $relationClassValue . ' WHERE ID = ' . $relationIDValue, 'error');
                    /** @var SiteTree $page */
                    $page = $parentClassName::get()->byID($relationIDValue);
                    if ($page) {
                        $this->flushNow('... FIXING ' . $object->getTitle());
                        $object->{$classField} = $page->ClassName;
                        $this->writeInner($object);
                        $this->flushNow('... FIXING: setting ' . $relationClassValue . ' WHERE ID = ' . $relationIDValue . ' to ' . $page->ClassName, 'repaired');
                    } else {
                        $this->flushNow('... Skipping page (should extend ' . $parentClassName . ') with ID: ' . $relationIDValue . ' as it could not be found.');
                    }
                } else {
                    $this->flushNow(
                        '
                        ... ERROR: : ' . $relationClassValue . ' class does not exist for ID = ' . $relationIDValue . ',
                        this should be set in the following field: ' . $idField . ' or ParentClass',
                        'error'
                    );
                }
            } else {
                $this->flushNow(
                    '
                    ... ERROR: : ' . $relationClassValue . ' class does not exist,
                    this should be set in the following field: ' . $classField . ' or ParentClass',
                    'error'
                );
            }
        }
    }

    protected function writeInner($object)
    {
        if ($object->hasMethod('writeToStage')) {
            $this->flushNow('... publishing: ' . $object->ClassName . '.' . $object->getTitle());
            $object->writeToStage(Versioned::DRAFT);
            $object->publishRecursive();
        } else {
            $this->flushNow('... writing: ' . $object->ClassName . '.' . $object->getTitle());
            $object->write();
        }
    }
}
