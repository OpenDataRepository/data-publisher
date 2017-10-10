<?php

/**
 * Open Data Repository Data Publisher
 * DisplayTemplate Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The displaytemplate controller handles everything required to
 * design the long-form view and edit layout of a database record.
 * This includes but is not limited to addition, deletion, and setting
 * of position and other related properties of DataFields, ThemeElement
 * containers, and child DataTypes.
 *
 */

namespace ODR\AdminBundle\Controller;

use ODR\AdminBundle\Component\Service\ThemeService;
use ODR\AdminBundle\Entity\ThemeMeta;
use ODR\AdminBundle\Form\Type\DatafieldType;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioOptionsMeta;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeElementMeta;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\OpenRepository\UserBundle\Entity\User;
// Forms
use ODR\AdminBundle\Form\UpdateThemeElementForm;
use ODR\AdminBundle\Form\UpdateThemeDatafieldForm;
use ODR\AdminBundle\Form\UpdateThemeDatatypeForm;
// Symfony
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
// YAML Parsing
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;
// Services
// use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\TrackedJobService;


class ThemeController extends ODRCustomController
{

    public function toggle_publicAction(
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
                throw new ODRNotFoundException('Database', false, 0x3289428);
            }

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ($user === "anon.") {
                throw new ODRForbiddenException('View', 0x1723999);
            }

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            // Ensure user has permissions to be doing this
            // Users must have view permission
            if (!$pm_service->canViewDatatype($user, $datatype)) {
                throw new ODRForbiddenException(
                    'You must have view permission on this database to make a view public.',
                    0x8923248
                );
            }

            // Check if this is a master template based datatype that is still
            // in the creation process.  If so, ask user to try again later.
            if ($datatype->getSetupStep() != DataType::STATE_OPERATIONAL) {
                // Throw error and ask user to wait
                throw new ODRForbiddenException(
                    'Please try again later.  This database is not yet fully created.',
                    0x2932008
                );
            }

            /** @var Theme $original_theme */
            $repo_theme = $em->getRepository('ODRAdminBundle:Theme');
            /** @var Theme $new_default_theme */
            $theme = $repo_theme->find($theme_id);

            if ($theme->getCreatedBy()->getId() != $user->getId()) {
                throw new ODRForbiddenException(
                    'You must the owner of the view to make it public.',
                    0x2382429
                );
            }

