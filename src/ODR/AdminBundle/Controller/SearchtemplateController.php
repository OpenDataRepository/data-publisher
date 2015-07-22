<?php

/**
* Open Data Repository Data Publisher
* SearchTemplate Controller
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The searchtemplate controller handles everything required to
* design the ShortResults version of DataRecords.
*
* TODO - search_ajax.html.twig still fires off ajax requests to re-render pieces of the page after every change made 
* TODO - e.g. changing datafield width on searchtemplate just reloads the datafield, instead of using the ajax return to update via javascript
*/

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeElementField;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RenderPluginFields;
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\RenderPluginMap;
use ODR\AdminBundle\Entity\RenderPluginOptions;
use ODR\AdminBundle\Entity\UserPermissions;
// Forms
use ODR\AdminBundle\Form\DecimalValueForm;
use ODR\AdminBundle\Form\IntegerValueForm;
use ODR\AdminBundle\Form\DatafieldsForm;
use ODR\AdminBundle\Form\DatatypeForm;
use ODR\AdminBundle\Form\UpdateDataFieldsForm;
use ODR\AdminBundle\Form\UpdateDataTypeForm;
use ODR\AdminBundle\Form\UpdateThemeElementForm;
use ODR\AdminBundle\Form\UpdateThemeDatafieldForm;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class SearchtemplateController extends ODRCustomController
{

   /**
     * Loads and returns the SearchTemplate HTML for this DataType/Theme pair.
     * 
     * @param integer $datatype_id The database of the DataType to be modified.
     * @param integer $theme       The database id of the Theme to be modified.
     * 
     * @return a Symfony JSON response containing HTML
     */
    public function designAction($datatype_id, $theme_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ( $theme == null )
                return parent::deletedEntityError('Theme');

            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( $datatype == null )
                return parent::deletedEntityError('Datatype');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // Ensure this datatype has a theme element
            $repo_theme_element = $em->getRepository('ODRAdminBundle:ThemeElement');
            $theme_element = $repo_theme_element->findOneBy( array('theme' => $theme->getId(), 'dataType' => $datatype->getId()) );
            if ($theme_element === null) {
                $datatype->setHasShortresults(true);
                $em->persist($datatype);

                parent::ODR_addThemeElementEntry($em, $user, $datatype, $theme);
                $em->flush();
            }

            // Render the design form
            $return['d'] = array(
                'datatype_id' => $datatype_id,
                'html' => self::GetDisplayData($request, $datatype_id, $theme_id)
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x38288399 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }


    /**
     * Adds a new ThemeElement to this DataType/Theme pair.
     * 
     * @param integer $datatype_id The database id of the DataType receiving the new ThemeElement.
     * @param integer $theme_id    The database id of the Theme receiving the new ThemeElement.
     * @param Request $request
     * 
     * @return an empty Symfony JSON response, unless an error occurred
     */
    public function addthemeelementAction($datatype_id, $theme_id, Request $request)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();

            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $datatype = $repo_datatype->find($datatype_id);
            if ( $datatype == null )
                return parent::deletedEntityError('Datatype');

            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ( $theme == null )
                return parent::deletedEntityError('Theme');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // Create and attach a new ThemeElement entity
            $theme_element = parent::ODR_addThemeElementEntry($em, $user, $datatype, $theme);

            // Save changes
            $em->flush();
            $em->refresh($theme_element);

            // Return the new theme element's id
            $return['d'] = array(
                'theme_element_id' => $theme_element->getId(),
            );

            // Schedule the cache for an update
            $options = array();
            $options['mark_as_updated'] = true;
//            $options['force_shortresults_recache'] = true;    // no harm in leaving an empty theme element?

            parent::updateDatatypeCache($datatype->getId(), $options);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x831455029 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes a ThemeElement from a DataType.
     * TODO - change whatever calls this to call deletethemeelementAction() in DisplayTemplate instead?
     * 
     * @param integer $theme_element_id The database id of the ThemeElement to delete.
     * @param Request $request
     * 
     * @return an empty Symfony JSON response, unless an error occurred
     */
    public function deletethemeelementAction($theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Current User
            $em = $this->getDoctrine()->getManager();
            $repo_theme_element = $em->getRepository('ODRAdminBundle:ThemeElement');
            $repo_theme_element_field = $em->getRepository('ODRAdminBundle:ThemeElementField');
            $repo_theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField');

            // Grab the theme element from the repository
            $theme_element = $repo_theme_element->find($theme_element_id);
            $em->refresh($theme_element);
            if ( $theme_element == null )
                return parent::deletedEntityError('ThemeElement');

            $theme = $theme_element->getTheme();
            if ( $theme == null )
                return parent::deletedEntityError('Theme');

            $datatype = $theme_element->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('Datatype');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Determine if the theme element holds anything
            $theme_element_fields = $repo_theme_element_field->findBy( array('themeElement' => $theme_element_id) );

            $has_fields = false;
            $has_child = false;
            foreach ($theme_element_fields as $tef) {
                if ($tef->getDataType() !== null) {
                    $has_child = true;
                    break;
                }
                else if ($tef->getDataFields() !== null) {
                    $datafield = $tef->getDataFields();
                    $theme_datafield = $repo_theme_datafield->findOneBy( array('dataFields' => $datafield->getId(), 'theme' => $theme->getId()) );
                    if ($theme_datafield->getActive() == true) {
                        $has_fields = true;
                        break;
                    }
                }
            }

            if ($has_fields || $has_child) {
                // Notify of inability to remove this theme element
//                throw new \Exception("This ThemeElement can't be removed...it still contains datafields or a datatype!");

                $return['r'] = 1;
                $return['t'] = 'ex';
                $return['d'] = "This ThemeElement can't be removed...it still contains datafields or a datatype!";
            }
            else {
                // Delete the theme element
                $em->remove($theme_element);
                $em->flush();
/*
                $theme_element = $repo_theme_element->findOneBy( array('dataType' => $datatype->getId(), 'theme' => $theme->getId()) );
                if ($theme_element == null) {
                    $datatype->setHasShortresults(false);
                    $em->persist($datatype);
                    $em->flush();
                }
*/
                // Schedule the cache for an update
                $options = array();
                $options['mark_as_updated'] = true;
                $options['force_shortresults_recache'] = true;

                parent::updateDatatypeCache($datatype->getId(), $options);
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x774592699 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


   /**
     * Attach a DataField created in DisplayTemplate Controller to this specific ShortResults theme.
     * 
     * @param integer $datafield_id     The database id of the DataField to attach.
     * @param integer $theme_element_id The database id of the ThemeElement to attach the DataField to.
     * @param Request $request
     * 
     * @return an empty Symfony JSON response, unless an error occurred
     */
    public function addfieldAction($datafield_id, $theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_theme_element = $em->getRepository('ODRAdminBundle:ThemeElement');
            $repo_theme_element_field = $em->getRepository('ODRAdminBundle:ThemeElementField');
            $repo_theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField');

            $datafield = $repo_datafield->find($datafield_id);
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');
            $datatype = $datafield->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('Datatype');

            $theme_element = $repo_theme_element->find($theme_element_id);
            if ( $theme_element == null )
                return parent::deletedEntityError('ThemeElement');

            // Grab the theme this theme_element belongs to
            $theme = $theme_element->getTheme();
            if ( $theme == null )
                return parent::deletedEntityError('Theme');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

//print 'wanting to add a datafield to theme_element '.$theme_element_id."\n";
            // Determine whether a theme element attached to this theme used to contain this datafield
            $found = false;
            $theme_elements = $repo_theme_element->findBy( array('dataType' => $datatype->getId(), 'theme' => $theme->getId()) );
            foreach ($theme_elements as $te) {
//print 'theme_element: '.$te->getId()."\n";
                $theme_element_fields = $repo_theme_element_field->findBy( array('themeElement' => $te->getId()) );
                foreach ($theme_element_fields as $tef) {
//print '-- theme_element_field: '.$tef->getId()."\n";
                    if ($tef->getDataFields() !== NULL && $tef->getDataFields()->getId() == $datafield_id) {
//print '-- -- datafield: '.$tef->getDataFields()->getid()."\n";
                        $found = true;

                        if ($te->getId() != $theme_element_id) {
//print 'need to repurpose a theme_element_field entry'."\n";
                            // Update the theme_element_field entry to use the desired theme_element
                            $tef->setThemeElement($theme_element);
                            $tef->setUpdatedBy($user);
                            $em->persist($tef);
                        }
                    }
                }

                if ($found)
                    break;
            }

            // Ensure a theme_element has a theme_element_field for this datafield
            if (!$found) {
//print 'need to create a theme_element_field entry'."\n";
                $theme_element_field = parent::ODR_addThemeElementFieldEntry($em, $user, NULL, $datafield, $theme_element);
                $em->flush();
                $em->refresh($theme_element_field);
            }

            // Ensure this datafield has a theme_datafield entry for this theme
            $theme_datafield = $repo_theme_datafield->findOneBy( array('dataFields' => $datafield->getId(), 'theme' => $theme->getId()) );
            if ($theme_datafield == null) {
//print 'need to create a theme_datafield entry'."\n";
                $theme_datafield = parent::ODR_addThemeDataFieldEntry($em, $user, $datafield, $theme);
                $em->flush();
                $em->refresh($theme_datafield);
            }

            // Activate this theme_datafield entry
            $theme_datafield->setActive(1);
            $theme_datafield->setUpdatedBy($user);
            $em->persist($theme_datafield);
            $em->flush();

/*
            // Render the design form
            $return['d'] = array(
                'datatype_id' => $datatype_id,
                'html' => self::GetDisplayData($request, $datatype_id, $theme_id)
            );
*/
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x38281399 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }


   /**
     * Detach a DataField from this specific ShortResults theme, so it won't show up...TODO
     * 
     * @param integer $datafield_id     The database id of the DataField to detach.
     * @param integer $theme_element_id The database id of the ThemeElement to detach the DataField from.
     * @param Request $request
     * 
     * @return an empty Symfony JSON response, unless an error occurred
     */
    public function removefieldAction($datafield_id, $theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_theme_element = $em->getRepository('ODRAdminBundle:ThemeElement');
            $repo_theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField');


            $datafield = $repo_datafield->find($datafield_id);
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');

            $datatype = $datafield->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            // Grab the theme this theme_element belongs to
            $theme_element = $repo_theme_element->find($theme_element_id);
            if ( $theme_element == null )
                return parent::deletedEntityError('ThemeElement');

            $theme = $theme_element->getTheme();
            if ( $theme == null )
                return parent::deletedEntityError('Theme');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Grab the theme_datafield entry for this datafield and theme
            $theme_datafield = $repo_theme_datafield->findOneBy( array('dataFields' => $datafield->getId(), 'theme' => $theme->getId()) );

            // Deactivate this theme_datafield entry
            $theme_datafield->setActive(0);
            $theme_datafield->setUpdatedBy($user);
            $em->persist($theme_datafield);
            $em->flush();

/*
            // Render the design form
            $return['d'] = array(
                'datatype_id' => $datatype_id,
                'html' => self::GetDisplayData($request, $datatype_id, $theme_id)
            );
*/
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x81323499 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }


    /**
     * Updates the display order of DataFields inside a ThemeElement, and/or moves the DataField to a new ThemeElement.
     * TODO - look into merging this with the similar function in DisplayTemplate controller?
     * 
     * @param integer $initial_theme_element_id The database id of the ThemeElement the DataField was in before being moved.
     * @param integer $ending_theme_element_id  The database id of the ThemeElement the DataField is in after being moved.
     * @param Request $request
     * 
     * @return an empty Symfony JSON response, unless an error occurred.
     */
    public function datafieldorderAction($initial_theme_element_id, $ending_theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            // Grab necessary objects
            $post = $_POST;
//print_r($post);
            $em = $this->getDoctrine()->getManager();
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_theme_element_field = $em->getRepository('ODRAdminBundle:ThemeElementField');
            $repo_theme_element = $em->getRepository('ODRAdminBundle:ThemeElement');

            // Grab the first datafield just to check permissions
            $datafield = null;
            foreach ($post as $index => $datafield_id) {
                $datafield = $repo_datafield->find($datafield_id);
                break;
            }
            $datatype = $datafield->getDataType();

            $datatype->setHasShortresults(true);
            $em->persist($datatype);

            $ending_theme_element = $repo_theme_element->find($ending_theme_element_id);

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // If user has permissions, go through all of the datafields setting the order
            foreach ($post as $index => $datafield_id) {
                // Grab the ThemeElementField entry that corresponds to this datafield
                $theme_element_field = $repo_theme_element_field->findOneBy( array('dataFields' => $datafield_id, 'themeElement' => $ending_theme_element_id) );    // theme_element implies theme
                if ($theme_element_field == null) {
                    // If it doesn't exist, then the datafield got moved to the ending theme_element...locate the 
                    $theme_element_field = $repo_theme_element_field->findOneBy( array('dataFields' => $datafield_id, 'themeElement' => $initial_theme_element_id) );

                    // Update the ThemeElementField entry to use the ending theme_element
                    $theme_element_field->setThemeElement($ending_theme_element);
                }

                $theme_element_field->setDisplayOrder($index);
                $theme_element_field->setUpdatedBy($user);

                $em->persist($theme_element_field);
            }
            $em->flush();

            // Schedule the cache for an update
            $options = array();
            $options['mark_as_updated'] = true;
            $options['force_shortresults_recache'] = true;

            parent::updateDatatypeCache($datatype->getId(), $options);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x2458268302 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Saves width changes made to a DataField in it's associated ThemeDataField entity.
     * TODO - look into merging this with the similar function in DisplayTemplate controller?
     * 
     * @param integer $theme_datafield_id The database id of the ThemeDataField entity to change.
     * @param Request $request
     * 
     * @return an empty Symfony JSON Response, unless an error occurred TODO
     */
    public function savethemedatafieldAction($theme_datafield_id, Request $request)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField')->find($theme_datafield_id);
            $datatype = $theme_datafield->getDataFields()->getDataType();

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // Populate new DataFields form
            $form = $this->createForm(new UpdateThemeDatafieldForm($theme_datafield), $theme_datafield);

            if ($request->getMethod() == 'POST') {
                $form->bind($request, $theme_datafield);
                $return['t'] = "html";
                if ($form->isValid()) {
                    // Save the changes made to the datatype
                    $em->persist($theme_datafield);
                    $em->flush();

                    // Schedule the cache for an update
                    $options = array();
                    $options['mark_as_updated'] = true;
                    $options['force_shortresults_recache'] = true;

                    parent::updateDatatypeCache($datatype->getId(), $options);

                }
/*
                else {
                    throw new \Exception( parent::ODR_getErrorMessages($form) );
                }
*/
            }
/*
            $templating = $this->get('templating');
            $return['d'] = $templating->render(
                'ODRAdminBundle:Displaytemplate:theme_element_properties_form.html.twig',
                array(
                    'form' => $form->createView(),
                    'theme_element' => $theme_element,
                )
            );
*/
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
     * Reloads the search design area (effectively...could be named something different, but uses same theory as child reloads in other controllers)
     *
     * @param integer $datatype_id The database id of the DataType to re-render.
     * @param integer $theme_id    The database id of the Theme to re-render from...
     * @param Request $request
     *
     * @return a Symfony JSON response containing HTML
     */
    public function reloadchildAction($datatype_id, $theme_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $return['d'] = array(
                'datatype_id' => $datatype_id,
                'html' => self::GetDisplayData($request, $datatype_id, $theme_id, 'child'),
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x817913259' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }


    /**
     * Triggers a re-render and reload of a ThemeElement in the search design area.
     *
     * @param integer $theme_element_id The database id of the ThemeElement that needs to be re-rendered.
     * @param integer $theme_id    The database id of the Theme to re-render from...
     * @param Request $request
     *
     * @return a Symfony JSON response containing HTML
     */
    public function reloadthemeelementAction($theme_element_id, $theme_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $datatype_id = null;
            $return['d'] = array(
                'theme_element_id' => $theme_element_id,
                'html' => self::GetDisplayData($request, $datatype_id, $theme_id, 'theme_element', $theme_element_id),
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x8137326060' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }

    /**
     * Triggers a re-render and reload of a DataField in the search design area.
     *
     * @param integer $datafield_id THe database id of the DataField that needs to be re-rendered.
     * @param integer $theme_id    The database id of the Theme to re-render from...
     * @param Request $request
     *
     * @return a Symfony JSON response containing HTML
     */
    public function reloaddatafieldAction($datafield_id, $theme_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $datatype_id = null;
            $return['d'] = array(
                'datafield_id' => $datafield_id,
                'html' => self::GetDisplayData($request, $datatype_id, $theme_id, 'datafield', $datafield_id),
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x872961321 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }


    /**
     * Renders and returns the HTML for a SearchTemplate version of a DataType.
     *
     * @param Request $request
     * @param integer $datatype_id  Which datatype needs to be re-rendered...
     * @param integer $theme_id    The database id of the Theme to re-render from...
     * @param string $template_name One of 'default', 'theme_element', 'datafield'
     * @param integer $other_id     If $template_name == 'theme_element', $other_id is a theme_element id...if $template_name == 'datafield', $other_id is a datafield id
     *
     * @return string
     */
    private function GetDisplayData(Request $request, $datatype_id, $theme_id, $template_name = 'default', $other_id = null)
    {
        // Required objects
        $em = $this->getDoctrine()->getManager();
        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
        $repo_theme_element = $em->getRepository('ODRAdminBundle:ThemeElement');
        $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
        $normal_theme = $em->getRepository('ODRAdminBundle:Theme')->find(1);
        $search_theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
        $templating = $this->get('templating');

        $user = $this->container->get('security.context')->getToken()->getUser();

        $datatype = null;
        $theme_element = null;
        $datafield = null;
        if ($datatype_id !== null) {
            $datatype = $repo_datatype->find($datatype_id);
        }
        else if ($template_name == 'theme_element' && $other_id !== null) {
            $theme_element = $repo_theme_element->find($other_id);
            $datatype = $theme_element->getDataType();
        }
        else if ($template_name == 'datafield' && $other_id !== null) {
            $datafield = $repo_datafield->find($other_id);
            $datatype = $datafield->getDataType();
        }

        $indent = 0;
        $is_link = 0;
        $top_level = 1;
        $short_form = true;
/*
        if ($template_name == 'child') {
            // Determine if this is a 'child' render request for a top-level datatype
            $query = $em->createQuery(
               'SELECT dt
                FROM ODRAdminBundle:DataTree dt
                WHERE dt.is_link = 0 AND dt.deletedAt IS NULL AND dt.descendant = :datatype'
            )->setParameters( array('datatype' => $datatype_id) );
            $results = $query->getResult();

            // If query found something nothing, then it's a top-level datatype
            if ( count($results) == 0 )
                $top_level = 1;
            else
                $top_level = 0;
        }
*/
        if ($template_name == 'theme_element') {
            $is_link = 0;
            $top_level = 0; // not going to be in a situation where this equal to 1 ever?
        }


$debug = true;
$debug = false;
if ($debug)
    print '<pre>';

        $html = '';
        if ($template_name != 'datafield') {
            $normal_tree = parent::buildDatatypeTree($user, $normal_theme, $datatype, $theme_element, $em, $is_link, $top_level, $short_form, $debug, $indent);
            $search_tree = parent::buildDatatypeTree($user, $search_theme, $datatype, $theme_element, $em, $is_link, $top_level, $short_form, $debug, $indent);

if ($debug)
    print '</pre>';

            if ($template_name == 'theme_element' || $template_name == 'child') {
                // Handle reloads of the design area theme_element
                $template = 'ODRAdminBundle:Searchtemplate:search_area_fieldarea_design.html.twig';
                $html = $templating->render(
                    $template,
                     array(
                        'datatype_tree' => $search_tree,
                        'theme' => $search_theme,
                    )
                );
            }
            else {
                // Handle renders of the entire page
                $template = 'ODRAdminBundle:Searchtemplate:search_ajax.html.twig';
                $html = $templating->render(
                    $template,
                     array(
                        'normal_datatype_tree' => $normal_tree,
                        'search_datatype_tree' => $search_tree,
                        'normal_theme' => $normal_theme,
                        'search_theme' => $search_theme,
                    )
                );
            }
        }
        else {
            // Rendering a datafield doesn't require the entire tree...
            $em->refresh($datafield);
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            parent::ODR_checkThemeDataField($user, $datafield, $theme);

            $query = $em->createQuery(
               'SELECT tdf
                FROM ODRAdminBundle:ThemeDataField tdf
                WHERE tdf.dataFields = :datafield AND tdf.theme = :theme AND tdf.deletedAt IS NULL'
            )->setParameters( array('datafield' => $datafield->getId(), 'theme' => $theme->getId()) );
            $result = $query->getResult();
            $theme_datafield = $result[0];
            $em->refresh($theme_datafield);

            $html = $templating->render(
                'ODRAdminBundle:Displaytemplate:design_area_datafield.html.twig',   // TODO: why displaytemplate here?
                array(
                    'fieldtheme' => $theme_datafield,
                    'field' => $datafield,
                )
            );
        }

        return $html;
    }


    /**
     * Loads/saves a Symfony DataFields properties Form.
     * 
     * @param integer $datafield_id The database id of the DataField being modified.
     * @param integer $theme_id     The database id of the Theme that is being modified for this DataField...
     * @param Request $request
     *
     * @return a Symfony JSON response containing HTML 
     */
    public function datafieldpropertiesAction($datafield_id, $theme_id, Request $request)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            // Grag necessary objects
            $em = $this->getDoctrine()->getManager();
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');

            $em->refresh($datafield);
            $datatype = $datafield->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Get desired theme
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('id' => $theme_id) );

            // Get relevant theme_datafield entry
            $query = $em->createQuery(
               'SELECT tdf
                FROM ODRAdminBundle:ThemeDataField AS tdf
                WHERE tdf.theme = :theme AND tdf.dataFields = :datafield
                AND tdf.deletedAt IS NULL'
            )->setParameters( array('theme' => $theme->getId(), 'datafield' => $datafield->getId()) );
            $result = $query->getResult();
            $theme_datafield = $result[0];

/*
            $themes = $datafield->getThemeDataField();

            $field_theme = "";
            foreach($themes as $mytheme) {
                if($mytheme->getTheme()->getId() == $theme->getId()) {
                    $field_theme = $mytheme;
                }
            }
*/
            // Populate new DataFields form
            $datafield_form = $this->createForm(new UpdateDatafieldsForm(), $datafield);
            $theme_datafield_form = $this->createForm(new UpdateThemeDatafieldForm(), $theme_datafield);
            $templating = $this->get('templating');

            if ($request->getMethod() == 'POST') {
                // Get Symfony to validate the form
                $datafield_form->bind($request, $datafield);

                $return['t'] = "html";
                if ($datafield_form->isValid()) {
                    // Save the form
                    $em->persist($datafield);
                    $em->flush();

                    // Schedule the cache for an update
                    $options = array();
                    $options['mark_as_updated'] = true;
                    $options['force_shortresults_recache'] = true;

                    parent::updateDatatypeCache($datatype->getId(), $options);
                }
                else {
                    // Get Sizing Options for Sizing Block
                    // Get Radio Option Form if Radio Type
                    $return['d'] = $templating->render(
                        'ODRAdminBundle:Searchtemplate:datafield_properties_form.html.twig', 
                        array(
                            'datafield' => $datafield,
                            'datafield_form' => $datafield_form->createView(),
                            'theme_datafield' => $theme_datafield,
                            'theme_datafield_form' => $theme_datafield_form->createView(),
                        )
                    );
                }
            }
            else {
                $return['d'] = $templating->render(
                    'ODRAdminBundle:Searchtemplate:datafield_properties_form.html.twig', 
                    array(
                        'datafield' => $datafield,
                        'datafield_form' => $datafield_form->createView(),
                        'theme_datafield' => $theme_datafield,
                        'theme_datafield_form' => $theme_datafield_form->createView(),
                    )
                );
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x84230230 ' . $e->getMessage();
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;  
    }


    /**
     * Loads/saves a Symfony ThemeElement properties Form.
     * TODO - merge with the version of this function in DisplayTemplate?
     *
     * @param integer $theme_element_id The database id of the ThemeElement being modified.
     * @param Request $request
     * 
     * @return a Symfony JSON response containing HTML
     */
    public function themeelementpropertiesAction($theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ( $theme_element == null )
                return parent::deletedEntityError('Datatype');

            $datatype = $theme_element->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('Datatype');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // Populate new DataFields form
            $form = $this->createForm(new UpdateThemeElementForm($theme_element), $theme_element);

            if ($request->getMethod() == 'POST') {
                $form->bind($request, $theme_element);
                $return['t'] = "html";
                if ($form->isValid()) {
                    // Save the changes made to the datatype
                    $em->persist($theme_element);
                    $em->flush();

                    // Schedule the cache for an update
                    $options = array();
                    $options['mark_as_updated'] = true;
                    $options['force_shortresults_recache'] = true;

                    parent::updateDatatypeCache($datatype->getId(), $options);
                }
/*
                else {
                    throw new \Exception( parent::ODR_getErrorMessages($form) );
                }
*/
            }

            $templating = $this->get('templating');
            $return['d'] = $templating->render(
                'ODRAdminBundle:Searchtemplate:theme_element_properties_form.html.twig',
                array(
                    'form' => $form->createView(),
                    'theme_element' => $theme_element,
                )
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x8234577020 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Saves changes made to the order of a Datatype's ThemeElements.
     * TODO - merge with similar function in DisplayTemplate?
     *
     * @param Request $request
     * 
     * @return an empty Symfony JSON response, unless an error occurred.
     */
    public function themeelementorderAction(Request $request)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $post = $_POST;
//print_r($post);
//return;
            $em = $this->getDoctrine()->getManager();
            $repo_theme_element = $em->getRepository('ODRAdminBundle:ThemeElement');

            // Grab the first theme element just to check permissions
            $theme_element = null;
            foreach ($post as $index => $theme_element_id) {
                $theme_element = $repo_theme_element->find($theme_element_id);
                break;
            }
            $datatype = $theme_element->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('Datatype');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'design' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // If user has permissions, go through all of the theme elements setting the order
            foreach ($post as $index => $theme_element_id) {
                $theme_element = $repo_theme_element->find($theme_element_id);
                $em->refresh($theme_element);

                $theme_element->setDisplayOrder($index);
                $theme_element->setUpdatedBy($user);

                $em->persist($theme_element);
            }
            $em->flush();

            // Schedule the cache for an update
            $options = array();
            $options['mark_as_updated'] = true;
            $options['force_shortresults_recache'] = true;

            parent::updateDatatypeCache($datatype->getId(), $options);
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x828304502 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

}
