<?php

namespace LittleGiant\SingleObjectAdmin;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Forms\Form;
use SilverStripe\Admin\AdminRootController;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Security\Permission;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Forms\HiddenField;
use SilverStripe\CMS\Controllers\SilverStripeNavigator;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormAction;
use SilverStripe\Control\Controller;
use SilverStripe\Control\PjaxResponseNegotiator;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Security\PermissionProvider;

/**
 * Defines the Single Object Administration interface for the CMS
 *
 * @package SingleObjectAdmin
 * @author  Jeremy Bridson with help from Stevie Mayhew
 */
class SingleObjectAdmin extends LeftAndMain implements PermissionProvider
{
    private static $url_rule = '/$Action/$ID/$OtherID';
    private static $menu_icon = 'resources/vendor/littlegiant/silverstripe-singleobjectadmin/dist/images/singleobjectadmin.png';

    private static $allowed_actions = [
        'EditForm'
    ];
    
    /**
     * @config
     * @var array Allows developers to create adaptations to this plugin by giving a class name top-level uri access
     */
    private static $plugins = [];

    public function canView($member = null)
    {
        return Permission::check("CMS_ACCESS_SingleObjectAdmin");
    }

    public function providePermissions()
    {
        return [
            "CMS_ACCESS_SingleObjectAdmin" => [
                'name'     => "Access to Single Object Administration",
                'category' => 'CMS Access',
                'help'     => 'Allow use of Single Object Administration'
            ]
        ];
    }

    /**
     * @return DataObject
     */
    public function getCurrentObject()
    {
        $objectClass = $this->config()->get('tree_class');

        /** @var DataObject|Versioned $object */
        $object = $objectClass::get()->first();

        if ($object && $object->exists()) {
            return $object;
        }

        $currentStage = Versioned::get_stage();
        Versioned::set_stage('Stage');

        $object = $objectClass::create();
        $object->write();
        if ($objectClass::has_extension(Versioned::class)) {
            $object->publishRecursive();
        }

        Versioned::set_stage($currentStage);

        return $object;
    }

    /**
     * @return FieldList
     */
    public function getCMSActions()
    {
        $actions = new FieldList(FormAction::create('doSave', 'Save')->addExtraClass('btn-primary font-ic1on-save'));

        $this->extend('updateCMSActions', $actions);

        return $actions;
    }

    /**
     * @param null $id     Not used.
     * @param null $fields Not used.
     *
     * @return Form
     */
    public function getEditForm($id = null, $fields = null)
    {
        $object = $this->getCurrentObject();

        $fields = $object->getCMSFields();

        $fields->push(HiddenField::create('ID', 'ID', $object->ID));
        $fields->push($navField = new LiteralField(SilverStripeNavigator::class, $this->getSilverStripeNavigator()));
        $navField->setAllowHTML(true);

        $actions = $this->getCMSActions();
        $negotiator = $this->getResponseNegotiator();

        // Retrieve validator, if one has been setup
        if ($object->hasMethod("getCMSValidator")) {
            $validator = $object->getCMSValidator();
        } else {
            $validator = null;
        }

        $form = Form::create($this, 'EditForm', $fields, $actions, $validator)->setHTMLID('Form_EditForm');

        $form->setValidationResponseCallback(function (ValidationResult $errors) use ($negotiator, $form) {
            $request = $this->getRequest();
            if ($request->isAjax() && $negotiator) {
                $result = $form->forTemplate();
                return $negotiator->respond($request, [
                    'CurrentForm' => function () use ($result) {
                        return $result;
                    }
                ]);
            }
        });

        $form->addExtraClass('flexbox-area-grow fill-height cms-content cms-edit-form');
        $form->setAttribute('data-pjax-fragment', 'CurrentForm');

        if ($form->Fields()->hasTabSet()) {
            $form->Fields()->findOrMakeTab('Root')->setTemplate('SilverStripe\\Forms\\CMSTabSet');
        }

        $form->loadDataFrom($object);
        $form->setTemplate($this->getTemplatesWithSuffix('_EditForm'));

        // Use <button> to allow full jQuery UI styling
        $actions = $actions->dataFields();
        if ($actions) {
            /** @var FormAction $action */
            foreach ($actions as $action) {
                $action->setUseButtonTag(true);
            }
        }

        $this->extend('updateEditForm', $form);
        return $form;

    }

    public function EditForm($request = null)
    {
        return $this->getEditForm();
    }

