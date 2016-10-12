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
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
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
     * Loads the html wrapper for selecting which derivative theme to design, or for adding new derivative themes,
     *  or for deleting existing derivative themes.
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function designAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
                return parent::permissionDeniedError('design');
            // --------------------

            // Grab this datatype in array format
            $query = $em->createQuery(
                'SELECT dt, dtm
                FROM ODRAdminBundle:DataType AS dt
                JOIN dt.dataTypeMeta AS dtm
                WHERE dt.id = :datatype_id'
            )->setParameters( array('datatype_id' => $datatype_id) );
            $results = $query->getArrayResult();

            $datatype = array();
            foreach ($results as $result) {
                $datatype = $result;
                $datatype['dataTypeMeta'] = $datatype['dataTypeMeta'][0];
            }

            // Render and return the wrapper HTML
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Theme:design_wrapper.html.twig',
                    array(
                        'datatype' => $datatype,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x68662121 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Loads arrays for making a list of derivative themes for the specified datatype
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $datatype_id
     *
     * @return Response
     */
    public function loadThemeListAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
                return parent::permissionDeniedError('design');
            // --------------------

            // Grab this datatype in array format
            $query = $em->createQuery(
               'SELECT dt, dtm
                FROM ODRAdminBundle:DataType AS dt
                JOIN dt.dataTypeMeta AS dtm
                WHERE dt.id = :datatype_id'
            )->setParameters( array('datatype_id' => $datatype_id) );
            $results = $query->getArrayResult();

            $datatype = array();
            foreach ($results as $result) {
                $datatype = $result;
                $datatype['dataTypeMeta'] = $datatype['dataTypeMeta'][0];
            }

//print_r($datatype);  exit();

            // Grab all non-master themes for this datatype
            $query = $em->createQuery(
               'SELECT t, tm, t_cb
                FROM ODRAdminBundle:Theme AS t
                JOIN t.themeMeta AS tm
                JOIN t.createdBy AS t_cb
                WHERE t.dataType = :datatype_id
                AND t.deletedAt IS NULL AND tm.deletedAt IS NULL'
            )->setParameters( array('datatype_id' => $datatype_id) );
            $results = $query->getArrayResult();

            $theme_list = array();
            foreach ($results as $num => $result) {
                $theme_list[$num] = $result;
                $theme_list[$num]['themeMeta'] = $theme_list[$num]['themeMeta'][0];
                $theme_list[$num]['createdBy'] = parent::cleanUserData( $theme_list[$num]['createdBy'] );
            }

//print_r($theme_list);  exit();

            // Render and return the wrapper HTML
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Theme:theme_list.html.twig',
                    array(
                        'datatype' => $datatype,
                        'theme_list' => $theme_list,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x66156132 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Creates a new Theme entity.
     *
     * @param integer $datatype_id
     * @param string $theme_type
     * @param Request $request
     *
     * @return Response
     */
    public function addthemeAction($datatype_id, $theme_type, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
                return parent::permissionDeniedError('design');
            // --------------------

            if ( !($theme_type == 'derivative' || $theme_type == 'search_results' || $theme_type == 'table') )
                throw new \Exception('Invalid theme type');


            // ----------------------------------------
            // Determine whether this theme should be set as default
            $query = $em->createQuery(
               'SELECT t
                FROM ODRAdminBundle:Theme AS t
                WHERE t.dataType = :datatype_id AND t.themeType = :theme_type
                AND t.deletedAt IS NULL'
            )->setParameters( array('datatype_id' => $datatype->getId(), 'theme_type' => $theme_type) );
            $results = $query->getArrayResult();

            // If the datatype already has a theme for this themeType (not counting the new one), then the new theme shouldn't be set as default
            $is_default = true;
            if ( count($results) > 0 )
                $is_default = false;

            // Create a new Theme entity
            $theme = new Theme();
            $theme->setDataType($datatype);
            $theme->setThemeType($theme_type);

            $theme->setCreatedBy($user);
            $theme->setUpdatedBy($user);

            $em->persist($theme);
            $em->flush();
            $em->refresh($theme);

            // Create a new ThemeMeta entity
            $theme_meta = new ThemeMeta();
            $theme_meta->setTheme($theme);
            $theme_meta->setTemplateName('');
            $theme_meta->setTemplateDescription('');
            $theme_meta->setIsDefault($is_default);

            $theme_meta->setCreatedBy($user);
            $theme_meta->setUpdatedBy($user);

            $em->persist($theme_meta);

            // Create a new default Theme Element for the theme
            parent::ODR_addThemeElement($em, $user, $theme);

            // Ensure that the datatype knows it has this particular theme type available to it
            if ($theme_type == 'search_results' && $datatype->getHasShortresults() == false) {
                $datatype->setHasShortresults(true);
                $em->persist($datatype);
            }
            else if ($theme_type == 'table' && $datatype->getHasTextresults() == false) {
                $datatype->setHasTextresults(true);
                $em->persist($datatype);
            }

            // Save all changes
            $em->flush();

            // Ensure the datatype is aware of its new theme
            parent::tmp_updateDatatypeCache($em, $datatype, $user);

            // Theme::design_wrapper.html.twig will reload the theme list
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
     * Loads complete layout data for a specified theme
     *
     * @param integer $theme_id
     * @param Request $request
     *
     * @return Response
     */
    public function loadthemeAction($theme_id, Request $request)
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
            if ($datatype->getDeletedAt() !== null)
                return parent::deletedEntityError('Datatype');

            /** @var Theme $master_theme */
            $master_theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            if ($master_theme == null)
                return parent::deletedEntityError('Theme');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            if ($theme->getThemeType() == 'master')
                throw new \Exception('ThemeController::loadthemeAction() attempted to load a "master" theme');


            // ----------------------------------------
            // Attempt to load theme data from memcached...
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            // Always bypass cache in dev mode?
            $bypass_cache = false;
            if ($this->container->getParameter('kernel.environment') === 'dev')
                $bypass_cache = true;

            // Going to need this a lot...
            $datatree_array = parent::getDatatreeArray($em, $bypass_cache);


            // ----------------------------------------
            // Determine which datatypes/childtypes to load from the cache
            $include_links = true;
            if ($theme->getThemeType() == 'table' || $theme->getThemeType() == 'search_results')
                $include_links = false;

            $associated_datatypes = parent::getAssociatedDatatypes($em, array($datatype->getId()), $include_links);

//print '<pre>'.print_r($associated_datatypes, true).'</pre>'; exit();

            // Grab the cached versions of all of the associated datatypes, and store them all at the same level in a single array
            $datatype_array = array();
            foreach ($associated_datatypes as $num => $dt_id) {
                $datatype_data = parent::getRedisData(($redis->get($redis_prefix.'.cached_datatype_'.$dt_id)));
                if ($bypass_cache || $datatype_data == null)
                    $datatype_data = parent::getDatatypeData($em, $datatree_array, $dt_id, $bypass_cache);

                foreach ($datatype_data as $dt_id => $data)
                    $datatype_array[$dt_id] = $data;
            }

//print '<pre>'.print_r($datatype_array, true).'</pre>'; exit();



            // ----------------------------------------
            // Render the HTML for modifying this derivative theme
            $return['d'] = array(
                'html' => self::GetDisplayData($datatype->getId(), $theme->getId(), 'default', $datatype->getId(), $request)
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x61231511 ' . $e->getMessage();
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
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            $theme_type = $theme->getThemeType();
            if ($theme_type == 'master')
                throw new \Exception('Unable to directly delete "master" theme...delete the Datatype instead');

            // TODO - change is_default to a different theme upon theme deletion?

            // Delete the theme and its associated meta entry
            $theme->setDeletedBy($user);
            $em->persist($theme);

            $theme_meta = $theme->getThemeMeta();
            $em->remove($theme);
            $em->remove($theme_meta);

            $em->flush();

            if ($theme_type == 'search_results') {
                $themes = $em->getRepository('ODRAdminBundle:Theme')->findBy( array('dataType' => $datatype->getId(), 'themeType' => $theme_type) );
                if ( count($themes) == 0 ) {
                    $datatype->setHasShortresults(false);
                    $em->persist($datatype);
                }
            }
            else if ($theme_type == 'table') {
                $themes = $em->getRepository('ODRAdminBundle:Theme')->findBy( array('dataType' => $datatype->getId(), 'themeType' => $theme_type) );
                if ( count($themes) == 0 ) {
                    $datatype->setHasTextresults(false);
                    $em->persist($datatype);
                }
            }

            // next function call will flush...

            // TODO - updating cached versions
            parent::tmp_updateDatatypeCache($em,$datatype, $user);
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
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Populate new Theme form
            $submitted_data = new ThemeMeta();
            $theme_form = $this->createForm(UpdateThemeForm::class, $submitted_data);

            $theme_form->handleRequest($request);

            if ($theme_form->isSubmitted()) {

//$theme_form->addError( new FormError('do not save') );

                if ($theme_form->isValid()) {
                    // Deal with changes to default status...
                    $theme_meta = $theme->getThemeMeta();
                    if ($theme_meta->getIsDefault() == true && $submitted_data->getIsDefault() == false) {
                        // ...if theme_meta is currently true, then submitted data will be false because the checkbox is disabled in the form...set it back to true
                        $submitted_data->setIsDefault(true);
                    }
                    else if ($theme_meta->getIsDefault() == false && $submitted_data->getIsDefault() == true) {
                        // ...if this theme_meta's is_default got set to true, then go through the rest of the themes of this theme_type and set them to not be default
                        /** @var Theme[] $other_themes */
                        $other_themes = $em->getRepository('ODRAdminBundle:Theme')->findBy( array('dataType' => $datatype->getId(), 'themeType' => $theme->getThemeType()) );
                        foreach ($other_themes as $other_theme) {
                            if ($other_theme->getId() !== $theme->getId())
                                parent::ODR_copyThemeMeta($em, $user, $other_theme, array('isDefault' => false));
                        }
                    }

                    // If a value in the form changed, create a new ThemeMeta entity to store the change
                    $properties = array(
                        'templateName' => $submitted_data->getTemplateName(),
                        'templateDescription' => $submitted_data->getTemplateDescription(),
                        'isDefault' => $submitted_data->getIsDefault(),
                    );
                    parent::ODR_copyThemeMeta($em, $user, $theme, $properties);

                    // TODO - Schedule the cache for an update?
                    $update_datatype = true;
                    parent::tmp_updateThemeCache($em, $theme, $user, $update_datatype);
                }
                else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($theme_form);
                    throw new \Exception($error_str);
                }
            }
            else {
                // GET request...load the actual ThemeMeta entity
                $theme_meta = $theme->getThemeMeta();
                $theme_form = $this->createForm(UpdateThemeForm::class, $theme_meta);

                // Return the slideout html
                $templating = $this->get('templating');
                $return['d'] = $templating->render(
                    'ODRAdminBundle:Theme:theme_properties_form.html.twig',
                    array(
                        'datatype' => $datatype,
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
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // Not allowed to create a new theme element for a table theme
            if ($theme->getThemeType() == 'table')
                throw new \Exception('Not allowed to have multiple theme elements in a table theme');


            // Create a new theme element entity
            /** @var Theme $theme */
            $data = parent::ODR_addThemeElement($em, $user, $theme);
            /** @var ThemeElement $theme_element */
            $theme_element = $data['theme_element'];
            /** @var ThemeElementMeta $theme_element_meta */
//            $theme_element_meta = $data['theme_element_meta'];

            // Save changes
            $em->flush();

            // Return the new theme element's id
            $return['d'] = array(
                'theme_element_id' => $theme_element->getId(),
                'datatype_id' => $datatype->getId(),
            );

            // TODO - update cached version directly?
            parent::tmp_updateThemeCache($em, $theme, $user);
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
            if ($theme_element == null)
                return parent::deletedEntityError('ThemeElement');
            $em->refresh($theme_element);

            $theme = $theme_element->getTheme();
            if ($theme == null)
                return parent::deletedEntityError('Theme');

            $datatype = $theme->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // Not allowed to delete a theme element from a table theme
            if ($theme->getThemeType() == 'table')
                throw new \Exception('Not allowed to delete a theme element from a table theme');

            $entities_to_remove = array();

            // Don't allow deletion of theme_element if it still has datafields or a child/linked datatype attached to it
            $theme_datatypes = $theme_element->getThemeDataType();
            $theme_datafields = $theme_element->getThemeDataFields();

            if ( count($theme_datatypes) > 0 || count($theme_datafields) > 0 ) {
                // TODO - allow deletion of theme elements that still have datafields or a child/linked datatype attached to them?
                $return['r'] = 1;
                $return['t'] = 'ex';
                $return['d'] = "This ThemeElement can't be removed...it still contains datafields or a datatype!";
            }
            else {
                // Save who is deleting this theme_element
                $theme_element->setDeletedBy($user);
                $em->persist($theme_element);

                // Also delete the meta entry
                $theme_element_meta = $theme_element->getThemeElementMeta();

                $entities_to_remove[] = $theme_element_meta;
                $entities_to_remove[] = $theme_element;

                // Commit deletes
                $em->flush();
                foreach ($entities_to_remove as $entity)
                    $em->remove($entity);
                $em->flush();

                // TODO - update cached version directly?
                parent::tmp_updateThemeCache($em, $theme, $user);
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
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // Not allowed to modify properties of a theme element in a table theme
            if ($theme->getThemeType() == 'table')
                throw new \Exception('Not allowed to change properties of a theme element belonging to a table theme');


            // Populate new ThemeElement form
            $submitted_data = new ThemeElementMeta();
            $theme_element_form = $this->createForm(UpdateThemeElementForm::class, $submitted_data);

            $theme_element_form->handleRequest($request);

            if ($theme_element_form->isSubmitted()) {

//$theme_element_form->addError( new FormError('do not save') );

                if ($theme_element_form->isValid()) {
                    // Store the old and the new css widths for this ThemeElement
                    $widths = array(
                        'med_width_old' => $theme_element->getCssWidthMed(),
                        'xl_width_old' => $theme_element->getCssWidthXL(),
                        'med_width_current' => $submitted_data->getCssWidthMed(),
                        'xl_width_current' => $submitted_data->getCssWidthXL(),
                    );

                    // If a value in the form changed, create a new ThemeElementMeta entity to store the change
                    $properties = array(
                        'cssWidthMed' => $submitted_data->getCssWidthMed(),
                        'cssWidthXL' => $submitted_data->getCssWidthXL(),
                    );
                    parent::ODR_copyThemeElementMeta($em, $user, $theme_element, $properties);

                    // TODO - update cached version directly?
                    parent::tmp_updateThemeCache($em, $theme, $user);


                    // Don't need to return a form object after saving
                    $return['widths'] = $widths;
                }
                else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($theme_element_form);
                    throw new \Exception($error_str);
                }
            }
            else {
                // Create ThemeElement form to modify existing properties
                $theme_element_meta = $theme_element->getThemeElementMeta();
                $theme_element_form = $this->createForm(UpdateThemeElementForm::class, $theme_element_meta);

                // Return the slideout html
                $templating = $this->get('templating');
                $return['d'] = $templating->render(
                    'ODRAdminBundle:Theme:theme_element_properties_form.html.twig',
                    array(
                        'theme_element' => $theme_element,
                        'theme_element_form' => $theme_element_form->createView(),
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
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $post = $_POST;
//print_r($post);
//return;

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_theme_element = $em->getRepository('ODRAdminBundle:ThemeElement');

            // Grab the first theme element just to check permissions
            $theme_element = null;
            foreach ($post as $index => $theme_element_id) {
                $theme_element = $repo_theme_element->find($theme_element_id);
                break;
            }
            /** @var ThemeElement $theme_element */

            $theme = $theme_element->getTheme();
            if ($theme == null)
                return parent::deletedEntityError('Theme');

            $datatype = $theme->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // Shouldn't happen since there's only one theme element per table theme
            if ($theme->getThemeType() == 'table')
                throw new \Exception('Not allowed to re-order theme elements inside a table theme');


            // If user has permissions, go through all of the theme elements updating their display order if needed
            foreach ($post as $index => $theme_element_id) {
                /** @var ThemeElement $theme_element */
                $theme_element = $repo_theme_element->find($theme_element_id);
                $em->refresh($theme_element);

                if ( $theme_element->getDisplayOrder() !== $index ) {
                    // Need to update this theme_element's display order
                    $properties = array(
                        'displayOrder' => $index
                    );
                    parent::ODR_copyThemeElementMeta($em, $user, $theme_element, $properties);
                }
            }

            // TODO - update cached version directly?
            parent::tmp_updateThemeCache($em, $theme, $user);
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x8283002 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
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
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                return parent::deletedEntityError('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme == null)
                return parent::deletedEntityError('Theme');

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ( $datafield == null )
                return parent::deletedEntityError('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                return parent::deletedEntityError('DataType');

            if ($theme->getDataType()->getId() !== $datatype->getId())
                throw new \Exception('Invalid request');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // No attaching datafields to master theme...they should always exist there
            if ($theme->getThemeType() == 'master')
                throw new \Exception('Not allowed to attach datafields to the master Theme');

            // Attach the datafield to the specified theme element
            parent::ODR_addThemeDataField($em, $user, $datafield, $theme_element);

            // If this datafield was attached to a table theme, then ensure the display orders are sequential
            if ($theme->getThemeType() == 'table') {
                $em->flush();

                $query = $em->createQuery(
                   'SELECT tdf
                    FROM ODRAdminBundle:ThemeDataField AS tdf
                    WHERE tdf.themeElement = :theme_element
                    AND tdf.deletedAt IS NULL'
                )->setParameters( array('theme_element' => $theme_element->getId()) );

                /** @var ThemeDataField[] $theme_datafields */
                $theme_datafields = $query->getResult();

                $datafield_list = array();
                foreach ($theme_datafields as $tdf)
                    $datafield_list[ $tdf->getDisplayOrder() ] = $tdf;

                /** @var ThemeDataField[] $datafield_list */
                ksort($datafield_list);

                // Reset displayOrder to be sequential
                $datafield_list = array_values($datafield_list);
                for ($i = 0; $i < count($datafield_list); $i++) {
                    $tdf = $datafield_list[$i];

                    if ($tdf->getDisplayOrder() !== $i) {
                        $properties = array(
                            'displayOrder' => $i
                        );
                        parent::ODR_copyThemeDatafield($em, $user, $tdf, $properties);
                    }
                }

                // TODO - empty table themes still count as having table themes?
            }

            // TODO - other stuff?

            // TODO - update cached version directly?
            parent::tmp_updateThemeCache($em, $theme, $user, false);
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x8262830 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Detaches a datafield from the specified theme element.
     *
     * @param integer $datafield_id
     * @param integer $theme_element_id
     * @param Request $request
     *
     * @return Response
     */
    public function detachdatafieldAction($datafield_id, $theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                return parent::deletedEntityError('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme == null)
                return parent::deletedEntityError('Theme');

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ( $datafield == null )
                return parent::deletedEntityError('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                return parent::deletedEntityError('DataType');

            if ($theme->getDataType()->getId() !== $datatype->getId())
                throw new \Exception('Invalid request');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // No detaching datafields to master theme...they should always exist there
            if ($theme->getThemeType() == 'master')
                throw new \Exception('Not allowed to detach datafields from the master Theme');

            // Detacth the datafield from the specified theme element
            /** @var ThemeDataField $theme_datafield */
            $theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField')->findOneBy( array('dataField' => $datafield->getId(), 'themeElement' => $theme_element->getId()) );
            $theme_datafield->setDeletedBy($user);
            $em->persist($theme_datafield);
            $em->flush();

            $em->remove($theme_datafield);


            // If this datafield was detached from a table theme, then ensure the display orders are sequential
            if ($theme->getThemeType() == 'table') {
                $em->flush();

                $query = $em->createQuery(
                   'SELECT tdf
                    FROM ODRAdminBundle:ThemeDataField AS tdf
                    WHERE tdf.themeElement = :theme_element
                    AND tdf.deletedAt IS NULL'
                )->setParameters( array('theme_element' => $theme_element->getId()) );

                /** @var ThemeDataField[] $theme_datafields */
                $theme_datafields = $query->getResult();

                $datafield_list = array();
                foreach ($theme_datafields as $tdf)
                    $datafield_list[ $tdf->getDisplayOrder() ] = $tdf;

                /** @var ThemeDataField[] $datafield_list */
                ksort($datafield_list);

                // Reset displayOrder to be sequential
                $datafield_list = array_values($datafield_list);
                for ($i = 0; $i < count($datafield_list); $i++) {
                    $tdf = $datafield_list[$i];

                    if ($tdf->getDisplayOrder() !== $i) {
                        $properties = array(
                            'displayOrder' => $i
                        );
                        parent::ODR_copyThemeDatafield($em, $user, $tdf, $properties);
                    }
                }

                // TODO - empty table themes still count as having table themes?
            }

            // TODO - other stuff?

            // TODO - update cached version directly?
            parent::tmp_updateThemeCache($em, $theme, $user, false);
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x8230135 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Attaches a child datatype to the specified theme element.
     *
     * @param integer $child_datatype_id
     * @param integer $theme_element_id
     * @param Request $request
     *
     * @return Response
     */
    public function attachdatatypeAction($child_datatype_id, $theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {

            throw new \Exception('NOT IMPLEMENTED');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                return parent::deletedEntityError('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme == null)
                return parent::deletedEntityError('Theme');

            /** @var DataType $child_datatype */
            $child_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($child_datatype_id);
            if ( $child_datatype == null )
                return parent::deletedEntityError('Child Datatype');

            // Always bypass cache in dev mode?
            $bypass_cache = false;
            if ($this->container->getParameter('kernel.environment') === 'dev')
                $bypass_cache = true;


            $datatree_array = parent::getDatatreeArray($em, $bypass_cache);

            $datatype_id = parent::getGrandparentDatatypeId($datatree_array, $child_datatype_id);
            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            if ($theme->getDataType()->getId() !== $datatype->getId())
                throw new \Exception('Invalid request');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // No attaching datafields to master theme...they should always exist there
            if ($theme->getThemeType() !== 'derivative')
                throw new \Exception('Only allowed to attach child/linked datatypes to a "derivative" Theme');

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x6683430 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Detaches a child datatype from the specified theme element.
     *
     * @param integer $child_datatype_id
     * @param integer $theme_element_id
     * @param Request $request
     *
     * @return Response
     */
    public function detachdatatypeAction($child_datatype_id, $theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {

            throw new \Exception('NOT IMPLEMENTED');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                return parent::deletedEntityError('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme == null)
                return parent::deletedEntityError('Theme');

            /** @var DataType $child_datatype */
            $child_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($child_datatype_id);
            if ( $child_datatype == null )
                return parent::deletedEntityError('Child Datatype');

            // Always bypass cache in dev mode?
            $bypass_cache = false;
            if ($this->container->getParameter('kernel.environment') === 'dev')
                $bypass_cache = true;


            $datatree_array = parent::getDatatreeArray($em, $bypass_cache);

            $datatype_id = parent::getGrandparentDatatypeId($datatree_array, $child_datatype_id);
            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            if ($theme->getDataType()->getId() !== $datatype->getId())
                throw new \Exception('Invalid request');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // No attaching datafields to master theme...they should always exist there
            if ($theme->getThemeType() !== 'derivative')
                throw new \Exception('Only allowed to detach child/linked datatypes from a "derivative" Theme');


        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x2824560 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
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
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $post = $_POST;
//print_r($post);
//return;

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_theme_element = $em->getRepository('ODRAdminBundle:ThemeElement');


            /** @var ThemeElement $initial_theme_element */
            /** @var ThemeElement $ending_theme_element */
            $initial_theme_element = $repo_theme_element->find($initial_theme_element_id);
            $ending_theme_element = $repo_theme_element->find($ending_theme_element_id);
            if ($initial_theme_element == null || $ending_theme_element == null)
                return parent::deletedEntityError('ThemeElement');

            if ($initial_theme_element->getTheme() == null || $ending_theme_element->getTheme() == null)
                return parent::deletedEntityError('Theme');
            if ( $initial_theme_element->getTheme()->getId() !== $ending_theme_element->getTheme()->getId() )
                throw new \Exception('Unable to move a datafield between Themes');


            $theme = $initial_theme_element->getTheme();
            $datatype = $theme->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // Ensure datafield list in $post is valid
            $query = $em->createQuery(
               'SELECT dt.id AS dt_id
                FROM ODRAdminBundle:DataFields AS df
                JOIN ODRAdminBundle:DataType AS dt WITH df.dataType = dt
                WHERE df.id IN (:datafields)
                AND df.deletedAt IS NULL AND dt.deletedAt IS NULL
                GROUP BY dt.id'
            )->setParameters( array('datafields' => $post) );
            $results = $query->getArrayResult();

            if ( count($results) > 1 )
                throw new \Exception('Invalid Datafield list');


            // There aren't appreciable differences between 'master', 'search_results', and 'table' themes...at least as far as changing datafield order is concerned

            // Grab all theme_datafield entries currently in the destination theme element
            $datafield_list = array();
            /** @var ThemeDataField[] $theme_datafields */
            $theme_datafields = $ending_theme_element->getThemeDataFields();
//print 'loading theme_datafield entries for theme_element '.$ending_theme_element->getId()."\n";
            foreach ($theme_datafields as $tdf) {
//print '-- found entry for datafield '.$tdf->getDataField()->getId().' tdf '.$tdf->getId()."\n";
                $datafield_list[ $tdf->getDataField()->getId() ] = $tdf;
            }
            /** @var ThemeDataField[] $datafield_list */


            // Update the order of the datafields in the destination theme element
            foreach ($post as $index => $df_id) {

                if ( isset($datafield_list[$df_id]) ) {
                    // Ensure this datafield has the correct display_order
                    $tdf = $datafield_list[$df_id];
                    if ($index != $tdf->getDisplayOrder()) {
                        $properties = array(
                            'displayOrder' => $index
                        );
//print 'updating theme_datafield '.$tdf->getId().' for datafield '.$tdf->getDataField()->getId().' theme_element '.$tdf->getThemeElement()->getId().' to displayOrder '.$index."\n";
                        parent::ODR_copyThemeDatafield($em, $user, $tdf, $properties);
                    }
                }
                else {
                    // This datafield got moved into the theme element
                    /** @var ThemeDataField $inserted_theme_datafield */
                    $inserted_theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField')->findOneBy( array('dataField' => $df_id, 'themeElement' => $initial_theme_element_id) );
                    if ($inserted_theme_datafield == null)
                        throw new \Exception('theme_datafield entry for Datafield '.$df_id.' themeElement '.$initial_theme_element_id.' does not exist');
                    else {
                        $properties = array(
                            'displayOrder' => $index,
                            'themeElement' => $ending_theme_element_id,
                        );
//print 'moved theme_datafield '.$inserted_theme_datafield->getId().' for Datafield '.$df_id.' themeElement '.$initial_theme_element_id.' to themeElement '.$ending_theme_element_id.' displayOrder '.$index."\n";
                        parent::ODR_copyThemeDatafield($em, $user, $inserted_theme_datafield, $properties);

                        // Don't need to redo display_order of the other theme_datafield entries in $initial_theme_element_id...they'll work fine even if the values aren't contiguous
                    }
                }
            }
            $em->flush();
/*
            // Schedule the cache for an update
            $options = array();
            $options['mark_as_updated'] = true;
            parent::updateDatatypeCache($datatype->getId(), $options);
*/
            parent::tmp_updateThemeCache($em, $theme, $user);
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x28268302 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Loads an ODR ThemeDatafield properties form.
     *
     * @param integer $datafield_id
     * @param integer $theme_id
     * @param Request $request
     *
     * @return Response
     */
    public function loadthemedatafieldAction($datafield_id, $theme_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                return parent::deletedEntityError('Datafield');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($theme == null)
                return parent::deletedEntityError('Theme');

            $datatype = $theme->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            if ( $datafield->getDataType()->getId() !== $datatype->getId() )
                throw new \Exception('Invalid Form');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // Locate the ThemeDatafield entity
            $query = $em->createQuery(
               'SELECT tdf
                FROM ODRAdminBundle:ThemeDataField AS tdf
                JOIN ODRAdminBundle:ThemeElement AS te WITH tdf.themeElement = te
                JOIN ODRAdminBundle:Theme AS t WITH te.theme = t
                WHERE tdf.dataField = :datafield_id AND t.id = :theme_id
                AND tdf.deletedAt IS NULL AND te.deletedAt IS NULL AND t.deletedAt IS NULL'
            )->setParameters( array('datafield_id' => $datafield->getId(), 'theme_id' => $theme->getId()) );
            $results = $query->getResult();

            /** @var ThemeDataField $theme_datafield */
            $theme_datafield = $results[0];
            if ($theme_datafield == null)
                return parent::deletedEntityError('Theme Datafield');

            // Create the ThemeDatatype form object
            $theme_datafield_form = $this->createForm(UpdateThemeDatafieldForm::class, $theme_datafield)->createView();

            // Return the slideout html
            $templating = $this->get('templating');
            $return['d'] = $templating->render(
                'ODRAdminBundle:Theme:theme_datafield_properties_form.html.twig',
                array(
                    'theme_datafield' => $theme_datafield,
                    'theme_datafield_form' => $theme_datafield_form,

                    'datafield_name' => $datafield->getFieldName(),
                )
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x9112630 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Saves an ODR ThemeDatafield properties form.  Kept separate from self::loadthemedatafieldAction() because
     * the 'master' theme designed by DisplaytemplateController.php needs to combine Datafield and ThemeDatafield forms
     * onto a single slideout, but every other theme is only allowed to modify ThemeDatafield entries.
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
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Populate new ThemeDataField form
            $submitted_data = new ThemeDataField();
            $theme_datafield_form = $this->createForm(UpdateThemeDatafieldForm::class, $submitted_data);

            $widths = array('med_width_old' => $theme_datafield->getCssWidthMed(), 'xl_width_old' => $theme_datafield->getCssWidthXL());

            $theme_datafield_form->handleRequest($request);

            if ($theme_datafield_form->isSubmitted()) {
//$theme_datafield_form->addError( new FormError('do not save') );

                if ($theme_datafield_form->isValid()) {
                    // Save all changes made via the form
                    $properties = array(
                        'cssWidthMed' => $submitted_data->getCssWidthMed(),
                        'cssWidthXL' => $submitted_data->getCssWidthXL(),
                    );
                    $new_theme_datafield = parent::ODR_copyThemeDatafield($em, $user, $theme_datafield, $properties);

                    $widths['med_width_current'] = $new_theme_datafield->getCssWidthMed();
                    $widths['xl_width_current'] = $new_theme_datafield->getCssWidthXL();

                    // TODO - update cached version directly?
                    parent::tmp_updateThemeCache($em, $theme_datafield->getThemeElement()->getTheme(), $user);
                }
                else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($theme_datafield_form);
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
     * Loads an ODR ThemeDatafield properties form.
     *
     * @param integer $theme_datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function loadthemedatatypeAction($theme_datatype_id, Request $request)
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

            $theme_element = $theme_datatype->getThemeElement();
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
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Create the ThemeDatatype form object
            $theme_datatype_form = $this->createForm(UpdateThemeDatatypeForm::class, $theme_datatype)->createView();

            // Return the slideout html
            $templating = $this->get('templating');
            $return['d'] = $templating->render(
                'ODRAdminBundle:Theme:theme_datatype_properties_form.html.twig',
                array(
                    'theme_datatype' => $theme_datatype,
                    'theme_datatype_form' => $theme_datatype_form,
                )
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x39912560 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Saves an ODR ThemeDatatype properties form.  Kept separate from self::loadthemedatatypeAction() because
     * the 'master' theme designed by DisplaytemplateController.php needs to combine Datatype, Datatree, and ThemeDatatype forms
     * onto a single slideout, but every other theme is only allowed to modify ThemeDatatype entries.
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

            $theme_element = $theme_datatype->getThemeElement();
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
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Populate new ThemeDataType form
            $submitted_data = new ThemeDataType();
            $theme_datatype_form = $this->createForm(UpdateThemeDatatypeForm::class, $submitted_data);

            $theme_datatype_form->handleRequest($request);

            if ($theme_datatype_form->isSubmitted()) {

//$theme_datatype_form->addError( new FormError('do not save') );

                if ($theme_datatype_form->isValid()) {
                    // Save all changes made via the form
                    $properties = array(
                        'display_type' => $submitted_data->getDisplayType(),
                    );
                    parent::ODR_copyThemeDatatype($em, $user, $theme_datatype, $properties);

                    // TODO - update cached version directly?
                    parent::tmp_updateThemeCache($em, $theme, $user);
                }
                else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($theme_datatype_form);
                    throw new \Exception($error_str);
                }
            }
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


    /**
     * Triggers a re-render and reload of a ThemeElement in the design.
     *
     * @param integer $source_datatype_id
     * @param integer $theme_element_id    The database id of the ThemeElement that needs to be re-rendered.
     * @param Request $request
     *
     * @return Response
     */
    public function reloadthemeelementAction($source_datatype_id, $theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $source_datatype */
            $source_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($source_datatype_id);
            if ($source_datatype == null)
                return parent::deletedEntityError('Source Datatype');

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
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            $datatype_id = null;
            $return['d'] = array(
                'theme_element_id' => $theme_element_id,
                'html' => self::GetDisplayData($source_datatype_id, $theme->getId(), 'theme_element', $theme_element_id, $request),
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x817913260' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Renders and returns the HTML for a DesignTemplate version of a DataType.
     *
     * @param integer $source_datatype_id  The datatype that originally requested this Displaytemplate rendering
     * @param integer $target_theme_id     The derivative theme to render for this datatype
     * @param string $template_name        One of 'default', 'child_datatype', 'theme_element', 'datafield'
     * @param integer $target_id           If $template_name == 'default', then $target_id should be a top-level datatype id
     *                                     If $template_name == 'child_datatype', then $target_id should be a child/linked datatype id
     *                                     If $template_name == 'theme_element', then $target_id should be a theme_element id
     *                                     If $template_name == 'datafield', then $target_id should be a datafield id
     * @param Request $request
     *
     * @throws \Exception
     *
     * @return string
     */
    private function GetDisplayData($source_datatype_id, $target_theme_id, $template_name, $target_id, Request $request)
    {
        // Don't need to check permissions

        // Required objects
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
        $repo_theme = $em->getRepository('ODRAdminBundle:Theme');

        $redis = $this->container->get('snc_redis.default');;
        // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');

        // Always bypass cache in dev mode?
        $bypass_cache = false;
        if ($this->container->getParameter('kernel.environment') === 'dev')
            $bypass_cache = true;

        // Going to need this a lot...
        $datatree_array = parent::getDatatreeArray($em, $bypass_cache);


        // ----------------------------------------
        // Load required objects based on parameters
        /** @var DataType $datatype */
        $datatype = null;
        /** @var Theme $master_theme */
        $master_theme = null;
        /** @var Theme $theme */
        $theme = null;

        /** @var DataType|null $child_datatype */
        $child_datatype = null;
        /** @var ThemeElement|null $theme_element */
        $theme_element = null;
        /** @var DataFields|null $datafield */
        $datafield = null;

        // Don't need to check whether these entities are deleted or not
        if ($template_name == 'default') {
            $datatype = $repo_datatype->find($target_id);
            $master_theme = $repo_theme->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            $theme = $repo_theme->find($target_theme_id);
        }
/*
        else if ($template_name == 'child_datatype') {
            $child_datatype = $repo_datatype->find($target_id);
            $theme = $repo_theme->findOneBy( array('dataType' => $child_datatype->getId(), 'themeType' => 'master') );

            // Need to determine the top-level datatype to be able to load all necessary data for rendering this child datatype
            if ( isset($datatree_array['descendant_of'][ $child_datatype->getId() ]) && $datatree_array['descendant_of'][ $child_datatype->getId() ] !== '' ) {
                $grandparent_datatype_id = parent::getGrandparentDatatypeId($datatree_array, $child_datatype->getId());

                $datatype = $repo_datatype->find($grandparent_datatype_id);
            }
            else if ( !isset($datatree_array['descendant_of'][ $child_datatype->getId() ]) || $datatree_array['descendant_of'][ $child_datatype->getId() ] == '' ) {
                // Was actually a re-render request for a top-level datatype...re-rendering should still work properly if various flags are set right
                $datatype = $child_datatype;
            }
        }
*/
        else if ($template_name == 'theme_element') {
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($target_id);
            $theme = $theme_element->getTheme();

            // This could be a theme element from a child datatype...make sure objects get set properly if it is
            $datatype = $theme->getDataType();
            if ( isset($datatree_array['descendant_of'][ $datatype->getId() ]) && $datatree_array['descendant_of'][ $datatype->getId() ] !== '' ) {
                $child_datatype = $theme->getDataType();
                $grandparent_datatype_id = parent::getGrandparentDatatypeId($datatree_array, $child_datatype->getId());

                $datatype = $repo_datatype->find($grandparent_datatype_id);
            }
        }
/*
        else if ($template_name == 'datafield') {
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($target_id);
            $child_datatype = $datafield->getDataType();
            $theme = $repo_theme->findOneBy( array('dataType' => $child_datatype->getId(), 'themeType' => 'master') );

            // This could be a datafield from a child datatype...make sure objects get set properly if it is
            $datatype = $datafield->getDataType();
            if ( isset($datatree_array['descendant_of'][ $datatype->getId() ]) && $datatree_array['descendant_of'][ $datatype->getId() ] !== '' ) {
                $child_datatype = $theme->getDataType();
                $grandparent_datatype_id = parent::getGrandparentDatatypeId($datatree_array, $child_datatype->getId());

                $datatype = $repo_datatype->find($grandparent_datatype_id);
            }
        }
*/


        // ----------------------------------------
        // Determine which datatypes/childtypes to load from the cache
        $include_links = true;
        $associated_datatypes = parent::getAssociatedDatatypes($em, array($datatype->getId()), $include_links);

//print '<pre>'.print_r($associated_datatypes, true).'</pre>'; exit();

        // Grab the cached versions of all of the associated datatypes, and store them all at the same level in a single array
        $datatype_array = array();
        foreach ($associated_datatypes as $num => $dt_id) {
            $datatype_data = parent::getRedisData(($redis->get($redis_prefix.'.cached_datatype_'.$dt_id)));
            if ($bypass_cache || $datatype_data == null)
                $datatype_data = parent::getDatatypeData($em, $datatree_array, $dt_id, $bypass_cache);

            foreach ($datatype_data as $dt_id => $data)
                $datatype_array[$dt_id] = $data;
        }

//print '<pre>'.print_r($datatype_array, true).'</pre>'; exit();

        // Don't need to filter by group permissions...have to be a datatype admin to get to this point, which will let them see everything anyways

        // ----------------------------------------
        // Render the required version of the page
        $templating = $this->get('templating');

        $html = '';
        if ($template_name == 'default') {
            $html = $templating->render(
                'ODRAdminBundle:Theme:theme_ajax.html.twig',
                array(
                    'datatype_array' => $datatype_array,
                    'initial_datatype_id' => $datatype->getId(),

                    'master_theme_id' => $master_theme->getId(),
                    'target_theme_id' => $theme->getId(),
                )
            );
        }
/*
        else if ($template_name == 'child_datatype') {

            // Set variables properly incase this was a theme_element for a child/linked datatype
            $target_datatype_id = $child_datatype->getId();
            $is_top_level = 1;
            if ($child_datatype->getId() !== $datatype->getId())
                $is_top_level = 0;


            // If the top-level datatype id found doesn't match the original datatype id of the design page, then this is a request for a linked datatype
            $is_link = 0;
            if ($source_datatype_id != $datatype->getId()) {
                $is_top_level = 0;
                $is_link = 1;
            }

            $html = $templating->render(
                'ODRAdminBundle:Displaytemplate:design_childtype.html.twig',
                array(
                    'datatype_array' => $datatype_array,
                    'target_datatype_id' => $target_datatype_id,
                    'theme_id' => $theme->getId(),

                    'is_link' => $is_link,
                    'is_top_level' => $is_top_level,
                )
            );
        }
*/
        else if ($template_name == 'theme_element') {

            // Set variables properly incase this was a theme_element for a child/linked datatype
            $target_datatype_id = $datatype->getId();
            $is_top_level = 1;
            if ($child_datatype !== null) {
                $target_datatype_id = $child_datatype->getId();
                $is_top_level = 0;
            }

            // If the top-level datatype id found doesn't match the original datatype id of the design page, then this is a request for a linked datatype
            $is_link = 0;
            if ($source_datatype_id != $datatype->getId())
                $is_link = 1;

            // design_fieldarea.html.twig attempts to render all theme_elements in the given theme...
            // Since this is a request to only re-render one of them, unset all theme_elements in the theme other than the one the user wants to re-render
            foreach ($datatype_array[ $target_datatype_id ]['themes'][ $theme->getId() ]['themeElements'] as $te_num => $te) {
                if ( $te['id'] != $target_id )
                    unset( $datatype_array[ $target_datatype_id ]['themes'][ $theme->getId() ]['themeElements'][$te_num] );
            }

//print '<pre>'.print_r($datatype_array, true).'</pre>'; exit();

            $html = $templating->render(
                'ODRAdminBundle:Theme:theme_fieldarea.html.twig',
                array(
                    'datatype_array' => $datatype_array,

                    'target_datatype_id' => $target_datatype_id,
                    'theme_id' => $theme->getId(),

                    'target_themetype' => $theme->getThemeType(),

                    'is_top_level' =>  $is_top_level,
                    'is_link' => $is_link,
                )
            );
        }
/*
        else if ($template_name == 'datafield') {

            // Locate the array versions of the requested datafield and its associated theme_datafield entry
            $datafield_array = null;
            $theme_datafield_array = null;

            foreach ($datatype_array[ $child_datatype->getId() ]['themes'][ $theme->getId() ]['themeElements'] as $te_num => $te) {
                if ( isset($te['themeDataFields']) ) {
                    foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                        if ( isset($tdf['dataField']) && $tdf['dataField']['id'] == $datafield->getId() ) {
                            $theme_datafield_array = $tdf;
                            $datafield_array = $tdf['dataField'];
                            break;
                        }
                    }
                }

                if ( $datafield_array !== null )
                    break;
            }

            if ( $datafield_array == null )
                throw new \Exception('Unable to locate array entry for datafield '.$datafield->getId());


            $html = $templating->render(
                'ODRAdminBundle:Displaytemplate:design_datafield.html.twig',
                array(
                    'theme_datafield' => $theme_datafield_array,
                    'datafield' => $datafield_array,
                )
            );
        }
*/
        return $html;
    }
}
