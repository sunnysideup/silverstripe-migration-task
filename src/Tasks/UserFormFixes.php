<?php

namespace Sunnysideup\MigrateData\Tasks;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\UserForms\Model\Recipient\EmailRecipient;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;
use SilverStripe\UserForms\Model\EditableFormField;
use SilverStripe\UserForms\UserForm;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\DataObject;
use Sunnysideup\DMS\Model\DMSDocument;
use Sunnysideup\DMS\Model\DMSDocumentSet;
use SilverStripe\Control\Director;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Assets\File;
use Sunnysideup\MigrateData\Tasks\MigrateDataTask;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Assets\Folder;
use SilverStripe\Versioned\Versioned;
use DNADesign\Elemental\Models\ElementalArea;
use SilverStripe\UserForms\Model\UserDefinedForm;

class UserFormFixes extends MigrateDataTask
{


    protected $title = 'Publish Userforms Models';

    protected $description = 'Some items in UserForms need to be saved / published - see: https://github.com/silverstripe/silverstripe-userforms/issues/804';

    protected $enabled = true;

    public function performMigration()
    {

        // EditableFormField
        $this->flushNowLine();
        $this->flushNow('fixing EditableFormField');
        $this->flushNowLine();
        $objects = EditableFormField::get();
        $parentClassName = SiteTree::class;
        $field = 'Form';
        $this->writeObjects($objects, $parentClassName, $field);

        // EmailRecipient::get()
        $this->flushNowLine();
        $this->flushNow('fixing EmailRecipient');
        $this->flushNowLine();
        $objects = EmailRecipient::get();
        $parentClassName = SiteTree::class;
        $field = 'Parent';
        $classField = 'FormClass';
        $this->writeObjects($objects, $parentClassName, $field);

        // SubmittedForm
        $this->flushNowLine();
        $this->flushNow('fixing SubmittedForm');
        $this->flushNowLine();
        $objects = SubmittedForm::get();
        $parentClassName = SiteTree::class;
        $idField = 'Parent';
        $this->writeObjects($objects, $parentClassName, $field);

        $this->flushNowLine();
        $this->flushNow('fixing UserForm');
        $this->flushNowLine();
        $objects = UserDefinedForm::get();
        foreach ($objects as $object) {
            $this->flushNow('Publishing '.$object->getTitle());
            $isPublished = $object->IsPublished();
            $object->writeToStage(Versioned::DRAFT);
            $object->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
            if (! $isPublished) {
                $object->doUnpublish();
            }
        }
    }


    protected function writeObjects($objects, $parentClassName, $field)
    {
        $classField = $field.'Class';
        $idField = $field.'ID';
        foreach ($objects as $object) {
            if ($object->hasMethod('writeToStage')) {
                $this->flushNow('... publishing: '.$object->ClassName.'.'.$object->getTitle());
                $object->writeToStage(Versioned::DRAFT);
                $object->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
            } else {
                $this->flushNow('... writing: '.$object->ClassName.'.'.$object->getTitle());
                $object->write();
            }
            $relationClassValue = $object->$classField;
            if(! $relationClassValue) {
                $relationClassValue = $object->ParentClass;
            }
            $relationIDValue = $object->$idField;
            if (class_exists($relationClassValue)) {
                $relation = $relationClassValue::get()->byID($relationIDValue);
                if ($relation) {
                    $this->flushNow('... Skipping: '.$object->ClassName.' relation => '.$relationClassValue.' WHERE ID = '.$relationIDValue);
                    continue;
                } else {
                    $this->flushNow('... ERROR: : '.$object->ClassName.' relation => could not find: '.$relationClassValue.' WHERE ID = '.$relationIDValue, 'error');
                }
            } else {
                $this->flushNow('... ERROR: : '.$relationClassValue.' class does not exist, this should be set in the following field: '.$classField.' or ParentClass', 'error');
            }
            $pageID = $object->$field;
            if(! $pageID) {
                $pageID = $object->$idField;
                if(! $pageID) {
                    $pageID = $object->ParentID;
                }
            }
            if ($pageID) {
                $page = $parentClassName::get()->byID($pageID);
                if ($page) {
                    $this->flushNow('... fixing '.$object->getTitle());
                    $object->$classField = $page->ClassName;
                    $object->write();
                    $this->flushNow('... FIXING: setting '.$relationClassValue.' WHERE ID = '.$relationIDValue.' to '.$page->ClassName, 'repaired');
                } else {
                    $this->flushNow('... Skipping page (should extend '.$parentClassName.') with ID: '.$pageID.' as it could not be found.');
                }
            } else {
                $this->flushNow('... Skipping setting class field for object as PageID field ('.$field.' and ParentID) is empty.');
            }
        }
    }
}
