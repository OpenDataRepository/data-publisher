<?php

/**
 * Open Data Repository Data Publisher
 * Theme Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This controller handles creation, modification, and deletion
 * of layouts and theme-related data.
 *
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeElementMeta;
use ODR\AdminBundle\Entity\ThemeMeta;
use ODR\OpenRepository\UserBundle\Entity\User;
// Forms
use ODR\AdminBundle\Form\UpdateThemeDatafieldForm;
use ODR\AdminBundle\Form\UpdateThemeDatatypeForm;
use ODR\AdminBundle\Form\UpdateThemeElementForm;
use ODR\AdminBundle\Form\UpdateThemeForm;
// Symfony
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class ThemeController extends ODRCustomController
{
    /**
     * Creates a new Theme entity.
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function addthemeAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            throw new \Exception('DISABLED PENDING SECOND HALF OF THEME REWORK');
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x39686212 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes an existing Theme entity.
     *
     * @param integer $theme_id
     * @param Request $request
     *
     * @return Response
     */
    public function deletethemeAction($theme_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab entity manager and repositories
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($theme == null)
                return parent::deletedEntityError('Theme');

            $datatype = $theme->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // TODO - don't allow deletion of master theme
            // TODO - change is_default to a different theme upon theme deletion?

            $entities_to_remove = array();

            // Delete all the theme elements
            $theme_elements = $theme->getThemeElements();
            foreach ($theme_elements as $theme_element) {
                /** @var ThemeElement $theme_element */

                // Need to delete any theme_datatype entries in this theme_element
                $theme_datatypes = $theme_element->getThemeDataType();
                foreach ($theme_datatypes as $theme_datatype) {
                    /** @var ThemeDataType $theme_datatype */
                    $theme_datatype->setDeletedBy($user);
                    $em->persist($theme_datatype);

                    $entities_to_remove[] = $theme_datatype;
                }

                // Need to delete any theme_datafield entries in this theme_element
                $theme_datafields = $theme_element->getThemeDataFields();
                foreach ($theme_datafields as $theme_datafield) {
                    /** @var ThemeDataField $theme_datafield */
                    $theme_datafield->setDeletedBy($user);
                    $em->persist($theme_datafield);

                    $entities_to_remove[] = $theme_datafield;
                }

                // Save who is deleting this theme_element
                $theme_element->setDeletedBy($user);
                $em->persist($theme_element);

                // Also delete the meta entry
                $theme_element_meta = $theme_element->getThemeElementMeta();
                $entities_to_remove[] = $theme_element_meta;
                $entities_to_remove[] = $theme_element;
            }

            // Finally, delete the theme and its associated meta entry
            $theme->setDeletedBy($user);
            $em->persist($theme);

            $theme_meta = $theme->getThemeMeta();
            $entities_to_remove[] = $theme_meta;
            $entities_to_remove[] = $theme;


            // ----------------------------------------
            // Commit deletes
            $em->flush();
            foreach ($entities_to_remove as $entity)
                $em->remove($entity);
            $em->flush();

            // TODO - updating cached versions
            // TODO - what to return?
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x392268283 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Loads/saves an ODR Theme properties form.
     *
     * @param integer $theme_id
     * @param Request $request
     *
     * @return Response
     */
    public function themepropertiesAction($theme_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($theme == null)
                return parent::deletedEntityError('Theme');

            $datatype = $theme->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('DataType');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Populate new Theme form
            $submitted_data = new ThemeMeta();
            $theme_form = $this->createForm(new UpdateThemeForm($theme), $submitted_data);

            if ($request->getMethod() == 'POST') {
                $theme_form->bind($request, $submitted_data);

$theme_form->addError( new FormError('do not save') );

                if ( $theme_form->isValid() ) {
                    // If a value in the form changed, create a new DataTree entity to store the change
                    $properties = array(
                        'templateName' => $submitted_data->getTemplateName(),
                        'templateDescription' => $submitted_data->getTemplateDescription(),
                        'isDefault' => $submitted_data->getIsDefault(),
                    );
                    parent::ODR_copyThemeMeta($em, $user, $theme, $properties);

                    // TODO - Schedule the cache for an update?
                    $options = array();
                    $options['mark_as_updated'] = true;
//                    parent::updateDatatypeCache($datatype->getId(), $options);

                }
                else {
                    // Form validation failed
                    // TODO - fix parent::ODR_getErrorMessages() to be consistent enough to use
                    $return['r'] = 1;
                    $errors = $theme_form->getErrors();

                    $error_str = '';
                    foreach ($errors as $num => $error)
                        $error_str .= 'ERROR: '.$error->getMessage()."\n";

                    throw new \Exception($error_str);
                }
            }

            if ( $request->getMethod() == 'GET' ) {
                // Create the form objects
                $theme_meta = $theme->getThemeMeta();
                $theme_form = $this->createForm(new UpdateThemeForm($theme), $theme_meta);

                // Return the slideout html
                $templating = $this->get('templating');
                $return['d'] = $templating->render(
                    'ODRAdminBundle:Theme:theme_properties_form.html.twig',
                    array(
                        'theme' => $theme,
                        'theme_form' => $theme_form->createView(),
                    )
                );
            }

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x392125700 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Adds a new ThemeElement entity to the current layout.
     *
     * @param integer $theme_id  Which theme to add this theme_element to
     * @param Request $request
     *
     * @return Response
     */
    public function addthemeelementAction($theme_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($theme == null)
                return parent::deletedEntityError('Theme');

            $datatype = $theme->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Create a new theme element entity
            /** @var Theme $theme */
            $data = parent::ODR_addThemeElementEntry($em, $user, $datatype, $theme);
            /** @var ThemeElement $theme_element */
            $theme_element = $data['theme_element'];
            /** @var ThemeElementMeta $theme_element_meta */
//            $theme_element_meta = $data['theme_element_meta'];

            // Save changes
            $em->flush();

            // Return the new theme element's id
            $return['d'] = array(
                'theme_element_id' => $theme_element->getId(),
            );

            // TODO - update cached version directly?
            // Schedule the cache for an update
            $options = array();
            $options['mark_as_updated'] = true;
            parent::updateDatatypeCache($datatype->getId(), $options);
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x831225029 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes a ThemeElement entity from the current layout.
     *
     * @param integer $theme_element_id
     * @param Request $request
     *
     * @return Response
     */
    public function deletethemeelementAction($theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Current User
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // Grab the theme element from the repository
            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            $em->refresh($theme_element);
            if ($theme_element == null)
                return parent::deletedEntityError('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme == null)
                return parent::deletedEntityError('Theme');

            $datatype = $theme->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            $entities_to_remove = array();

            // Don't allow deletion of theme_element if it still has "stuff" in it
            $theme_datatypes = $theme_element->getThemeDataType();
            $theme_datafields = $theme_element->getThemeDataFields();

            if ( count($theme_datatypes) > 0 || count($theme_datafields) > 0 ) {
                // TODO - test that this works as expected...

                $return['r'] = 1;
                $return['t'] = 'ex';
                $return['d'] = "This ThemeElement can't be removed...it still contains datafields or a datatype!";
            }
            else {
                // Need to delete any theme_datatype entries in this theme_element
                foreach ($theme_datatypes as $theme_datatype) {
                    /** @var ThemeDataType $theme_datatype */
                    $entities_to_remove[] = $theme_datatype;    // TODO - does ThemeDatatype need a 'deletedBy' column?
                }

                // Need to delete any theme_datafield entries in this theme_element
                foreach ($theme_datafields as $theme_datafield) {
                    /** @var ThemeDataField $theme_datafield */
                    $entities_to_remove[] = $theme_datafield;    // TODO - does ThemeDatafield need a 'deletedBy' column?
                }

                // Save who is deleting this theme_element
                $theme_element->setDeletedBy($user);
//                $em->persist($theme_element);

                // Also delete the meta entry
                $theme_element_meta = $theme_element->getThemeElementMeta();

                $entities_to_remove[] = $theme_element_meta;
                $entities_to_remove[] = $theme_element;

/*
                // Commit deletes
                $em->flush();
                foreach ($entities_to_remove as $entity)
                    $em->remove($entity);
                $em->flush();
*/

                // TODO - directly update cached version?
                // Schedule the cache for an update
                $options = array();
                $options['mark_as_updated'] = true;
                parent::updateDatatypeCache($datatype->getId(), $options);
            }

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x77392699 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Loads/saves an ODR ThemeElement properties form.
     *
     * @param integer $theme_element_id
     * @param Request $request
     *
     * @return Response
     */
    public function themeelementpropertiesAction($theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                return parent::deletedEntityError('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme == null)
                return parent::deletedEntityError('Theme');

            $datatype = $theme->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Populate new ThemeElement form
            $submitted_data = new ThemeElementMeta();
            $theme_element_form = $this->createForm(new UpdateThemeElementForm($theme), $submitted_data);

            if ($request->getMethod() == 'POST') {
                $theme_element_form->bind($request, $submitted_data);

$theme_element_form->addError( new FormError('do not save') );

                if ( $theme_element_form->isValid() ) {
                    // If a value in the form changed, create a new DataTree entity to store the change
                    $properties = array(
                        'cssWidthMed' => $submitted_data->getCssWidthMed(),
                        'cssWidthXL' => $submitted_data->getCssWidthXL(),
                    );
                    parent::ODR_copyThemeElementMeta($em, $user, $theme_element, $properties);

                    // TODO - Schedule the cache for an update?
                    $options = array();
                    $options['mark_as_updated'] = true;
//                    parent::updateDatatypeCache($datatype->getId(), $options);

                }
                else {
                    // Form validation failed
                    // TODO - fix parent::ODR_getErrorMessages() to be consistent enough to use
                    $return['r'] = 1;
                    $errors = $theme_element_form->getErrors();

                    $error_str = '';
                    foreach ($errors as $num => $error)
                        $error_str .= 'ERROR: '.$error->getMessage()."\n";

                    throw new \Exception($error_str);
                }
            }

            if ( $request->getMethod() == 'GET' ) {
                // Create the form objects
                $theme_element_meta = $theme_element->getThemeElementMeta();
                $theme_element_form = $this->createForm(new UpdateThemeElementForm($theme), $theme_element_meta);

                // Return the slideout html
                $templating = $this->get('templating');
                $return['d'] = $templating->render(
                    'ODRAdminBundle:Theme:theme_element_properties_form.html.twig',
                    array(
                        'theme' => $theme,
                        'theme_form' => $theme_element_form->createView(),
                    )
                );
            }

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x216225700 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Updates the display order of ThemeElements inside the current layout.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function themeelementorderAction(Request $request)
    {
        // from DisplaytemplateController
    }


    /**
     * Attaches a datafield to the specified theme element.
     *
     * @param integer $datafield_id
     * @param integer $theme_element_id
     * @param Request $request
     *
     * @return Response
     */
    public function attachdatafieldAction($datafield_id, $theme_element_id, Request $request)
    {
        // from SearchtemplateController, most likely
    }


    /**
     * Detaches a datafield to the specified theme element.
     *
     * @param integer $datafield_id
     * @param integer $theme_element_id
     * @param Request $request
     *
     * @return Response
     */
    public function detachdatafieldAction($datafield_id, $theme_element_id, Request $request)
    {
        // from SearchtemplateController, most likely
    }


    /**
     * Updates the display order of DataFields inside a ThemeElement, and/or moves the DataField to a new ThemeElement.
     *
     * @param integer $initial_theme_element_id
     * @param integer $ending_theme_element_id
     * @param Request $request
     *
     * @return Response
     */
    public function datafieldorderAction($initial_theme_element_id, $ending_theme_element_id, Request $request)
    {
        // from DisplaytemplateController
    }


    /**
     * Loads/saves an ODR ThemeDatafield properties form.
     *
     * @param integer $theme_datafield_id
     * @param Request $request
     *
     * @return Response
     */
    public function savethemedatafieldAction($theme_datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ThemeDataField $theme_datafield */
            $theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField')->find($theme_datafield_id);
            if ($theme_datafield == null)
                return parent::deletedEntityError('ThemeDatafield');

            $datafield = $theme_datafield->getDataField();
            if ($datafield == null)
                return parent::deletedEntityError('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Populate new ThemeDataField form
            $submitted_data = new ThemeDataField();
            $theme_datafield_form = $this->createForm(new UpdateThemeDatafieldForm($theme_datafield), $submitted_data);

            $widths = array('med_width_old' => $theme_datafield->getCssWidthMed(), 'xl_width_old' => $theme_datafield->getCssWidthXL());

            if ($request->getMethod() == 'POST') {
                $theme_datafield_form->bind($request, $submitted_data);

$theme_datafield_form->addError( new FormError('do not save') );

                if ($theme_datafield_form->isValid()) {
                    // Save all changes made via the form
                    $properties = array(
                        'cssWidthMed' => $submitted_data->getCssWidthMed(),
                        'cssWidthXL' => $submitted_data->getCssWidthXL(),
                    );
                    $new_theme_datafield = parent::ODR_copyThemeDatafield($em, $user, $theme_datafield, $properties);

                    $widths['med_width_current'] = $new_theme_datafield->getCssWidthMed();
                    $widths['xl_width_current'] = $new_theme_datafield->getCssWidthXL();

                    // TODO - Schedule the cache for an update?
                    $options = array();
                    $options['mark_as_updated'] = true;
//                    parent::updateDatatypeCache($datatype->getId(), $options);
                }
                else {
                    // Form validation failed
                    // TODO - fix parent::ODR_getErrorMessages() to be consistent enough to use
                    $return['r'] = 1;
                    $errors = $theme_datafield_form->getErrors();

                    $error_str = '';
                    foreach ($errors as $num => $error)
                        $error_str .= 'ERROR: '.$error->getMessage()."\n";

                    throw new \Exception($error_str);
                }
            }

            // Don't need to return a form object...it's loaded with the regular datafield properties form
            $return['widths'] = $widths;
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x82399100 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Loads/saves an ODR ThemeDatatype properties form.
     *
     * @param integer $theme_datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function savethemedatatypeAction($theme_datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ThemeDataType $theme_datatype */
            $theme_datatype = $em->getRepository('ODRAdminBundle:ThemeDataType')->find($theme_datatype_id);
            if ($theme_datatype == null)
                return parent::deletedEntityError('ThemeDatatype');

            $datatype = $theme_datatype->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Populate new ThemeDataField form
            $submitted_data = new ThemeDataType();
            $theme_datatype_form = $this->createForm(new UpdateThemeDatatypeForm($theme_datatype), $submitted_data);

            if ($request->getMethod() == 'POST') {
                $theme_datatype_form->bind($request, $submitted_data);

$theme_datatype_form->addError( new FormError('do not save') );

                if ($theme_datatype_form->isValid()) {
                    // Save all changes made via the form
                    $properties = array(
                        'display_type' => $submitted_data->getDisplayType(),
                    );
                    parent::ODR_copyThemeDatatype($em, $user, $theme_datatype, $properties);

                    // TODO - Schedule the cache for an update?
                    $options = array();
                    $options['mark_as_updated'] = true;
//                    parent::updateDatatypeCache($datatype->getId(), $options);
                }
                else {
                    // Form validation failed
                    // TODO - fix parent::ODR_getErrorMessages() to be consistent enough to use
                    $return['r'] = 1;
                    $errors = $theme_datatype_form->getErrors();

                    $error_str = '';
                    foreach ($errors as $num => $error)
                        $error_str .= 'ERROR: '.$error->getMessage()."\n";

                    throw new \Exception($error_str);
                }
            }

            // TODO - what to return
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x39981500 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