    /**
     * Used for preview controls, mainly links which switch between different states of the page.
     *
     * @return DBHTMLText|string
     */
    public function getSilverStripeNavigator()
    {
        return $this->renderWith(SingleObjectAdmin::class . '_SilverStripeNavigator');
    }

    /**
     * @return PjaxResponseNegotiator
     */
    public function getResponseNegotiator()
    {
        $neg = parent::getResponseNegotiator();
        $controller = $this;
        $neg->setCallback('CurrentForm', function () use (&$controller) {
            return $controller->renderWith(SingleObjectAdmin::class . '_Content');
        });
        return $neg;
    }

    /**
     * @param array $data
     * @param Form  $form
     *
     * @return mixed
     */
    public function doSave($data, $form)
    {
        $objectClass = $this->config()->get('tree_class');

        /** @var DataObject|Versioned $object */
        $object = $objectClass::get()->byID($data['ID']);

        $currentStage = Versioned::get_stage();
        Versioned::set_stage('Stage');

        $controller = Controller::curr();
        if (!$object->canEdit()) {
            return $controller->httpError(403);
        }

        try {
            $form->saveInto($object);
            $object->write();
        } catch (ValidationException $e) {
            $result = $e->getResult();
            $form->loadMessagesFrom($result);

            $responseNegotiator = new PjaxResponseNegotiator([
                'CurrentForm' => function () use (&$form) {
                    return $form->forTemplate();
                },
                'default'     => function () use (&$controller) {
                    return $controller->redirectBack();
                }
            ]);
            if ($controller->getRequest()->isAjax()) {
                $controller->getRequest()->addHeader('X-Pjax', 'CurrentForm');
            }
            return $responseNegotiator->respond($controller->getRequest());
        }

        Versioned::set_stage($currentStage);
        if ($objectClass::has_extension(Versioned::class)) {
            if ($object->isPublished()) {
                $this->publish($data, $form);
            }
        }

        $link = '"' . $object->singular_name() . '"';
        $message = _t('GridFieldDetailForm.Saved', 'Saved {name} {link}', [
            'name' => $object->singular_name(),
            'link' => $link
        ]);

        $form->sessionMessage($message, 'good');
        $action = $this->edit(Controller::curr()->getRequest());

        return $action;
    }

    /**
     * @param HTTPRequest $request
     *
     * @return DBHTMLText|string
     */
    public function edit($request)
    {
        $controller = Controller::curr();
        $form = $this->EditForm($request);

        $return = $this->customise([
            'Backlink' => $controller->hasMethod('Backlink') ? $controller->Backlink() : $controller->Link(),
            'EditForm' => $form,
        ])->renderWith(SingleObjectAdmin::class . '_Content');

        if ($request->isAjax()) {
            return $return;
        }

        return $controller->customise([
            'Content' => $return,
        ]);
    }

    /**
     * @param array $data
     * @param Form $form
     */
    private function publish($data, $form)
    {
        $currentStage = Versioned::get_stage();
        Versioned::set_stage('Stage');

        $objectClass = $this->config()->get('tree_class');

        /** @var DataObject|Versioned $object */
        $object = $objectClass::get()->byID($data['ID']);

        if ($object) {
            $object->publishRecursive();
            $form->sessionMessage($object->singular_name() . ' has been saved.', 'good');
        } else {
            $form->sessionMessage('Something failed, please refresh your browser.', 'bad');
        }

        Versioned::set_stage($currentStage);
    }


    /**
     * Overridden to avoid the BadMethodCallException exception when a url_segment is undefined
     *
     * @param string $action
     *
     * @return string
     * @throws \BadMethodCallException
     */
    public function Link($action = null)
    {
        $allowedPlugins = $this->config()->get('plugins');
        $allowedPlugins[] = SingleObjectAdmin::class;
        
        $this->extend('updateAllowedPlugins', $allowedPlugins);

        // LeftAndMain methods have a top-level uri access
        if (in_array(static::class, $allowedPlugins)) {
            $segment = '';
        } else {
            // Get url_segment
            $segment = $this->config()->get('url_segment');
            if (!$segment) {
                throw new \BadMethodCallException("SingleObjectAdmin subclasses must have url_segment");
            }
        }

        $link = Controller::join_links(AdminRootController::admin_url(), $segment, '/', "$action");
        $this->extend('updateLink', $link);
        return $link;
    }
}