            // Clone New Default Meta
            /** @var ThemeMeta $theme_meta */
            $return['d'] = array();
            $theme_meta = clone $theme->getThemeMeta();
            if($theme_meta->getPublic() != null
                && $theme_meta->getPublic() <= new \DateTime()) {
                $return['d']['public'] = false;
                $theme_meta->setPublic(null);
            }
            else {
                $return['d']['public'] = true;
                $theme_meta->setPublic(new \DateTime());
            }
            $theme_meta->setCreatedBy($user);
            $theme_meta->setUpdatedBy($user);
            $theme_meta->setCreated(new \DateTime());
            $theme_meta->setUpdated(new \DateTime());
            $em->persist($theme_meta);
            $em->remove($theme->getThemeMeta());
            $em->flush();

        } catch (\Exception $e) {
            if ( $request->query->has('error_type') && $request->query->get('error_type') == 'json' )
                $request->setRequestFormat('json');

            $source = 0x1823313;
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
     * Sets a view to be the database master view.  User must be owner of new view
     * and an admin on the database.
     *
     * @param $datatype_id
     * @param $theme_id
     * @param Request $request
     * @return Response
     */
    public function make_defaultAction(
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
                throw new ODRNotFoundException('Database', false, 0x3289428);
            }

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ($user === "anon.") {
                throw new ODRForbiddenException('View', 0x1723999);
            }

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            // Ensure user has permissions to be doing this
            // Users must have view permission
            if (!$pm_service->isDatatypeAdmin($user, $datatype)) {
                throw new ODRForbiddenException(
                    'You must be an administrator on this database to set the default view.',
                    0x8923248
                );
            }

            // Check if this is a master template based datatype that is still
            // in the creation process.  If so, ask user to try again later.
            if ($datatype->getSetupStep() != DataType::STATE_OPERATIONAL) {
                // Throw error and ask user to wait
                throw new ODRForbiddenException(
                    'Please try again later.  This database is not yet fully created.',
                    0x2932008
                );
            }

            /** @var Theme $original_theme */
            $repo_theme = $em->getRepository('ODRAdminBundle:Theme');
            /** @var Theme $new_default_theme */
            $new_default_theme = $repo_theme->find($theme_id);

            if ($new_default_theme->getCreatedBy()->getId() != $user->getId()) {
                throw new ODRForbiddenException(
                    'You must the owner of the new theme to set it as the database default.',
                    0x2382429
                );
            }

            $theme_types = array();
            if($new_default_theme->getThemeType() == "master"
                || $new_default_theme->getThemeType() == "custom_view"
            ) {
                array_push($theme_types, "master");
                array_push($theme_types, "custom_view");
            }
            else {
                array_push($theme_types, $new_default_theme->getThemeType());
            }
            // Unset all other default themes....
            $default_themes = $repo_theme->findBy(array(
                'dataType' => $datatype,
                'themeType' => $theme_types
            ));

            // Set this as default for database (themeMeta.is_default)
            /** @var Theme $default_theme */
            foreach($default_themes as $default_theme) {
                if($default_theme->getThemeMeta()->getIsDefault()) {
                    // clone meta
                    /** @var ThemeMeta $default_theme_meta_clone */
                    $default_theme_meta_clone = clone $default_theme->getThemeMeta();
                    $default_theme_meta_clone->setIsDefault(0);
                    $default_theme_meta_clone->setCreatedBy($user);
                    $default_theme_meta_clone->setUpdatedBy($user);
                    $default_theme_meta_clone->setCreated(new \DateTime);
                    $default_theme_meta_clone->setUpdated(new \DateTime);
                    $em->persist($default_theme_meta_clone);
                    $em->remove($default_theme->getThemeMeta());
                }
            }

            // Clone New Default Meta
            $new_default_theme_meta = clone $new_default_theme->getThemeMeta();
            $new_default_theme_meta->setIsDefault(1);
            $new_default_theme_meta->setPublic(new \DateTime);
            $new_default_theme_meta->setCreatedBy($user);
            $new_default_theme_meta->setUpdatedBy($user);
            $new_default_theme_meta->setCreated(new \DateTime);
            $new_default_theme_meta->setUpdated(new \DateTime);
            $em->persist($new_default_theme_meta);
            $em->remove($new_default_theme->getThemeMeta());


            $em->flush();

            $return['d'] = "success";

        } catch (\Exception $e) {
            if ( $request->query->has('error_type') && $request->query->get('error_type') == 'json' )
                $request->setRequestFormat('json');

            $source = 0x1823313;
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

                /** @var ThemeService $theme_service */
                $theme_service = $this->container->get('odr.theme_service');

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
                    $theme_service->getDefaultTheme(
                        $datatype->getId(), 'master', true
                    );
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
        } catch (\Exception $e) {
            if ( $request->query->has('error_type')
                && $request->query->get('error_type') == 'json'
            )
                $request->setRequestFormat('json');

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
     * Sets the user theme and returns acknowledgement
     *
     * @param $datatype_id
     * @param $theme_id
     * @param bool $session
     * @param Request $request
     *
     */
    public function apply_themeAction(
        $datatype_id,
        $theme_id,
        $session = false,
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
            /** @var Theme $theme */
            $theme = $repo_theme->find($theme_id);

            // Is user the creator of theme or is theme public
            if($theme->getCreatedBy()->getId() == $user->getId()
            || (
                $theme->getThemeMeta()->getPublic() != null
                && $theme->getThemeMeta()->getPublic() < new \DateTime()
               )
            ) {

                /** @var ThemeService $theme_service */
                $theme_service = $this->container->get('odr.theme_service');
                if($session) {
                    $theme_service->setSessionTheme($datatype, $theme);
                }
                else {
                    // Set selected theme to session theme
                    $theme_service->setSessionTheme($datatype, $theme);
                    // Set as User Default for Datatype...
                    $theme_service->setUserDefaultTheme($datatype, $theme);

                    // Flush theme for user.
                    $theme_type = $theme->getThemeType();
                    if($theme_type == "choose_view") {
                        $theme_type = "master";
                    }
                    $theme_service->getSelectedTheme($datatype->getId(), $theme_type, true);
                }

                $return['d'] = "success";
            }
            else {
                throw new ODRForbiddenException(
                    "You do not have permissions to use this view.",
                    0x823282
                );
            }

        } catch (\Exception $e) {
            if ( $request->query->has('error_type') && $request->query->get('error_type') == 'json' )
                $request->setRequestFormat('json');

            $source = 0x823238213;
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
                'html' => self::DisplayTheme(
                    $datatype,
                    $original_theme->getThemeType(),
                    $theme_id,
                    'edit'
                )
            );

        } catch (\Exception $e) {
            if ( $request->query->has('error_type') && $request->query->get('error_type') == 'json' )
                $request->setRequestFormat('json');

            $source = 0x823238213;
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
     * Checks progress of theme job
     *
     * TODO - This should be in a tracked job controller...
     *
     * @param $tracked_job_id
     * @return TrackedJob array
     */
    public function check_theme_progressAction(
        $tracked_job_id
    ) {

        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        // Check permissions

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob');
            $tracked_job = $repo_tracked_job->find($tracked_job_id);

            $return['d'] = $tracked_job->toArray();

        } catch (\Exception $e) {
            if ( $request->query->has('error_type') && $request->query->get('error_type') == 'json' )
                $request->setRequestFormat('json');

            $source = 0x823cadf213;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

    /**
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


        } catch (\Exception $e) {
            if ( $request->query->has('error_type') && $request->query->get('error_type') == 'json' )
                $request->setRequestFormat('json');

            $source = 0x823cadf213;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    public function newsearchthemeAction($datatype_id, Request $request)
    {

        // Copy entire theme for datatype with theme_id as parent_theme_id

        // Display entire datatype edit view...

    }

    public function newtablethemeAction($datatype_id, Request $request)
    {

    }

    public function togglefieldAction($theme_data_field_id, Request $request)
    {

    }

    public function togglechildtypeAction($theme_data_field_id, Request $request)
    {

    }

    /**
     * Deletes a DataField from the DataType.
     *
     * @param integer $datafield_id The database id of the Datafield to delete.
     * @param Request $request
     *
     * @return Response
     */
    public function deletedatafieldAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            // Grab entity manager and repositories
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                return parent::deletedEntityError('DataField');
            $datatype = $datafield->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('DataType');
            $datatype_id = $datatype->getId();

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if (!(isset($datatype_permissions[$datatype->getId()]) && isset($datatype_permissions[$datatype->getId()]['dt_admin'])))
                return parent::permissionDeniedError("edit");
            // --------------------

            $datatree_array = parent::getDatatreeArray($em, true);
            $grandparent_datatype_id = parent::getGrandparentDatatypeId($datatree_array, $datatype->getId());
//            $grandparent_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($grandparent_datatype_id);

            // --------------------
            // TODO - better way of handling this?
            // Prevent deletion of datafields if a csv import is in progress, as this could screw the importing over
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy(array('job_type' => 'csv_import', 'target_entity' => 'datatype_' . $grandparent_datatype_id, 'completed' => null));   // TODO - not datatype_id, right?
            if ($tracked_job !== null)
                throw new \Exception('Preventing deletion of any DataField for this DataType, because a CSV Import for this DataType is in progress...');


            // ----------------------------------------
            // Save which themes are going to get theme_datafield entries deleted
            $query = $em->createQuery(
                'SELECT t
                FROM ODRAdminBundle:ThemeDataField AS tdf
                JOIN ODRAdminBundle:ThemeElement AS te WITH tdf.themeElement = te
                JOIN ODRAdminBundle:Theme AS t WITH te.theme = t
                WHERE tdf.dataField = :datafield
                AND tdf.deletedAt IS NULL AND te.deletedAt IS NULL AND t.deletedAt IS NULL'
            )->setParameters(array('datafield' => $datafield->getId()));
            $all_datafield_themes = $query->getResult();
            /** @var Theme[] $all_datafield_themes */

            // Save which users and groups need to delete their permission entries for this datafield
            $query = $em->createQuery(
                'SELECT g.id AS group_id
                FROM ODRAdminBundle:GroupDatafieldPermissions AS gdfp
                JOIN ODRAdminBundle:Group AS g WITH gdfp.group = g
                WHERE gdfp.dataField = :datafield
                AND gdfp.deletedAt IS NULL AND g.deletedAt IS NULL'
            )->setParameters(array('datafield' => $datafield->getId()));
            $all_affected_groups = $query->getArrayResult();

//print '<pre>'.print_r($all_affected_groups, true).'</pre>';  //exit();

            $query = $em->createQuery(
                'SELECT u.id AS user_id
                FROM ODRAdminBundle:Group AS g
                JOIN ODRAdminBundle:UserGroup AS ug WITH ug.group = g
                JOIN ODROpenRepositoryUserBundle:User AS u WITH ug.user = u
                WHERE g.id IN (:groups)
                AND g.deletedAt IS NULL AND ug.deletedAt IS NULL'
            )->setParameters(array('groups' => $all_affected_groups));
            $all_affected_users = $query->getArrayResult();

//print '<pre>'.print_r($all_affected_users, true).'</pre>'; exit();


            // Delete this datafield from all table themes and ensure all remaining datafields in the theme are still in sequential order
            self::removeDatafieldFromTableThemes($em, $user, $datafield);


            // ----------------------------------------
            // Perform a series of DQL mass updates to immediately remove everything that could break if it wasn't deleted...
            /*
                        // ...datarecordfield entries
                        $query = $em->createQuery(
                           'UPDATE ODRAdminBundle:DataRecordFields AS drf
                            SET drf.deletedAt = :now
                            WHERE drf.dataField = :datafield AND drf.deletedAt IS NULL'
                        )->setParameters( array('now' => new \DateTime(), 'datafield' => $datafield->getId()) );
                        $rows = $query->execute();
            */
            // ...theme_datafield entries
            $query = $em->createQuery(
                'UPDATE ODRAdminBundle:ThemeDataField AS tdf
                SET tdf.deletedAt = :now, tdf.deletedBy = :deleted_by
                WHERE tdf.dataField = :datafield AND tdf.deletedAt IS NULL'
            )->setParameters(array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datafield' => $datafield->getId()));
            $rows = $query->execute();

            // ...datafield permissions
            $query = $em->createQuery(
                'UPDATE ODRAdminBundle:GroupDatafieldPermissions AS gdfp
                SET gdfp.deletedAt = :now
                WHERE gdfp.dataField = :datafield AND gdfp.deletedAt IS NULL'
            )->setParameters(array('now' => new \DateTime(), 'datafield' => $datafield->getId()));
            $rows = $query->execute();


            // Ensure that the datatype dosen't continue to think the deleted datafield is something special
            $properties = array();
            // Ensure that the datatype doesn't continue to think this datafield is its external id field
            if ($datatype->getExternalIdField() !== null && $datatype->getExternalIdField()->getId() === $datafield->getId())
                $properties['externalIdField'] = null;

            // Ensure that the datatype doesn't continue to think this datafield is its name field
            if ($datatype->getNameField() !== null && $datatype->getNameField()->getId() === $datafield->getId())
                $properties['nameField'] = null;

            // Ensure that the datatype doesn't continue to think this datafield is its sort field
            if ($datatype->getSortField() !== null && $datatype->getSortField()->getId() === $datafield->getId()) {
                $properties['sortField'] = null;

                // Delete the sort order for the datatype too, so it doesn't attempt to sort on a non-existent datafield
                $redis->del($redis_prefix . '.data_type_' . $datatype->getId() . '_record_order');
            }

            // Ensure that the datatype doesn't continue to think this datafield is its background image field
            if ($datatype->getBackgroundImageField() !== null && $datatype->getBackgroundImageField()->getId() === $datafield->getId())
                $properties['backgroundImageField'] = null;

            if (count($properties) > 0)
                parent::ODR_copyDatatypeMeta($em, $user, $datatype, $properties);


            // ----------------------------------------
            // Save who deleted this datafield
            $datafield->setDeletedBy($user);
            $em->persist($datafield);
            $em->flush();

            // Done cleaning up after the datafield, delete it and its metadata
            $datafield_meta = $datafield->getDataFieldMeta();
            $em->remove($datafield_meta);
            $em->remove($datafield);

            // Save changes
            $em->flush();


            // ----------------------------------------
            // TODO - delete all storage entities for this datafield via beanstalk?  or just stack delete statements for all 12 entities in here?

            // Remove this datafield from all themes of the datafield's datatype
            $update_datatype = true;
            foreach ($all_datafield_themes as $theme) {
                parent::tmp_updateThemeCache($em, $theme, $user, $update_datatype);
                $update_datatype = false;
            }


            // ----------------------------------------
            // Wipe cached data for all the datatype's datarecords
            $query = $em->createQuery(
                'SELECT dr.id AS dr_id
                FROM ODRAdminBundle:DataRecord AS dr
                WHERE dr.dataType = :datatype_id'
            )->setParameters(array('datatype_id' => $grandparent_datatype_id));
            $results = $query->getArrayResult();

            foreach ($results as $result) {
                $dr_id = $result['dr_id'];
                $redis->del($redis_prefix . '.cached_datarecord_' . $dr_id);
                $redis->del($redis_prefix . '.datarecord_table_data_' . $dr_id);

                // TODO - schedule each of these datarecords for a recache?
            }


            // Wipe cached entries for Group and User permissions involving this datafield
            foreach ($all_affected_groups as $group) {
                $group_id = $group['group_id'];
                $redis->del($redis_prefix . '.group_' . $group_id . '_permissions');

                // TODO - schedule each of these groups for a recache?
            }

            foreach ($all_affected_users as $user) {
                $user_id = $user['user_id'];
                $redis->del($redis_prefix . '.user_' . $user_id . '_permissions');

                // TODO - schedule each of these users for a recache?
            }


            // ----------------------------------------
            // See if any cached search results need to be deleted...
            $cached_searches = parent::getRedisData(($redis->get($redis_prefix . '.cached_search_results')));
            if ($cached_searches != false && isset($cached_searches[$grandparent_datatype_id])) {
                // Delete all cached search results for this datatype that were run with criteria for this specific datafield
                foreach ($cached_searches[$grandparent_datatype_id] as $search_checksum => $search_data) {
                    $searched_datafields = $search_data['searched_datafields'];
                    $searched_datafields = explode(',', $searched_datafields);

                    if (in_array($datafield_id, $searched_datafields))
                        unset($cached_searches[$grandparent_datatype_id][$search_checksum]);
                }

                // Save the collection of cached searches back to memcached
                $redis->set($redis_prefix . '.cached_search_results', gzcompress(serialize($cached_searches)));
            }
        } catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x18392883 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes a RadioOption entity from a Datafield.
     *
     * @param integer $radio_option_id The database id of the RadioOption to delete.
     * @param Request $request
     *
     * @return Response
     */
    public function deleteradiooptionAction($radio_option_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var RadioOptions $radio_option */
            $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->find($radio_option_id);
            if ($radio_option == null)
                return parent::deletedEntityError('RadioOption');

            $datafield = $radio_option->getDataField();
            if ($datafield == null)
                return parent::deletedEntityError('Datafield');
            $datafield_id = $datafield->getId();

            $datatype = $datafield->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');
            $datatype_id = $datatype->getId();

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy(array('dataType' => $datatype->getId(), 'themeType' => 'master'));
            if ($theme == null)
                return parent::deletedEntityError('Theme');


            $datatree_array = parent::getDatatreeArray($em, true);
            $grandparent_datatype_id = parent::getGrandparentDatatypeId($datatree_array, $datatype->getId());

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if (!(isset($datatype_permissions[$datatype->getId()]) && isset($datatype_permissions[$datatype->getId()]['dt_admin'])))
                return parent::permissionDeniedError("change layout of");
            // --------------------


            // ----------------------------------------
            // Save which themes are currently using this datafield
            $query = $em->createQuery(
                'SELECT t
                FROM ODRAdminBundle:ThemeDataField AS tdf
                JOIN ODRAdminBundle:ThemeElement AS te WITH tdf.themeElement = te
                JOIN ODRAdminBundle:Theme AS t WITH te.theme = t
                WHERE tdf.dataField = :datafield
                AND tdf.deletedAt IS NULL AND te.deletedAt IS NULL AND t.deletedAt IS NULL'
            )->setParameters(array('datafield' => $datafield->getId()));
            $all_datafield_themes = $query->getResult();
            /** @var Theme[] $all_datafield_themes */


            // Delete all radio selection entities attached to the radio option
            $query = $em->createQuery(
                'UPDATE ODRAdminBundle:RadioSelection AS rs
                SET rs.deletedAt = :now
                WHERE rs.radioOption = :radio_option_id AND rs.deletedAt IS NULL'
            )->setParameters(array('now' => new \DateTime(), 'radio_option_id' => $radio_option_id));
            $updated = $query->execute();


            // Save who deleted this radio option
            $radio_option->setDeletedBy($user);
            $em->persist($radio_option);
            $em->flush($radio_option);

            // Delete the radio option and its current associated metadata entry
            $radio_option_meta = $radio_option->getRadioOptionMeta();
            $em->remove($radio_option);
            $em->remove($radio_option_meta);
            $em->flush();


            // Schedule the cache for an update
            // TODO - how to update all datarecords of this datatype?
            $update_datatype = true;
            foreach ($all_datafield_themes as $theme) {
                parent::tmp_updateThemeCache($em, $theme, $user, $update_datatype);
                $update_datatype = false;
            }


            // ----------------------------------------
            // Wipe cached data for the grandparent datatype
//            $redis->del($redis_prefix.'.cached_datatype_'.$grandparent_datatype_id);

            // Wipe cached data for all the datatype's datarecords
            $query = $em->createQuery(
                'SELECT dr.id AS dr_id
                FROM ODRAdminBundle:DataRecord AS dr
                WHERE dr.dataType = :datatype_id'
            )->setParameters(array('datatype_id' => $grandparent_datatype_id));
            $results = $query->getArrayResult();

            foreach ($results as $result) {
                $dr_id = $result['dr_id'];
                $redis->del($redis_prefix . '.cached_datarecord_' . $dr_id);
                $redis->del($redis_prefix . '.datarecord_table_data_' . $dr_id);

                // TODO - schedule each of these datarecords for a recache?
            }

            // See if any cached search results need to be deleted...
            $cached_searches = parent::getRedisData(($redis->get($redis_prefix . '.cached_search_results')));
            if ($cached_searches != false && isset($cached_searches[$grandparent_datatype_id])) {
                // Delete all cached search results for this datatype that were run with criteria for this specific datafield
                foreach ($cached_searches[$grandparent_datatype_id] as $search_checksum => $search_data) {
                    $searched_datafields = $search_data['searched_datafields'];
                    $searched_datafields = explode(',', $searched_datafields);

                    if (in_array($datafield_id, $searched_datafields))
                        unset($cached_searches[$grandparent_datatype_id][$search_checksum]);
                }

                // Save the collection of cached searches back to memcached
                $redis->set($redis_prefix . '.cached_search_results', gzcompress(serialize($cached_searches)));
            }

        } catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x18392884444 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Toggles whether a given RadioOption entity is automatically selected upon creation of a new datarecord.
     *
     * @param integer $radio_option_id The database id of the RadioOption to modify.
     * @param Request $request
     *
     * @return Response
     */
    public function defaultradiooptionAction($radio_option_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_radio_option_meta = $em->getRepository('ODRAdminBundle:RadioOptionsMeta');

            /** @var RadioOptions $radio_option */
            $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->find($radio_option_id);
            if ($radio_option == null)
                return parent::deletedEntityError('RadioOption');
            $datafield = $radio_option->getDataField();
            if ($datafield == null)
                return parent::deletedEntityError('Datafield');
            $datatype = $datafield->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy(array('dataType' => $datatype->getId(), 'themeType' => 'master'));
            if ($theme == null)
                return parent::deletedEntityError('Theme');


            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if (!(isset($datatype_permissions[$datatype->getId()]) && isset($datatype_permissions[$datatype->getId()]['dt_admin'])))
                return parent::permissionDeniedError("change layout of");
            // --------------------


            $field_typename = $datafield->getFieldType()->getTypeName();
            if ($field_typename == 'Single Radio' || $field_typename == 'Single Select') {
                // Only one option allowed to be default for Single Radio/Select DataFields, find the other option(s) where isDefault == true
                $query = $em->createQuery(
                    'SELECT rom.id
                    FROM ODRAdminBundle:RadioOptionsMeta AS rom
                    JOIN ODRAdminBundle:RadioOptions AS ro WITH rom.radioOption = ro
                    WHERE rom.isDefault = 1 AND ro.dataField = :datafield
                    AND rom.deletedAt IS NULL AND ro.deletedAt IS NULL'
                )->setParameters(array('datafield' => $datafield->getId()));
                $results = $query->getResult();

                foreach ($results as $num => $result) {
                    /** @var RadioOptionsMeta $radio_option_meta */
                    $radio_option_meta = $repo_radio_option_meta->find($result['id']);
                    $ro = $radio_option_meta->getRadioOption();

                    $properties = array(
                        'isDefault' => false
                    );
                    parent::ODR_copyRadioOptionsMeta($em, $user, $ro, $properties);
                }

                // TODO - currently not allowed to remove a default option from one of these fields once a a default has been set
                // Set this radio option as selected by default
                $properties = array(
                    'isDefault' => true
                );
                parent::ODR_copyRadioOptionsMeta($em, $user, $radio_option, $properties);
            } else {
                // Multiple options allowed as defaults, toggle default status of current radio option
                $properties = array(
                    'isDefault' => true
                );
                if ($radio_option->getIsDefault() == true)
                    $properties['isDefault'] = false;

                parent::ODR_copyRadioOptionsMeta($em, $user, $radio_option, $properties);
            }

            /*
                        // Schedule the cache for an update
                        $options = array();
                        $options['mark_as_updated'] = true;
                        parent::updateDatatypeCache($datatype->getId(), $options);
            */
            $update_datatype = true;
            parent::tmp_updateThemeCache($em, $theme, $user, $update_datatype);
        } catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x1397284454 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes an entire DataType and all of the entities directly related to rendering it.
     *
     * @param integer $datatype_id The database id of the DataType to be deleted.
     * @param Request $request
     *
     * @return Response
     */
    public function deletedatatypeAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('DataType');

            $top_level_datatypes = parent::getTopLevelDatatypes();

            $datatree_array = parent::getDatatreeArray($em, true);
            $grandparent_datatype_id = parent::getGrandparentDatatypeId($datatree_array, $datatype->getId());

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if (!(isset($datatype_permissions[$datatype->getId()]) && isset($datatype_permissions[$datatype->getId()]['dt_admin'])))
                return parent::permissionDeniedError("delete");
            // --------------------

            // TODO - prevent datatype deletion when jobs are in progress?


            // ----------------------------------------
            // Locate ids of all datatypes that need deletion
            $tmp = array($datatype->getId() => 0);

            $datatypes_to_delete = array(0 => $datatype->getId());
            while (count($tmp) > 0) {
                $new_tmp = array();
                foreach ($tmp as $dt_id => $num) {
                    $child_datatype_ids = array_keys($datatree_array['descendant_of'], $dt_id);

                    foreach ($child_datatype_ids as $num => $child_datatype_id) {
                        $new_tmp[$child_datatype_id] = 0;
                        $datatypes_to_delete[] = $child_datatype_id;
                    }

                    unset($tmp[$dt_id]);
                }
                $tmp = $new_tmp;
            }

            $datatypes_to_delete = array_unique($datatypes_to_delete);
            $datatypes_to_delete = array_values($datatypes_to_delete);

//print '<pre>'.print_r($datatypes_to_delete, true).'</pre>'; exit();

            // Determine all Groups and all Users affected by this
            $query = $em->createQuery(
                'SELECT g.id AS group_id
                FROM ODRAdminBundle:Group AS g
                WHERE g.dataType IN (:datatype_ids)
                AND g.deletedAt IS NULL'
            )->setParameters(array('datatype_ids' => $datatypes_to_delete));
            $groups_to_delete = $query->getArrayResult();

//print '<pre>'.print_r($groups_to_delete, true).'</pre>';  exit();

            $query = $em->createQuery(
                'SELECT u.id AS user_id
                FROM ODRAdminBundle:UserGroup AS ug
                JOIN ODROpenRepositoryUserBundle:User AS u WITH ug.user = u
                WHERE ug.group IN (:groups) AND ug.deletedAt IS NULL'
            )->setParameters(array('groups' => $groups_to_delete));
            $all_affected_users = $query->getArrayResult();

//print '<pre>'.print_r($all_affected_users, true).'</pre>';  exit();


            // ----------------------------------------
            /*
                        // Delete Datarecordfield entries
                        $query = $em->createQuery(
                           'UPDATE ODRAdminBundle:DataRecord AS dr, ODRAdminBundle:DataRecordFields AS drf
                            SET drf.deletedAt = :now
                            WHERE drf.dataRecord = dr
                            AND dr.dataType IN (:datatype_ids)
                            AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL'
                        )->setParameters( array('now' => new \DateTime(), 'datatype_ids' => $datatypes_to_delete) );
                        $query->execute();
            */
            // Delete LinkedDatatree entries...can't do multi-table updates in Doctrine, so have to split it apart into three queries
            $query = $em->createQuery(
                'SELECT ancestor.id AS ancestor_id
                FROM ODRAdminBundle:DataRecord AS ancestor
                WHERE ancestor.dataType IN (:datatype_ids)
                AND ancestor.deletedAt IS NULL'
            )->setParameters(array('datatype_ids' => $datatypes_to_delete));
            $results = $query->getArrayResult();

            $ancestor_ids = array();
            foreach ($results as $result)
                $ancestor_ids[] = $result['ancestor_id'];

            $query = $em->createQuery(
                'SELECT descendant.id AS descendant_id
                FROM ODRAdminBundle:DataRecord AS descendant
                WHERE descendant.dataType IN (:datatype_ids)
                AND descendant.deletedAt IS NULL'
            )->setParameters(array('datatype_ids' => $datatypes_to_delete));
            $results = $query->getArrayResult();

            $descendant_ids = array();
            foreach ($results as $result)
                $descendant_ids[] = $result['descendant_id'];

            $query = $em->createQuery(
                'UPDATE ODRAdminBundle:LinkedDataTree AS ldt
                SET ldt.deletedAt = :now, ldt.deletedBy = :deleted_by
                WHERE (ldt.ancestor IN (:ancestor_ids) OR ldt.descendant IN (:descendant_ids))
                AND ldt.deletedAt IS NULL'
            )->setParameters(array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'ancestor_ids' => $ancestor_ids, 'descendant_ids' => $descendant_ids));
            $query->execute();

            /*
                        // Delete Datarecord and DatarecordMeta entries
                        $query = $em->createQuery(
                            'UPDATE ODRAdminBundle:DataRecord AS dr, ODRAdminBundle:DataRecordMeta AS drm
                            SET dr.deletedAt = :now, dr.deletedBy = :deleted_by, drm.deletedAt = :now
                            WHERE drm.dataRecord = dr
                            AND dr.dataType IN (:datatype_ids)
                            AND dr.deletedAt IS NULL AND drm.deletedAt IS NULL'
                        )->setParameters( array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datatype_ids' => $datatypes_to_delete) );
                        $query->execute();
            */

            // ----------------------------------------
            /*
                        // Delete GroupDatafieldPermission entries (cached versions deleted later)
                        $query = $em->createQuery(
                           'UPDATE ODRAdminBundle:DataFields AS df, ODRAdminBundle:GroupDatafieldPermissions AS gdfp
                            SET gdfp.deletedAt = :now
                            WHERE gdfp.dataField = df
                            AND df.dataType IN (:datatype_ids)
                            AND df.deletedAt IS NULL AND gdfp.deletedAt IS NULL'
                        )->setParameters( array('now' => new \DateTime(), 'datatype_ids' => $datatypes_to_delete) );
                        $query->execute();

                        // Delete Datafields and their DatafieldMeta entries
                        $query = $em->createQuery(
                           'UPDATE ODRAdminBundle:DataFields AS df, ODRAdminBundle:DataFieldsMeta AS dfm
                            SET df.deletedAt = :now, df.deletedBy = :deleted_by, dfm.deletedAt = :now
                            WHERE dfm.dataField = df
                            AND df.dataType IN (:datatype_ids)
                            AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL'
                        )->setParameters( array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datatype_ids' => $datatypes_to_delete) );
                        $query->execute();
            */

            // ----------------------------------------
            /*
                        // Delete all ThemeDatatype entries
                        $query = $em->createQuery(
                           'UPDATE ODRAdminBundle:ThemeDataType AS tdt, ODRAdminBundle:ThemeElement AS te, ODRAdminBundle:Theme AS t
                            SET tdt.deletedAt = :now, tdt.deletedBy = :deleted_by
                            WHERE tdt.themeElement = te AND te.theme = t
                            AND t.dataType IN (:datatype_ids)
                            AND tdt.deletedAt IS NULL AND te.deletedAt IS NULL AND t.deletedAt IS NULL'
                        )->setParameters( array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datatype_ids' => $datatypes_to_delete) );
                        $query->execute();
            */
            // Delete any leftover ThemeDatatype entries that refer to $datatypes_to_delete...these would be other datatypes linking to the ones being deleted
            // (if block above is commented, then it'll also arbitrarily delete themeDatatype entries for child datatypes)
            $query = $em->createQuery(
                'UPDATE ODRAdminBundle:ThemeDataType AS tdt
                SET tdt.deletedAt = :now, tdt.deletedBy = :deleted_by
                WHERE tdt.dataType IN (:datatype_ids)
                AND tdt.deletedAt IS NULL'
            )->setParameters(array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datatype_ids' => $datatypes_to_delete));
            $query->execute();
            /*
                        // Delete all ThemeDatafield entries
                        $query = $em->createQuery(
                           'UPDATE ODRAdminBundle:ThemeDataField AS tdf, ODRAdminBundle:ThemeElement AS te, ODRAdminBundle:Theme AS t
                            SET tdf.deletedAt = :now, tdf.deletedBy = :deleted_by
                            WHERE tdf.themeElement = te AND te.theme = t
                            AND t.dataType IN (:datatype_ids)
                            AND tdf.deletedAt IS NULL AND te.deletedAt IS NULL AND t.deletedAt IS NULL'
                        )->setParameters( array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datatype_ids' => $datatypes_to_delete) );
                        $query->execute();

                        // Delete all ThemeElement and ThemeElementMeta entries
                        $query = $em->createQuery(
                           'UPDATE ODRAdminBundle:ThemeElement AS te, ODRAdminBundle:ThemeElementMeta AS tem ODRAdminBundle:Theme AS t
                            SET te.deletedAt = :now, te.deletedBy = :deleted_by, tem.deletedAt = :now
                            WHERE tem.themeElement = te AND te.theme = t
                            AND t.dataType IN (:datatype_ids)
                            AND te.deletedAt IS NULL AND tem.deletedAt IS NULL AND t.deletedAt IS NULL'
                        )->setParameters( array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datatype_ids' => $datatypes_to_delete) );
                        $query->execute();

                        // Delete all Theme and ThemeMeta entries
                        $query = $em->createQuery(
                           'UPDATE ODRAdminBundle:Theme AS t, ODRAdminBundle:ThemeMeta AS tm
                            SET t.deletedAt = :now, t.deletedBy = :deleted_by, tm.deletedAt = :now
                            WHERE tm.theme = t
                            AND t.dataType IN (:datatype_ids)
                            AND t.deletedAt IS NULL AND tm.deletedAt IS NULL'
                        )->setParameters( array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datatype_ids' => $datatypes_to_delete) );
                        $query->execute();
            */

            // ----------------------------------------
            // Delete all Datatree and DatatreeMeta entries
            $query = $em->createQuery(
                'SELECT dt.id AS dt_id
                FROM ODRAdminBundle:DataTree AS dt
                WHERE (dt.ancestor IN (:datatype_ids) OR dt.descendant IN (:datatype_ids) )
                AND dt.deletedAt IS NULL'
            )->setParameters(array('datatype_ids' => $datatypes_to_delete));
            $results = $query->getArrayResult();

            $datatree_ids = array();
            foreach ($results as $result)
                $datatree_ids[] = $result['dt_id'];

            $query = $em->createQuery(
                'UPDATE ODRAdminBundle:DataTreeMeta AS dtm
                SET dtm.deletedAt = :now
                WHERE dtm.dataTree IN (:datatree_ids)
                AND dtm.deletedAt IS NULL'
            )->setParameters(array('now' => new \DateTime(), 'datatree_ids' => $datatree_ids));
            $query->execute();

            $query = $em->createQuery(
                'UPDATE ODRAdminBundle:DataTree AS dt
                SET dt.deletedAt = :now, dt.deletedBy = :deleted_by
                WHERE dt.id IN (:datatree_ids)
                AND dt.deletedAt IS NULL'
            )->setParameters(array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datatree_ids' => $datatree_ids));
            $query->execute();


            // ----------------------------------------
            /*
                        // Delete GroupDatatypePermission entries (cached versions deleted later)
                        $query = $em->createQuery(
                           'UPDATE ODRAdminBundle:GroupDatatypePermissions AS gdtp
                            SET gdtp.deletedAt = :now
                            WHERE gdtp.dataType IN (:datatype_ids)
                            AND gdtp.deletedAt IS NULL'
                        )->setParameters( array('now' => new \DateTime(), 'datatype_ids' => $datatypes_to_delete) );
                        $query->execute();

                        // Delete Groups and their GroupMeta entries
                        $query = $em->createQuery(
                           'UPDATE ODRAdminBundle:Group AS g, ODRAdminBundle:GroupMeta AS gm
                            SET g.deletedAt = :now, g.deletedBy = :deleted_by, gm.deletedAt = :now
                            WHERE gm.group = g
                            AND g.dataType IN (:datatype_ids)
                            AND g.deletedAt IS NULL AND gm.deletedAt IS NULL'
                        )->setParameters( array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datatype_ids' => $datatypes_to_delete) );
                        $query->execute();
            */

            // Remove members from the Groups for this Datatype
            $query = $em->createQuery(
                'UPDATE ODRAdminBundle:UserGroup AS ug
                SET ug.deletedAt = :now, ug.deletedBy = :deleted_by
                WHERE ug.group IN (:group_ids)
                AND ug.deletedAt IS NULL'
            )->setParameters(array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'group_ids' => $groups_to_delete));
            $query->execute();


            // ----------------------------------------
            // Delete all Datatype and DatatypeMeta entries
            $query = $em->createQuery(
                'UPDATE ODRAdminBundle:DataTypeMeta AS dtm
                SET dtm.deletedAt = :now
                WHERE dtm.dataType IN (:datatype_ids)
                AND dtm.deletedAt IS NULL'
            )->setParameters(array('now' => new \DateTime(), 'datatype_ids' => $datatypes_to_delete));
            $query->execute();

            $query = $em->createQuery(
                'UPDATE ODRAdminBundle:DataType AS dt
                SET dt.deletedAt = :now, dt.deletedBy = :deleted_by
                WHERE dt.id IN (:datatype_ids)
                AND dt.deletedAt IS NULL'
            )->setParameters(array('now' => new \DateTime(), 'deleted_by' => $user->getId(), 'datatype_ids' => $datatypes_to_delete));
            $query->execute();


            // ----------------------------------------
            // Delete cached versions of all Datarecords of this Datatype if needed
            if ($datatype->getId() == $grandparent_datatype_id) {
                $query = $em->createQuery(
                    'SELECT dr.id AS dr_id
                    FROM ODRAdminBundle:DataRecord AS dr
                    WHERE dr.dataType = :datatype_id'
                )->setParameters(array('datatype_id' => $grandparent_datatype_id));
                $results = $query->getArrayResult();

//print '<pre>'.print_r($results, true).'</pre>';  exit();

                foreach ($results as $result) {
                    $dr_id = $result['dr_id'];

                    $redis->del($redis_prefix . '.cached_datarecord_' . $dr_id);
                    $redis->del($redis_prefix . '.datarecord_table_data_' . $dr_id);
                    $redis->del($redis_prefix . '.associated_datarecords_for_' . $dr_id);
                }
            }

            // Delete cached entries for Group and User permissions involving this Datafield
            foreach ($groups_to_delete as $group) {
                $group_id = $group['group_id'];
                $redis->del($redis_prefix . '.group_' . $group_id . '_permissions');

                // TODO - schedule each of these groups for a recache?
            }

            foreach ($all_affected_users as $user) {
                $user_id = $user['user_id'];
                $redis->del($redis_prefix . '.user_' . $user_id . '_permissions');

                // TODO - schedule each of these users for a recache?
            }

            // ...cached searches
            $cached_searches = parent::getRedisData(($redis->get($redis_prefix . '.cached_search_results')));
            if ($cached_searches != false && isset($cached_searches[$datatype_id])) {
                unset($cached_searches[$datatype_id]);

                // Save the collection of cached searches back to memcached
                $redis->set($redis_prefix . '.cached_search_results', gzcompress(serialize($cached_searches)));
            }

            // ...layout data
            foreach ($datatypes_to_delete as $num => $dt_id)
                $redis->del($redis_prefix . '.cached_datatype_' . $dt_id);

            // ...and the cached version of the datatree array
            $redis->del($redis_prefix . '.cached_datatree_array');
        } catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x1883778 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Loads and returns the DesignTemplate HTML for this DataType.
     *
     * @param integer $datatype_id The database id of the DataType to be rendered.
     * @param string $template_type The type of template to be designed/modified [default: master].
     * @param integer $template_id If provided, the corresponding template will be loaded [default: 0].
     * @param Request $request
     *
     * @return Response
     */
    public function designAction(
        $datatype_id,
        $theme_type = "search_results",
        $theme_id = 0,
        Request $request
    )
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException("Datatype");

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($user);

            // Ensure user has permissions to be doing this
            // TODO Alternate settings for individual users to create their own themes...
            if (!(isset($datatype_permissions[$datatype->getId()])
                && isset($datatype_permissions[$datatype->getId()]['dt_admin'])))
                throw new ODRForbiddenExceptions("edit");
            // --------------------


            // Check if this is a master template based datatype that is still
            // in the creation process.  If so, redirect to progress system.
            if ($datatype->getSetupStep() == DataType::STATE_INITIAL
                && $datatype->getIsMasterType() == 0) {
                // Return creating datatype template
                $templating = $this->get('templating');
                $return['t'] = "html";
                $return['d'] = array();
                $return['d']['html'] = $templating->render(
                    'ODRAdminBundle:Datatype:create_status_checker.html.twig',
                    array("datatype" => $datatype)
                );
            } else {
                $return['d'] = array(
                    'datatype_id' => $datatype->getId(),
                    'html' => self::DisplayTheme($datatype, $theme_type, $theme_id)
                );
            }
        } catch (\Exception $e) {
            if ($e instanceof ODRException) {
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode());
            }
            else {
                throw new ODRException($e->getMessage(), 500,0x288399, $e);
            }
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
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
        /** @var PermissionsManagementService $pm_service */
        $pm_service = $this->container->get('odr.permissions_management_service');
        /** @var DatatypeInfoService $dti_service */
        $dti_service = $this->container->get('odr.datatype_info_service');

        // Required objects
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $repo_theme = $em->getRepository('ODRAdminBundle:Theme');


        // ----------------------------------------
        /** @var Theme $theme */
        $theme = null;

        if ($theme_id > 0) {
            $theme = $repo_theme->find($theme_id);
        } else {
            $theme = $repo_theme->findOneBy(
                array(
                    'dataType' => $datatype->getId(),
                    'themeType' => $template_type
                )
            );
        }

        // ----------------------------------------
        // Determine whether the user is an admin of this datatype
        /** @var User $user */
        $user = $this->container->get('security.token_storage')->getToken()->getUser();

        // Anonymous users can not design their own themes.
        if($user === "anon.")
            throw new ODRForbiddenException("Access denied.");

        $datatype_permissions = $pm_service->getDatatypePermissions($user);

        $is_datatype_admin = false;
        if (isset($datatype_permissions[$datatype->getId()])
            && isset($datatype_permissions[$datatype->getId()]['dt_admin'])
        )
            $is_datatype_admin = true;


        // If theme is null, we need to create them cloning master.
        // Create Datatype Service....
        if ($theme == null) {
            /** @var ThemeService $theme_service */
            $theme_service = $this->container->get('odr.theme_service');
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
        // Determine which datatypes/childtypes to load from the cache
        $include_links = true;
        $associated_datatypes = $dti_service->getAssociatedDatatypes(
            array($datatype->getId()), $include_links
        );


        // Grab the cached versions of all of the associated datatypes, and store them all at the same level in a single array
        $datatype_array = $dti_service->getDatatypeArray($associated_datatypes, $theme->getId(), true);

        // ----------------------------------------
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

        // ----------------------------------------
        // Render the required version of the page
        $templating = $this->get('templating');

        $html = $templating->render(
            'ODRAdminBundle:Theme:theme_ajax.html.twig',
            array(
                'datatype_array' => $datatype_array,
                'initial_datatype_id' => $datatype->getId(),
                'theme_id' => $theme->getId(),

                'is_datatype_admin' => $is_datatype_admin,

                'fieldtype_array' => $fieldtype_array,
                'has_datarecords' => $has_datarecords,
                'display_mode' => $display_mode,
            )
        );

        return $html;
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
            if (!(isset($datatype_permissions[$datatype->getId()]) && isset($datatype_permissions[$datatype->getId()]['dt_admin'])))
                return parent::permissionDeniedError("edit");
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
                } else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($theme_element_form);
                    throw new \Exception($error_str);
                }
            } else {

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

        } catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x216225700 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
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


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                return parent::deletedEntityError('Datafield');

            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                return parent::deletedEntityError('ThemeElement');

            $theme_id = $theme_element->getTheme()->getId();

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_element->getTheme()->getId());
            if ($theme == null)
                return parent::deletedEntityError('Theme');

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                return parent::deletedEntityError('Datatype');

            if ($datafield->getDataType()->getId() !== $datatype->getId())
                throw new \Exception('Invalid Form');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if (!(isset($datatype_permissions[$datatype->getId()]) && isset($datatype_permissions[$datatype->getId()]['dt_admin'])))
                return parent::permissionDeniedError("edit");
            // --------------------

            // Locate the ThemeDatafield entity
            $query = $em->createQuery(
                'SELECT tdf
                FROM ODRAdminBundle:ThemeDataField AS tdf
                JOIN ODRAdminBundle:ThemeElement AS te WITH tdf.themeElement = te
                WHERE tdf.dataField = :datafield_id AND te.id = :theme_element_id
                AND tdf.deletedAt IS NULL AND te.deletedAt IS NULL'
            )->setParameters(array('datafield_id' => $datafield->getId(), 'theme_element_id' => $theme_element_id));
            $results = $query->getResult();

            /** @var ThemeDataField $theme_datafield */
            $theme_datafield = $results[0];
            if ($theme_datafield == null)
                return parent::deletedEntityError('Theme Datafield');

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
        } catch (\Exception $e) {
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
     * @param integer $theme_element_id
     * @param integer $datafield_id
     * @param Request $request
     *
     * @return Response
     */
    public function savethemedatafieldAction(
        $theme_element_id,
        $datafield_id,
        Request $request
    ) {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ThemeDataField $theme_datafield */
            $theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField')
                ->findOneBy(
                    array(
                        'themeElement' => $theme_element_id,
                        'dataField' => $datafield_id
                    )
                );

            if ($theme_datafield == null)
                return parent::deletedEntityError('ThemeDatafield');

            /** @var ThemeElement $theme_element */
            $theme_element = $theme_datafield->getThemeElement();
            if ($theme_element == null)
                return parent::deletedEntityError('ThemeElement');

            /** @var Theme $theme */
            $theme = $theme_element->getTheme();
            if ($theme == null)
                return parent::deletedEntityError('Theme');

            /** @var Datafields $datafield */
            $datafield = $theme_datafield->getDataField();
            if ($datafield == null)
                return parent::deletedEntityError('Datafield');

            /** @var DataType $datatype */
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
            if (!(isset($datatype_permissions[$datatype->getId()])
                && isset($datatype_permissions[$datatype->getId()]['dt_admin']))
            ) {
                return parent::permissionDeniedError("edit");
            }
            // --------------------

            if ($theme->getThemeType() == 'table')
                throw new \Exception('Unable to change properties of a Table theme');


            // Populate new ThemeDataField form
            $submitted_data = new ThemeDataField();
            $theme_datafield_form = $this->createForm(UpdateThemeDatafieldForm::class, $submitted_data);

            $widths = array(
                'med_width_old' => $theme_datafield->getCssWidthMed(),
                'xl_width_old' => $theme_datafield->getCssWidthXL(),
                'hidden' => $theme_datafield->getHidden()
            );

            $theme_datafield_form->handleRequest($request);

            if ($theme_datafield_form->isSubmitted()) {

                if ($theme_datafield_form->isValid()) {
                    // Save all changes made via the form
                    $new_theme_datafield = clone $theme_datafield;
                    $new_theme_datafield->setCssWidthMed($submitted_data->getCssWidthMed());
                    $new_theme_datafield->setCssWidthXL($submitted_data->getCssWidthXL());
                    $new_theme_datafield->setHidden($submitted_data->getHidden());
                    $new_theme_datafield->setUpdatedBy($user);
                    $new_theme_datafield->setCreatedBy($user);
                    $new_theme_datafield->setCreated(new \DateTime());
                    $new_theme_datafield->setUpdated(new \DateTime());
                    $em->persist($new_theme_datafield);
                    $em->remove($theme_datafield);
                    $em->flush();


                    $widths['med_width_current'] = $new_theme_datafield->getCssWidthMed();
                    $widths['xl_width_current'] = $new_theme_datafield->getCssWidthXL();
                    $widths['hidden'] = $new_theme_datafield->getHidden();

                    // Theme changes are cached with the Datatype (ugh)
                    /** @var DatatypeInfoService $datatype_info_service */
                    $datatype_info_service = $this->container->get('odr.datatype_info_service');
                    $datatype_data = $datatype_info_service
                        ->getDatatypeArray([$datatype->getId()], $theme->getId(),true);
                } else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($theme_datafield_form);
                    throw new \Exception($error_str);
                }
            }

            // Don't need to return a form object...it's loaded with the regular datafield properties form
            $return['widths'] = $widths;
        } catch (\Exception $e) {
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
     * @param integer $theme_element_id
     * @param integer $datatype_id
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

// TODO - Conflicted
//            $redis = $this->container->get('snc_redis.default');;
//            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
//            $redis_prefix = $this->container->getParameter('memcached_key_prefix');
//
//            /** @var ThemeElement $theme_element */
//            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            /** @var ThemeDataType $theme_datatype */
            $theme_datatype = $em->getRepository('ODRAdminBundle:ThemeDataType')->findOneBy(
                array(
                    'themeElement' => $theme_element_id,
                    'dataType' => $datatype_id
                )
            );

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


            // Allow header to be hidden for non-multiple-allowed child types
            $display_choices = array(
                'Accordion' => '0',
                'Tabbed' => '1',
                'Select Box' => '2',
                'List' => '3'
            );

            // Check if multiple are allowed for datatype
            $data_tree = $em->getRepository('ODRAdminBundle:DataTree')->findOneBy(
                array(
                    'ancestor' => $theme_element->getTheme()->getDataType()->getId(),
                    'descendant' => $datatype_id
                )
            );

// TODO - Conflicted
//                // TODO - empty table themes still count as having table themes?
//
//                // Since a datafield got detached from this table theme, the cached table versions of all affected datarecords need to be deleted
//                $query = $em->createQuery(
//                   'SELECT dr.id AS dr_id
//                    FROM ODRAdminBundle:DataRecord AS dr
//                    WHERE dr.dataType = :datatype_id
//                    AND dr.deletedAt IS NULL'
//                )->setParameters(array('datatype_id' => $datatype->getId()));
//                $results = $query->getArrayResult();
//
//                foreach ($results as $result) {
//                    $dr_id = $result['dr_id'];
//                    $redis->del($redis_prefix.'.datarecord_table_data_'.$dr_id);
//                }

            if($data_tree->getDataTreeMeta()->getMultipleAllowed() == false) {
                $display_choices = array(
                    'Accordion' => '0',
                    'Tabbed' => '1',
                    'Select Box' => '2',
                    'List' => '3',
                    'Hide Header' => '4'
                );
            }

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

            /** @var ThemeDataType $theme_datatype */
            $theme_datatype = $em->getRepository('ODRAdminBundle:ThemeDataType')->findOneBy( array('themeElement' => $theme_element_id, 'dataType' => $datatype_id) );
            if ($theme_datatype == null)
                return parent::deletedEntityError('ThemeDatatype');

            $theme_element = $theme_datatype->getThemeElement();
            if ($theme_element->getDeletedAt() != null)
                return parent::deletedEntityError('ThemeElement');

            $theme = $theme_element->getTheme();
            if ($theme->getDeletedAt() != null)
                return parent::deletedEntityError('Theme');

            $datatype = $theme->getDataType();                  // Note that this is $datatype_id's parent datatype
            if ($datatype->getDeletedAt() != null)
                return parent::deletedEntityError('Datatype');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            // TODO What permissions should a user have to do this?
            // Probably depends on theme type (Search Result -> datatype admin)
            // UserDisplay -> Theme Creator?
            if ( !(isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_admin' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // Populate new ThemeDataType form
            $submitted_data = new ThemeDataType();




            // Allow header to be hidden for non-multiple-allowed child types
            $display_choices = array(
                'Accordion' => '0',
                'Tabbed' => '1',
                'Select Box' => '2',
                'List' => '3'
            );

            // Check if multiple are allowed for datatype
            $data_tree = $em->getRepository('ODRAdminBundle:DataTree')->findOneBy(
                array(
                    'ancestor' => $theme_element->getTheme()->getDataType()->getId(),
                    'descendant' => $datatype_id
                )
            );

            if($data_tree->getDataTreeMeta()->getMultipleAllowed() == false) {
                $display_choices = array(
                    'Accordion' => '0',
                    'Tabbed' => '1',
                    'Select Box' => '2',
                    'List' => '3',
                    'Hide Header' => '4'
                );
            }

            // Create the ThemeDatatype form object
            $theme_datatype_form = $this->createForm(
                UpdateThemeDatatypeForm::class,
                $submitted_data,
                array(
                    'display_choices' => $display_choices
                )
            );

            // $theme_datatype_form = $this->createForm(UpdateThemeDatatypeForm::class, $submitted_data);

            $theme_datatype_form->handleRequest($request);

            if ($theme_datatype_form->isSubmitted()) {

                if ($theme_datatype_form->isValid()) {
                    // Save all changes made via the form
                    $new_theme_datatype = clone $theme_datatype;
                    $new_theme_datatype->setDisplayType($submitted_data->getDisplayType());
                    $new_theme_datatype->setHidden($submitted_data->getHidden());
                    $new_theme_datatype->setUpdatedBy($user);
                    $new_theme_datatype->setCreatedBy($user);
                    $new_theme_datatype->setCreated(new \DateTime());
                    $new_theme_datatype->setUpdated(new \DateTime());
                    $em->persist($new_theme_datatype);
                    $em->remove($theme_datatype);
                    $em->flush();

                    // Theme changes are cached with the Datatype (ugh)
                    $datatype_info_service = $this->container->get('odr.datatype_info_service');
                    $datatype_data = $datatype_info_service->getDatatypeData('', $datatype->getId(), true);

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
            if ( $datatype->getDeletedAt() != null )
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




}
