<?php

/**
 * Open Data Repository Data Publisher
 * Theme Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * TODO
 */

namespace ODR\AdminBundle\Controller;


use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeMeta;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeElementMeta;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\OpenRepository\UserBundle\Entity\User;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Forms
use ODR\AdminBundle\Form\Type\DatafieldType;
use ODR\AdminBundle\Form\UpdateThemeForm;
use ODR\AdminBundle\Form\UpdateThemeElementForm;
use ODR\AdminBundle\Form\UpdateThemeDatafieldForm;
use ODR\AdminBundle\Form\UpdateThemeDatatypeForm;
// Services
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\AdminBundle\Component\Service\TrackedJobService;
// Symfony
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class ThemeController extends ODRCustomController
{

    /**
     * TODO - fix this
     *
     * Toggles the public status of a Theme.
     *
     * @param integer $theme_id
     * @param Request $request
     *
     * @return Response
     */
    public function themepublicAction($theme_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = array();

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');


            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ($user === "anon.")
                throw new ODRForbiddenException();

            // If they're not a super admin, don't allow a user to change shared status of another user's theme
            if (!$user->hasRole('ROLE_SUPER_ADMIN') && $user->getId() != $theme->getCreatedBy()->getId())
                throw new ODRForbiddenException();

            // Don't allow the user to make changes to a theme of a datatype they can't view
            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Toggle the shared status of the specified theme
            if ( $theme->isShared() ) {
                // Theme is currently shared...
                $properties = array(
                    'shared' => false,
                );
                parent::ODR_copyThemeMeta($em, $user, $theme, $properties);

                $return['d']['public'] = false;
            }
            else {
                // Theme is not currently shared...
                $properties = array(
                    'shared' => true,
                );
                parent::ODR_copyThemeMeta($em, $user, $theme, $properties);

                $return['d']['public'] = true;
            }

            // Update cached version of theme
            $theme_service->updateThemeCacheEntry($theme, $user);
        }
        catch (\Exception $e) {
            $source = 0xd67b2938;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * TODO - fix this
     *
     * Sets a view to be the database master view.  User must be owner of new view
     * and an admin on the database.
     *
     * @param integer $theme_id
     * @param Request $request
     *
     * @return Response
     */
    public function themedefaultAction($theme_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');


            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ($user === "anon.")
                throw new ODRForbiddenException();

            // Don't allow the user to do this unless they're an admin of this datatype
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Compile a list of theme types that need to be set to "not default"
            $theme_types = array();
            if ($theme->getThemeType() == 'master' || $theme->getThemeType() == 'custom_view') {
                array_push($theme_types, 'master');
                array_push($theme_types, 'custom_view');
            }
            else {
                array_push($theme_types, $theme->getThemeType());
            }

            /** @var Theme[] $theme_list */
            $theme_list = $em->getRepository('ODRAdminBundle:Theme')->findBy(
                array('datatype' => $datatype->getId(), 'themeType' => $theme_types)
            );

            // Each of the themes in this list need to be set to "not default"...
            foreach ($theme_list as $t) {
                $properties = array(
                    'isDefault' => false
                );
                parent::ODR_copyThemeMeta($em, $user, $t, $properties);

                // Update cached versions of these themes
                $theme_service->updateThemeCacheEntry($t, $user);
            }

            // ...afterwards, specify this theme as the default (and shared, if it isn't already)
            $properties = array(
                'shared' => true,
                'isDefault' => true,
            );
            parent::ODR_copyThemeMeta($em, $user, $theme, $properties);

            // Updated cached version of this theme
            $theme_service->updateThemeCacheEntry($theme, $user);

            $return['d'] = "success";
        }
        catch (\Exception $e) {
            $source = 0x763a86a6;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * TODO - fix this
     *
     * Deletes a user's custom view.  Will not delete
     * master views.
     *
     * @param $datatype_id
     * @param $theme_id
     * @param Request $request
     * @return Response
     */
    public function delete_custom_themeAction(
        $datatype_id,
        $theme_id,
        Request $request
    ) {

        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        // Check permissions
        try {

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null) {
                throw new ODRNotFoundException('Database', false, 0x823756);
            }

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ($user === "anon.") {
                throw new ODRForbiddenException('View', 0x237528);
            }

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            // Ensure user has permissions to be doing this
            // Users must have view permission
            if (!$pm_service->canViewDatatype($user, $datatype)) {
                throw new ODRForbiddenException(
                    'You must have "view" permissions on this database to create or delete a custom view.',
                    0x239223
                );
            }

            // Check if this is a master template based datatype that is still
            // in the creation process.  If so, ask user to try again later.
            if ($datatype->getSetupStep() != DataType::STATE_OPERATIONAL) {
                // Throw error and ask user to wait
                throw new ODRForbiddenException(
                    'Please try again later.  This database is not yet fully created.',
                    0x2918239
                );
            }

            /** @var Theme $original_theme */
            $repo_theme = $em->getRepository('ODRAdminBundle:Theme');
            /** @var Theme $theme */
            $theme = $repo_theme->find($theme_id);

            // Is user the creator of theme
            if($theme->getCreatedBy()->getId() == $user->getId()
                && $theme->getThemeType() != "master"
            ) {

                /** @var ThemeInfoService $theme_service */
                $theme_service = $this->container->get('odr.theme_info_service');

                // If is datatype default, set "master" as default
                if(
                    $theme->getThemeType() == 'custom_view'
                    && $theme->getThemeMeta()->getIsDefault() > 0
                ) {
                    // Find master theme and set as default

                    /** @var Theme $master_theme */
                    $master_theme = $repo_theme->findOneBy(
                        array(
                            'dataType' => $datatype->getId(),
                            'themeType' => 'master'
                        )
                    );

                    // TODO determine if datatype admin is needed to restore as default.
                    /** @var ThemeMeta $master_theme_meta */
                    $master_theme_meta = $master_theme->getThemeMeta();
                    $new_theme_meta = clone $master_theme_meta;
                    $new_theme_meta->setCreated(new \DateTime());
                    $new_theme_meta->setUpdated(new \DateTime());
                    $new_theme_meta->setIsDefault(1);

                    $em->persist($new_theme_meta);
                    $em->remove($master_theme_meta);

                    // Flush default theme cache.
                    $em->flush();
                    $theme_service->getDatatypeDefaultTheme($datatype->getId(), 'master');
                }

                // Check user session theme and assign default
                $theme_type = $theme->getThemeType();
                if($theme_type == "custom_view") {
                    $theme_type = 'master';
                }
                else {
                    $theme_type = preg_replace('/^custom_/','', $theme_type);
                }
                $user_session_theme = $theme_service->getSessionTheme(
                    $datatype->getId(),
                    $theme_type
                );
                // if user was using this as default.
                if($user_session_theme != null
                    && $user_session_theme->getId() == $theme->getId()) {
                    $theme_service->resetSessionTheme($datatype, $theme_type);
                }

                // Delete theme
                $em->remove($theme);
                $em->flush();

                $return['d'] = "success";
            }
            else {
                throw new ODRForbiddenException(
                    "You do not have permissions to use or modify this view.",
                    0x8192392
                );
            }
        }
        catch (\Exception $e) {
            $source = 0x2392933;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

    /**
     * TODO - fix this
     *
     * @param $datatype_id
     * @param $theme_id
     * @param Request $request
     * @return Response
     */
    public function modify_themeAction(
        $datatype_id,
        $theme_id,
        Request $request
    ) {

        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        // Check permissions

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null) {
                throw new ODRNotFoundException('Database', false, 0x8238888);
            }

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ($user === "anon.") {
                throw new ODRForbiddenException('View', 0x1238193);
            }

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            // Ensure user has permissions to be doing this
            // Users must have view permission
            if (!$pm_service->canViewDatatype($user, $datatype)) {
                throw new ODRForbiddenException(
                    'You must have "view" permissions on this database to create a custom view.',
                    0x4328483
                );
            }


            // Check if this is a master template based datatype that is still
            // in the creation process.  If so, ask user to try again later.
            if ($datatype->getSetupStep() != DataType::STATE_OPERATIONAL) {
                // Throw error and ask user to wait
                throw new ODRForbiddenException(
                    'Please try again later.  This database is not yet fully created.',
                    0x2918239
                );
            }

            /** @var Theme $original_theme */
            $repo_theme = $em->getRepository('ODRAdminBundle:Theme');
            $original_theme = $repo_theme->find($theme_id);

            if($original_theme->getThemeType() == "master") {
                throw new ODRBadRequestException(
                    "Master themes can not be customized directly.  You must copy the theme first.",
                    0x82382818
                );
            }

            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'html' => self::DisplayTheme($datatype, $original_theme->getThemeType(), $theme_id, 'edit')
            );

        }
        catch (\Exception $e) {
            $source = 0x134752347;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * TODO - fix this
     *
     * Allows users to clone themes for creating customized views.
     *
     * @param $datatype_id
     * @param int $theme_id
     * @param Request $request
     * @return Response
     */
    public function clone_themeAction(
        $datatype_id,
        $theme_id = 0,
        Request $request
    ) {

        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        // Check permissions

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null) {
                throw new ODRNotFoundException('Database');
            }

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ($user === "anon.") {
                throw new ODRForbiddenException('View');
            }

            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            // Users must have view permission
            if (
                !isset($datatype_permissions[$datatype->getId()])
                || !isset($datatype_permissions[$datatype->getId()]['dt_view'])
            ) {
                throw new ODRForbiddenException(
                    'You must have "view" permissions on this database to create a custom view.',
                    0x823782983
                );
            }


            // Check if this is a master template based datatype that is still
            // in the creation process.  If so, ask user to try again later.
            if ($datatype->getSetupStep() != DataType::STATE_OPERATIONAL) {
                // Throw error and ask user to wait
                throw new ODRForbiddenException(
                    'Please try again later.  This database is not yet fully created.',
                    0x2377282
                );
            }

            /** @var Theme $original_theme */
            $repo_theme = $em->getRepository('ODRAdminBundle:Theme');
            $original_theme = $repo_theme->find($theme_id);

            if ($original_theme != null) {
                // Create tracked job id
                /** @var TrackedJobService $tracked_job_service */
                $tracked_job_service = $this->container->get('odr.tracked_job_service');
                /** @var TrackedJob $tracked_job */
                $tracked_job = $tracked_job_service->getTrackedJob(
                    $user,
                    'clone_theme',
                    'theme_'.$original_theme->getId(),
                    array(),
                    '',
                    '100'
                );


                // Create theme job in beanstalk...
                // Start the job to create the datatype from the template
                $pheanstalk = $this->get('pheanstalk');
                $redis_prefix = $this->container->getParameter('memcached_key_prefix');
                $api_key = $this->container->getParameter('beanstalk_api_key');

                // Insert the new job into the queue
                $priority = 1024;   // should be roughly default priority
                $payload = json_encode(
                    array(
                        "user_id" => $user->getId(),
                        "datatype_id" => $datatype->getId(),
                        "theme_id" => $theme_id,
                        "tracked_job_id" => $tracked_job->getId(),

                        "redis_prefix" => $redis_prefix,    // debug purposes only
                        "api_key" => $api_key,
                    )
                );

                $delay = 0;
                $pheanstalk->useTube('clone_theme')->put($payload, $priority, $delay);

                $return['d'] = $tracked_job->toArray();

            } else {
                throw new ODRNotFoundException(
                    'A valid existing view must be selected for copying. View not found.',
                    true,
                    0x8213928
                );
            }


        }
        catch (\Exception $e) {
            $source = 0x823cadf213;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * TODO - fix this
     *
     * Loads and returns the DesignTemplate HTML for this DataType.
     *
     * @param integer $datatype_id   The database id of the DataType to be rendered.
     * @param string $template_type  The type of template to be designed/modified [default: master].
     * @param integer $template_id   If provided, the corresponding template will be loaded [default: 0].
     * @param Request $request
     *
     * @return Response
     */
    public function designAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = array();

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException("Datatype");


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            // TODO - Alternate settings for individual users to create their own themes...
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Check if this is a master template based datatype that is still in the creation
            //  process.  If so, redirect to progress system.
            if ($datatype->getSetupStep() == DataType::STATE_INITIAL
                && $datatype->getIsMasterType() == 0
            ) {
                // Return creating datatype template
                $templating = $this->get('templating');
                $return['d']['html'] = $templating->render(
                    'ODRAdminBundle:Datatype:create_status_checker.html.twig',
                    array("datatype" => $datatype)
                );
            } else {
                // TODO - transferred from function parameters...
                $theme_type = "search_results";
                $theme_id = 0;

                $return['d'] = array(
                    'datatype_id' => $datatype->getId(),
                    'html' => self::DisplayTheme($datatype, $theme_type, $theme_id)
                );
            }
        }
        catch (\Exception $e) {
            $source = 0x239a544e;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * TODO - fix this
     *
     * Renders and returns the HTML for a DesignTemplate version of a DataType.
     *
     * @param DataType $datatype The datatype that originally requested this Theme rendering
     * @param string $template_type One of 'master','custom'
     * @param integer $theme_id If > 0, load this theme to operate on.
     * @param string $display_mode Determines what messaging to display in editor.
     *
     * @throws \Exception
     *
     * @return string
     */
    private function DisplayTheme($datatype, $template_type, $theme_id, $display_mode = "wizard")
    {
        // Required objects
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $repo_theme = $em->getRepository('ODRAdminBundle:Theme');

        /** @var DatatypeInfoService $dti_service */
        $dti_service = $this->container->get('odr.datatype_info_service');
        /** @var PermissionsManagementService $pm_service */
        $pm_service = $this->container->get('odr.permissions_management_service');


        // ----------------------------------------
        $theme = null;
        if ($theme_id > 0) {
            // If a theme was specified, attempt to load that...
            $theme = $repo_theme->find($theme_id);
        }
        else {
            // ...otherwise, attempt to load the
            $theme = $repo_theme->findOneBy(
                array('dataType' => $datatype->getId(), 'themeType' => $template_type)
            );
        }
        /** @var Theme $theme */
        if ($theme == null)
            throw new ODRNotFoundException('Theme');


        // ----------------------------------------
        // Determine whether the user is an admin of this datatype
        /** @var User $user */
        $user = $this->container->get('security.token_storage')->getToken()->getUser();

        // TODO - what is this wonkyness
        // Anonymous users can not design their own themes.
        if ($user === "anon.")
            throw new ODRForbiddenException();

        $datatype_permissions = $pm_service->getDatatypePermissions($user);

        $is_datatype_admin = false;
        if (isset($datatype_permissions[$datatype->getId()])
            && isset($datatype_permissions[$datatype->getId()]['dt_admin'])
        )
            $is_datatype_admin = true;


        // If theme is null, we need to create them cloning master.
        // Create Datatype Service....
        if ($theme == null) {
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');
            /** @var Theme $theme */
            $theme = $theme_service->cloneThemesForDatatype(
                $datatype,
                $template_type,
                $user->getId()
            );
            // If state is "incomplete" this makes it operational
            if($datatype->getSetupStep() != DataType::STATE_OPERATIONAL) {
                $datatype->setSetupStep(DataType::STATE_OPERATIONAL);
                $em->persist($datatype);
                $em->flush();
            }
        }

        // ----------------------------------------
        // Grab the cached versions of all of the associated datatypes, and store them all at the same level in a single array
        $include_links = true;
        $datatype_array = $dti_service->getDatatypeArray($datatype->getId(), $include_links);


        // ----------------------------------------
        // TODO - what the everloving shit is this
        // Going to need an array of fieldtype ids and fieldtype typenames for notifications about changing fieldtypes
        $fieldtype_array = array();
        /** @var FieldType[] $fieldtypes */
        $fieldtypes = $em->getRepository('ODRAdminBundle:FieldType')->findAll();
        foreach ($fieldtypes as $fieldtype)
            $fieldtype_array[$fieldtype->getId()] = $fieldtype->getTypeName();

        // Store whether this datatype has datarecords..affects warnings when changing datafield fieldtypes
        $query = $em->createQuery(
           'SELECT COUNT(dr) AS dr_count
            FROM ODRAdminBundle:DataRecord AS dr
            WHERE dr.dataType = :datatype_id'
        )->setParameters(array('datatype_id' => $datatype->getId()));
        $results = $query->getArrayResult();

        $has_datarecords = false;
        if ($results[0]['dr_count'] > 0)
            $has_datarecords = true;

        // GET request...load the actual ThemeMeta entity
        $theme_meta = $theme->getThemeMeta();
        $theme_form = $this->createForm(
            UpdateThemeForm::class,
            $theme_meta
        );

        // Render the required version of the page
        $templating = $this->get('templating');

        $html = $templating->render(
            'ODRAdminBundle:Theme:theme_ajax.html.twig',
            array(
                'theme_datatype' => $datatype,
                'datatype_array' => $datatype_array,
                'initial_datatype_id' => $datatype->getId(),
                'theme_id' => $theme->getId(),
                'theme' => $theme,
                'theme_form' => $theme_form->createView(),
                'is_datatype_admin' => $is_datatype_admin,
                'fieldtype_array' => $fieldtype_array,
                'has_datarecords' => $has_datarecords,
                'display_mode' => $display_mode,
            )
        );

        return $html;
    }


    /**
     * Loads an ODR ThemeDatafield properties form.
     *
     * @param integer $datafield_id
     * @param integer $theme_element_id
     * @param Request $request
     *
     * @return Response
     */
    public function loadthemedatafieldAction($datafield_id, $theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                throw new ODRNotFoundException('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme->getDeletedAt() != null)
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            if ($datafield->getDataType()->getId() !== $datatype->getId())
                throw new ODRBadRequestException('Invalid Form');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Locate the ThemeDatafield entity
            /** @var ThemeDataField $theme_datafield */
            $theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField')->findOneBy(
                array('dataField' => $datafield->getId(), 'themeElement' => $theme_element->getId())
            );
            if ($theme_datafield == null)
                throw new ODRNotFoundException('ThemeDatafield');


            // Create the ThemeDatatype form object
            $theme_datafield_form = $this->createForm(
                UpdateThemeDatafieldForm::class,
                $theme_datafield,
                array(
                    'action' => $this->generateUrl(
                        'odr_design_save_theme_datafield',
                        array(
                            'theme_element_id' => $theme_element_id,
                            'datafield_id' => $datafield_id
                        )
                    )
                )
            )->createView();

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
            $source = 0x7c3192e4;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
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
     * @param integer $theme_element_id
     * @param integer $datafield_id
     * @param Request $request
     *
     * @return Response
     */
    public function savethemedatafieldAction($theme_element_id, $datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');


            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                throw new ODRNotFoundException('ThemeElement');

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            /** @var ThemeDataField $theme_datafield */
            $theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField')->findOneBy(
                array('themeElement' => $theme_element->getId(), 'dataField' => $datafield->getId())
            );
            if ($theme_datafield == null)
                throw new ODRNotFoundException('ThemeDatafield');


            $theme = $theme_element->getTheme();
            if ($theme->getDeletedAt() != null)
                throw new ODRNotFoundException('Theme');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            if ($theme->getDataType()->getId() != $datatype->getId())
                throw new ODRBadRequestException();

            if ($theme->getThemeType() == 'table')
                throw new ODRBadRequestException('Unable to change properties of a Table theme');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this    TODO - do users creating custom themes have the ability to resize?
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Populate new ThemeDataField form
            $submitted_data = new ThemeDataField();
            $theme_datafield_form = $this->createForm(UpdateThemeDatafieldForm::class, $submitted_data);

            $theme_datafield_form->handleRequest($request);
            if ($theme_datafield_form->isSubmitted()) {

//$theme_datafield_form->addError( new FormError('DO NOT SAVE') );

                if ($theme_datafield_form->isValid()) {
                    // Save all changes made via the submitted form
                    $properties = array(
                        'displayOrder' => $submitted_data->getDisplayOrder(),
                        'cssWidthMed' => $submitted_data->getCssWidthMed(),
                        'cssWidthXL' => $submitted_data->getCssWidthXL(),
                        'hidden' => $submitted_data->getHidden(),
                    );
                    parent::ODR_copyThemeDatafield($em, $user, $theme_datafield, $properties);

                    // Update the cached version of the theme
                    $theme_service->updateThemeCacheEntry($theme, $user);
                }
                else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($theme_datafield_form);
                    throw new ODRException($error_str);
                }
            }

            // Don't need to return a form object...it's loaded with the regular datafield properties form
        }
        catch (\Exception $e) {
            $source = 0xca1a6cae;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * TODO - fix this
     *
     * Loads an ODR ThemeDatatype properties form.
     *
     * @param integer $theme_element_id  The id of the theme element holding the child/linked datatype
     * @param integer $datatype_id       The id of the child/linked datatype itself
     * @param Request $request
     *
     * @return Response
     */
    public function loadthemedatatypeAction($theme_element_id, $datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                throw new ODRNotFoundException('ThemeElement');

            /** @var DataType $child_datatype */
            $child_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($child_datatype == null)
                throw new ODRNotFoundException('Datatype');

            /** @var ThemeDataType $theme_datatype */
            $theme_datatype = $em->getRepository('ODRAdminBundle:ThemeDataType')->findOneBy(
                array('themeElement' => $theme_element->getId(), 'dataType' => $child_datatype->getId())
            );
            if ($theme_datatype == null)
                throw new ODRNotFoundException('Theme Datatype');

            $theme = $theme_element->getTheme();
            if ($theme->getDeletedAt() != null)
                throw new ODRNotFoundException('Theme');

            $parent_datatype = $theme->getDataType();
            if ($parent_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // TODO - Only allow if user is datatype admin or theme owner?
            if ( !$pm_service->isDatatypeAdmin($user, $parent_datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // TODO - why was this moved out of the form itself?
            // Allow header to be hidden for non-multiple-allowed child types
            $display_choices = array(
                'Accordion' => '0',
                'Tabbed' => '1',
                'Select Box' => '2',
                'List' => '3'
            );

            // Check if multiple child/linked datarecords are allowed for datatype
            /** @var DataTree $datatree */
            $datatree = $em->getRepository('ODRAdminBundle:DataTree')->findOneBy(
                array('ancestor' => $parent_datatype->getId(), 'descendant' => $child_datatype->getId())
            );

            if ($datatree->getMultipleAllowed() == false)
                $display_choices['Hide Header'] = 4;

            // Create the ThemeDatatype form object
            $theme_datatype_form = $this->createForm(
                UpdateThemeDatatypeForm::class,
                $theme_datatype,
                array(
                    'action' => $this->generateUrl(
                        'odr_design_save_theme_datatype',
                        array(
                            'theme_element_id' => $theme_element_id,
                            'datatype_id' => $datatype_id,
                        )
                    ),
                    'display_choices' => $display_choices
                )
            )->createView();


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
            $source = 0x73845aa8;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * TODO - fix this
     *
     * Saves an ODR ThemeDatatype properties form.  Kept separate from self::loadthemedatatypeAction() because
     * the 'master' theme designed by DisplaytemplateController.php needs to combine Datatype, Datatree, and ThemeDatatype forms
     * onto a single slideout, but every other theme is only allowed to modify ThemeDatatype entries.
     *
     * @param integer $theme_element_id
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function savethemedatatypeAction($theme_element_id, $datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');


            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                throw new ODRNotFoundException('ThemeElement');

            /** @var DataType $child_datatype */
            $child_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($child_datatype == null)
                throw new ODRNotFoundException('Datatype');

            /** @var ThemeDataType $theme_datatype */
            $theme_datatype = $em->getRepository('ODRAdminBundle:ThemeDataType')->findOneBy(
                array('themeElement' => $theme_element->getId(), 'dataType' => $child_datatype->getId())
            );
            if ($theme_datatype == null)
                throw new ODRNotFoundException('Theme Datatype');

            /** @var Theme $theme */
            $theme = $theme_element->getTheme();
            if ($theme->getDeletedAt() != null)
                throw new ODRNotFoundException('Theme');

            /** @var DataType $parent_datatype */
            $parent_datatype = $theme->getDataType();
            if ($parent_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('DataType');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Only allow if user is datatype admin or theme owner?
            if ( !$pm_service->isDatatypeAdmin($user, $parent_datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Populate new ThemeDataType form
            /** @var ThemeDataType $submitted_data */
            $submitted_data = new ThemeDataType();

            // TODO - why was this moved out of the associated form?
            // Allow header to be hidden for non-multiple-allowed child types
            $display_choices = array(
                'Accordion' => '0',
                'Tabbed' => '1',
                'Select Box' => '2',
                'List' => '3'
            );

            // Check if multiple child/linked datarecords are allowed for datatype
            /** @var DataTree $datatree */
            $datatree = $em->getRepository('ODRAdminBundle:DataTree')->findOneBy(
                array('ancestor' => $parent_datatype->getId(), 'descendant' => $child_datatype->getId())
            );

            if ($datatree->getMultipleAllowed() == false)
                $display_choices['Hide Header'] = 4;


            // Create the ThemeDatatype form object
            $theme_datatype_form = $this->createForm(
                UpdateThemeDatatypeForm::class,
                $submitted_data,
                array(
                    'display_choices' => $display_choices
                )
            );

            $theme_datatype_form->handleRequest($request);
            if ($theme_datatype_form->isSubmitted()) {

                if ($theme_datatype_form->isValid()) {
                    // Save all changes made via the form
                    $properties = array(
                        'display_type' => $submitted_data->getDisplayType(),
                        'hidden' => $submitted_data->getHidden(),
                    );
                    parent::ODR_copyThemeDatatype($em, $user, $theme_datatype, $properties);

                    // Update cached version of theme
                    $theme_service->updateThemeCacheEntry($theme, $user);
                }
                else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($theme_datatype_form);
                    throw new ODRException($error_str);
                }
            }
        }
        catch (\Exception $e) {
            $source = 0x1ffa9747;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
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

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');


            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
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

            // Update cached version of theme
            $theme_service->updateThemeCacheEntry($theme, $user);
        }
        catch (\Exception $e) {
            $source = 0x7cbe82a5;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
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

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');


            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                throw new ODRNotFoundException('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme->getDeletedAt() != null)
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // Not allowed to modify properties of a theme element in a table theme
            if ($theme->getThemeType() == 'table')
                throw new \Exception('Not allowed to change properties of a theme element belonging to a table theme');


            // Populate new ThemeElement form
            $submitted_data = new ThemeElementMeta();
            $theme_element_form = $this->createForm(
                UpdateThemeElementForm::class,
                $submitted_data
            );

            $theme_element_form->handleRequest($request);
            if ($theme_element_form->isSubmitted()) {

                //$theme_element_form->addError( new FormError('do not save') );

                if ($theme_element_form->isValid()) {
                    // If a value in the form changed, create a new ThemeElementMeta entity to store the change
                    $properties = array(
                        'cssWidthMed' => $submitted_data->getCssWidthMed(),
                        'cssWidthXL' => $submitted_data->getCssWidthXL(),
                    );
                    parent::ODR_copyThemeElementMeta($em, $user, $theme_element, $properties);

                    // Update the cached version of this theme
                    $theme_service->updateThemeCacheEntry($theme, $user);
                }
                else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($theme_element_form);
                    throw new ODRException($error_str);
                }
            }
            else {
                // Create ThemeElement form to modify existing properties
                $theme_element_meta = $theme_element->getThemeElementMeta();
                $theme_element_form = $this->createForm(
                    UpdateThemeElementForm::class,
                    $theme_element_meta,
                    array(
                        'action' => $this->generateUrl(
                            'odr_design_get_theme_element_properties',
                            array(
                                'theme_element_id' => $theme_element_id
                            )
                        )
                    )
                );

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
            $source = 0xfc2da1a7;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
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

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');


            // Grab the theme element from the repository
            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                throw new ODRNotFoundException('ThemeElement');
            $em->refresh($theme_element);   // TODO - why did this exist again?

            $theme = $theme_element->getTheme();
            if ($theme->getDeletedAt() != null)
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Not allowed to delete a theme element from a table theme
            if ($theme->getThemeType() == 'table')
                throw new \Exception('Not allowed to delete a theme element from a table theme');

            $entities_to_remove = array();

            // Don't allow deletion of theme_element if it still has datafields or a child/linked datatype attached to it
            $theme_datatypes = $theme_element->getThemeDataType();
            $theme_datafields = $theme_element->getThemeDataFields();

            // TODO - allow deletion of theme elements that still have datafields or a child/linked datatype attached to them?
            if ( count($theme_datatypes) > 0 || count($theme_datafields) > 0 )
                throw new ODRBadRequestException('Unable to delete a theme element that contains datafields or datatypes');

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

            // Update cached version of theme
            $theme_service->updateThemeCacheEntry($theme, $user);
        }
        catch (\Exception $e) {
            $source = 0x6d3a448c;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
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

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_theme_element = $em->getRepository('ODRAdminBundle:ThemeElement');

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');


            // Grab the first theme element just to check permissions
            $theme_element = null;
            foreach ($post as $index => $theme_element_id) {
                $theme_element = $repo_theme_element->find($theme_element_id);
                break;
            }
            /** @var ThemeElement $theme_element */

            $theme = $theme_element->getTheme();
            if ($theme->getDeletedAt() != null)
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
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

            // Update cached version of theme
            $theme_service->updateThemeCacheEntry($theme, $user);
        }
        catch (\Exception $e) {
            $source = 0x26e49fd1;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
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

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');


            /** @var ThemeElement $initial_theme_element */
            /** @var ThemeElement $ending_theme_element */
            $initial_theme_element = $repo_theme_element->find($initial_theme_element_id);
            $ending_theme_element = $repo_theme_element->find($ending_theme_element_id);
            if ($initial_theme_element == null || $ending_theme_element == null)
                throw new ODRNotFoundException('ThemeElement');

            if ($initial_theme_element->getTheme()->getDeletedAt() != null || $ending_theme_element->getTheme()->getDeletedAt() != null)
                throw new ODRNotFoundException('Theme');
            if ( $initial_theme_element->getTheme()->getId() !== $ending_theme_element->getTheme()->getId() )
                throw new ODRBadRequestException('Unable to move a datafield between Themes');

            $theme = $initial_theme_element->getTheme();
            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Ensure there's not a child or linked datatype in the ending theme_element before actually moving this datafield into it
            /** @var ThemeDataType[] $theme_datatypes */
            $theme_datatypes = $em->getRepository('ODRAdminBundle:ThemeDataType')
                ->findBy( array('themeElement' => $ending_theme_element_id) );
            if ( count($theme_datatypes) > 0 )
                throw new \Exception('Unable to move a Datafield into a ThemeElement that already has a child/linked Datatype');


            // ----------------------------------------
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

            // Update the cached version of the theme
            $theme_service->updateThemeCacheEntry($theme, $user);
        }
        catch (\Exception $e) {
            $source = 0x7d6d495b;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
