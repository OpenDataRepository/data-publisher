<?php

/**
 * Open Data Repository Data Publisher
 * Theme Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Contains controller actions specifically for modifying Themes and layouts.
 */

namespace ODR\AdminBundle\Controller;


use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeMeta;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeElementMeta;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Forms
use ODR\AdminBundle\Form\UpdateThemeForm;
use ODR\AdminBundle\Form\UpdateThemeElementForm;
use ODR\AdminBundle\Form\UpdateThemeDatafieldForm;
use ODR\AdminBundle\Form\UpdateThemeDatatypeForm;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\CloneThemeService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
// Symfony
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class ThemeController extends ODRCustomController
{

    /**
     * Saves changes made to a theme's name/description.  Loading the form is automatically done
     * inside self::DisplayTheme()
     *
     * @param int $theme_id
     * @param Request $request
     *
     * @return Response
     */
    public function savethemepropertiesAction($theme_id, Request $request)
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


            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Require users to be logged in and able to view the datatype before doing this...
            if ($user === "anon.")
                throw new ODRForbiddenException();
            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();

            if ($theme->getThemeType() == 'master' && !$pm_service->isDatatypeAdmin($user, $datatype)) {
                // If this is a 'master' theme, then require the user to be a datatype admin
                throw new ODRForbiddenException();
            }
            else if ($theme->getThemeType() !== 'master'
                && $theme->getCreatedBy()->getId() !== $user->getId()
            ) {
                // If this isn't a 'master' theme, then require the user to have created the theme
                throw new ODRForbiddenException();
            }
            // --------------------


            // Populate new Theme form
            $submitted_data = new ThemeMeta();
            $theme_form = $this->createForm(UpdateThemeForm::class, $submitted_data);
            $theme_form->handleRequest($request);


            // --------------------
            if ($theme_form->isSubmitted()) {

//$theme_form->addError( new FormError('DO NOT SAVE') );

                if ($theme_form->isValid()) {

                    // If a value in the form changed, create a new ThemeMeta entity to store the change
                    $properties = array(
                        'templateName' => $submitted_data->getTemplateName(),
                        'templateDescription' => $submitted_data->getTemplateDescription(),
                    );
                    parent::ODR_copyThemeMeta($em, $user, $theme, $properties);

                    // Update the cached version of this theme
                    $theme_service->updateThemeCacheEntry($theme, $user);
                }
                else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($theme_form);
                    throw new \Exception($error_str);
                }
            }
        }
        catch (\Exception $e) {
            $source = 0x2ab832c3;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
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
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Require users to be logged in and able to view the datatype before doing this...
            if ($user === "anon.")
                throw new ODRForbiddenException();
            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();

            // If user didn't create the theme, don't allow them to change shared status
            if ($theme->getCreatedBy()->getId() !== $user->getId())
                throw new ODRForbiddenException();
            // --------------------


            // If the theme is a 'master' theme, then don't allow it to be set to "not shared"
            if ($theme->getThemeType() == 'master' && $theme->isShared())
                throw new ODRBadRequestException("A datatype's 'master' theme must always be shared");

            // If the theme is currently a "default" theme for the datatype, then it also needs
            //  to always remain "shared"
            if ($theme->isDefault() && $theme->isShared())
                throw new ODRBadRequestException("A 'default' theme must always be shared");


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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
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
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ($user === "anon.")
                throw new ODRForbiddenException();

            // Don't allow the user to do this unless they're an admin of this datatype
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Compile a list of theme types that need to be set to "not default"
            $theme_types = ThemeInfoService::LONG_FORM_THEMETYPES;
            if ( in_array($theme->getThemeType(), ThemeInfoService::SHORT_FORM_THEMETYPES) )
                $theme_types = ThemeInfoService::SHORT_FORM_THEMETYPES;

            /** @var Theme[] $theme_list */
            $theme_list = $em->getRepository('ODRAdminBundle:Theme')->findBy(
                array('dataType' => $datatype->getId(), 'themeType' => $theme_types)
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes a Theme.  Will not delete 'master' or 'default' themes,
     *
     * @param int $theme_id
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
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Require users to be logged in and able to view the datatype before doing this...
            if ($user === "anon.")
                throw new ODRForbiddenException();
            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();

            // If user is not a super admin and didn't create the theme, don't allow them to delete
            if ( !$user->hasRole('ROLE_SUPER_ADMIN')
                && $theme->getCreatedBy()->getId() !== $user->getId()
            ) {
                throw new ODRForbiddenException();
            }
            // --------------------


            // If the theme is a 'master' theme, then nobody is allowed to delete it
            if ($theme->getThemeType() == 'master')
                throw new ODRBadRequestException('Unable to delete a "master" Theme');

            // If the theme is currently marked as a default for the datatype, don't delete it
            if ($theme->isDefault())
                throw new ODRBadRequestException('Unable to delete a Theme marked as "default"');

            // Don't delete themes for child datatypes
            if ($theme->getParentTheme()->getId() !== $theme->getId())
                throw new ODRBadRequestException('Unable to delete a Theme for a child datatype');


            // ----------------------------------------
            // Delete all ThemePreferences that reference this Theme
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:ThemePreferences AS tp
                SET tp.deletedAt = :now
                WHERE tp.theme = :theme_id
                AND tp.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'theme_id' => $theme->getId(),
                )
            );
            $rows = $query->execute();

            // Delete this Theme and all its "children"
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:Theme AS t
                SET t.deletedAt = :now
                WHERE t.parentTheme = :theme_id
                AND t.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'theme_id' => $theme->getId(),
                )
            );
            $rows = $query->execute();


            // ----------------------------------------
            // Delete the relevant cached theme entries
            $cache_service->delete('cached_theme_'.$theme_id);
            $cache_service->delete('top_level_themes');
        }
        catch (\Exception $e) {
            $source = 0x06c0bb40;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Opens the page to modify a single non-master theme.
     *
     * @param int $datatype_id
     * @param int $theme_id
     * @param Request $request
     *
     * @return Response
     */
    public function modifythemeAction($datatype_id, $theme_id, Request $request)
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


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            if ($theme->getDataType()->getId() !== $datatype->getId())
                throw new ODRBadRequestException();

            // Don't allow on a 'master' theme
            if ($theme->getThemeType() == 'master')
                throw new ODRBadRequestException('ThemeController::modifythemeAction() called on a master theme');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Require users to be logged in and able to view the datatype before doing this...
            if ($user === "anon.")
                throw new ODRForbiddenException();
            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();

            if ($theme->getCreatedBy()->getId() !== $user->getId())
                throw new ODRForbiddenException();
            // --------------------


            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'html' => self::DisplayTheme($datatype, $theme, 'edit')
            );

        }
        catch (\Exception $e) {
            $source = 0x1b840b54;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
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
     * @param int $theme_id
     * @param Request $request
     *
     * @return Response
     */
    public function clonethemeAction($theme_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var CloneThemeService $clone_theme_service */
            $clone_theme_service = $this->container->get('odr.clone_theme_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Require users to be logged in and able to view the datatype before doing this...
            if ($user === 'anon.')
                throw new ODRForbiddenException();
            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();

            // If a theme is shared...
            if ($theme->isShared()) {
                // ...allow anybody to copy it
            }
            else if ( !$pm_service->isDatatypeAdmin($user, $datatype)
                && $theme->getCreatedBy()->getId() !== $user->getId()
            ) {
                // User has to be a datatype admin or the creator of the theme to be allowed to
                //  copy a non-shared theme
                throw new ODRForbiddenException();
            }
            // --------------------


            // Don't run this on themes for child datatypes
            if ($theme->getId() !== $theme->getParentTheme()->getId())
                throw new ODRBadRequestException('Not allowed to clone a Theme for a child Datatype');


            // For now, just directly clone the theme...'master' themes get cloned to 'custom_view'
            // Everything else retains the theme_type it was previously
            $dest_theme_type = $theme->getThemeType();
            if ($dest_theme_type == 'master')
                $dest_theme_type = 'custom_view';

            $new_theme = $clone_theme_service->cloneThemeFromParent($user, $theme, $dest_theme_type);

            // This actually seems to be fast enough that it doesn't need the TrackedJobService...

            $return['d'] = array(
                'new_theme_id' => $new_theme->getId()
            );

            // Delete the cached list of top-level themes
            $cache_service->delete('top_level_themes');
        }
        catch (\Exception $e) {
            $source = 0x4391891a;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Syncs the provided theme with its source.
     *
     * @param int $theme_id
     * @param Request $request
     *
     * @return Response
     */
    public function syncthemeAction($theme_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CloneThemeService $clone_theme_service */
            $clone_theme_service = $this->container->get('odr.clone_theme_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Require users to be logged in and able to view the datatype before doing this...
            if ($user === 'anon.')
                throw new ODRForbiddenException();
            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();

            // If the user didn't create the theme, don't allow them to sync it
            if ($theme->getCreatedBy()->getId() !== $user->getId())
                throw new ODRForbiddenException();
            // --------------------


            // Don't run this on themes that are their own source...this also blocks 'master' themes
            if ($theme->getSourceTheme()->getId() == $theme->getId())
                throw new ODRBadRequestException('Not allowed to sync a Theme with itself');

            // Don't run this on themes for child datatypes
            if ($theme->getId() !== $theme->getParentTheme()->getId())
                throw new ODRBadRequestException('Not allowed to clone a Theme for a child Datatype');


            // TODO - track this in the future...it's fast enough to not need it right now though
            $changes_made = $clone_theme_service->syncThemeWithSource($user, $theme);

            $return['d'] = array('changes_made' => $changes_made);
        }
        catch (\Exception $e) {
            $source = 0xd48bcb98;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Loads and returns the default 'search_results' Theme for the datatype, creating it if it
     * doesn't already exist.
     *
     * @param integer $datatype_id   The database id of the DataType to be rendered.
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

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException("Datatype");


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // TODO - pretty sure custom themes can't use this...

            // Ensure user has permissions to be doing this
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
            }
            else {
                // Ensure a default 'search_results' theme exists for this datatype
                $theme = $theme_service->getDatatypeDefaultTheme($datatype->getId(), 'search_results');
                if ($theme == null) {
                    // Load this datatype's 'master' Theme
                    $master_theme = $theme_service->getDatatypeDefaultTheme($datatype->getId());

                    // Make a copy of the 'master' Theme
                    /** @var CloneThemeService $clone_theme_service */
                    $clone_theme_service = $this->container->get('odr.clone_theme_service');
                    $theme = $clone_theme_service->cloneThemeFromParent($user, $master_theme, 'search_results');

                    // Since this is the first 'search_results' theme, it should get marked as
                    //  both 'shared' and 'default'
                    $properties = array(
                        'shared' => true,
                        'isDefault' => true,
                    );
                    parent::ODR_copyThemeMeta($em, $user, $theme, $properties);

                    // This datatype now has a "search_results" theme, so it's no longer "incomplete"
                    $datatype->setSetupStep(DataType::STATE_OPERATIONAL);
                    $em->persist($datatype);
                    $em->flush();

                    // Delete the cached version of this datatype
                    $cache_service->delete('cached_datatype_'.$datatype->getId());
                    // Delete the cached list of top-level themes
                    $cache_service->delete('top_level_themes');
                }

                // Redirect to the correct URL to edit the default 'search_results' theme
                $url = $this->generateUrl(
                    'odr_modify_theme',
                    array(
                        'datatype_id' => $datatype->getId(),
                        'theme_id' => $theme->getId()
                    )
                );

                $response = new RedirectResponse($url);
                return $response;
            }
        }
        catch (\Exception $e) {
            $source = 0x239a544e;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Renders and returns the HTML to modify a non-master Theme for a Datatype.
     *
     * @param DataType $datatype The datatype that originally requested this Theme rendering
     * @param Theme $theme The theme to render
     * @param string $display_mode Determines what messaging to display in editor.
     *
     * @throws \Exception
     *
     * @return string
     */
    private function DisplayTheme($datatype, $theme, $display_mode = "wizard")
    {
        /** @var DatatypeInfoService $dti_service */
        $dti_service = $this->container->get('odr.datatype_info_service');
        /** @var PermissionsManagementService $pm_service */
        $pm_service = $this->container->get('odr.permissions_management_service');
        /** @var ThemeInfoService $theme_service */
        $theme_service = $this->container->get('odr.theme_info_service');


        // ----------------------------------------
        // Determine whether the user is an admin of this datatype
        /** @var ODRUser $user */
        $user = $this->container->get('security.token_storage')->getToken()->getUser();

        $datatype_permissions = $pm_service->getDatatypePermissions($user);
        $is_datatype_admin = $pm_service->isDatatypeAdmin($user, $datatype);


        // ----------------------------------------
        // Grab the cached versions of all of the associated datatypes, and store them all at the same level in a single array
        $include_links = false;
        $datatype_array = $dti_service->getDatatypeArray($datatype->getId(), $include_links);

        $theme_array = $theme_service->getThemeArray(array($theme->getId()));     // TODO - need to get links?

        // ----------------------------------------


        // Build the Form to save changes to the Theme's name/description
        $theme_meta = $theme->getThemeMeta();
        $theme_form = $this->createForm(
            UpdateThemeForm::class,
            $theme_meta
        );

        // ----------------------------------------
        // Render the required version of the page
        $templating = $this->get('templating');
        $html = $templating->render(
            'ODRAdminBundle:Theme:theme_ajax.html.twig',
            array(
                'datatype_array' => $datatype_array,
                'initial_datatype_id' => $datatype->getId(),
                'theme_array' => $theme_array,

                'theme_datatype' => $datatype,
                'theme' => $theme,
                'theme_form' => $theme_form->createView(),

                'datatype_permissions' => $datatype_permissions,
                'is_datatype_admin' => $is_datatype_admin,

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
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Require users to be logged in and able to view the datatype before doing this...
            if ( $user === 'anon.' )
                throw new ODRForbiddenException();
            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();


            if ($theme->getThemeType() == 'master' && !$pm_service->isDatatypeAdmin($user, $datatype)) {
                // If this is a 'master' theme, then require the user to be a datatype admin
                throw new ODRForbiddenException();
            }
            else if ($theme->getThemeType() !== 'master'
                && $theme->getCreatedBy()->getId() !== $user->getId()
            ) {
                // If this isn't a 'master' theme, then require the user to have created the theme
                throw new ODRForbiddenException();
            }
            // --------------------


            // Locate the ThemeDatafield entity
            /** @var ThemeDataField $theme_datafield */
            $theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField')->findOneBy(
                array('dataField' => $datafield->getId(), 'themeElement' => $theme_element->getId())
            );
            if ($theme_datafield == null)
                throw new ODRNotFoundException('ThemeDatafield');


            // Form contents change slightly depending on whether this is a master theme or not
            $is_master_theme = false;
            if ($theme->getThemeType() == 'master')
                $is_master_theme = true;

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
                    ),
                    'is_master_theme' => $is_master_theme,
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Saves an ODR ThemeDatafield properties form.  Kept separate from
     * self::loadthemedatafieldAction() because the 'master' theme designed by
     * DisplaytemplateController.php needs to combine Datafield and ThemeDatafield forms onto a
     * single slideout, but every other theme is only allowed to modify ThemeDatafield entries.
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

//            if ($theme->getThemeType() == 'table')
//                throw new ODRBadRequestException('Unable to change properties of a Table theme');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Require users to be logged in and able to view the datatype before doing this...
            if ( $user === 'anon.' )
                throw new ODRForbiddenException();
            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();


            if ($theme->getThemeType() == 'master' && !$pm_service->isDatatypeAdmin($user, $datatype)) {
                // If this is a 'master' theme, then require the user to be a datatype admin
                throw new ODRForbiddenException();
            }
            else if ($theme->getThemeType() !== 'master'
                && $theme->getCreatedBy()->getId() !== $user->getId()
            ) {
                // If this isn't a 'master' theme, then require the user to have created the theme
                throw new ODRForbiddenException();
            }
            // --------------------

            // Form contents change slightly depending on whether this is a master theme or not
            $is_master_theme = false;
            if ($theme->getThemeType() == 'master')
                $is_master_theme = true;

            // Populate new ThemeDataField form
            $submitted_data = new ThemeDataField();
            $theme_datafield_form = $this->createForm(
                UpdateThemeDatafieldForm::class,
                $submitted_data,
                array(
                    'is_master_theme' => $is_master_theme,
                )
            );

            $theme_datafield_form->handleRequest($request);
            if ($theme_datafield_form->isSubmitted()) {

//$theme_datafield_form->addError( new FormError('DO NOT SAVE') );

                if ($theme_datafield_form->isValid()) {
                    // Don't allow a themeDatafield belonging to a master theme to be hidden
                    if ($theme->getThemeType() == 'master')
                        $submitted_data->setHidden(0);

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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
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
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Require users to be logged in and able to view the datatype before doing this...
            if ( $user === 'anon.' )
                throw new ODRForbiddenException();
            if ( !$pm_service->canViewDatatype($user, $parent_datatype) )
                throw new ODRForbiddenException();
            if ( !$pm_service->canViewDatatype($user, $child_datatype) )
                throw new ODRForbiddenException();


            if ($theme->getThemeType() == 'master' && !$pm_service->isDatatypeAdmin($user, $parent_datatype)) {
                // If this is a 'master' theme, then require the user to be a datatype admin
                throw new ODRForbiddenException();
            }
            else if ($theme->getThemeType() !== 'master'
                && $theme->getCreatedBy()->getId() !== $user->getId()
            ) {
                // If this isn't a 'master' theme, then require the user to have created the theme
                throw new ODRForbiddenException();
            }
            // --------------------


            // Form contents change slightly depending on whether this is a master theme or not
            $is_master_theme = false;
            if ($theme->getThemeType() == 'master')
                $is_master_theme = true;


            // Check if multiple child/linked datarecords are allowed for datatype
            /** @var DataTree $datatree */
            $datatree = $em->getRepository('ODRAdminBundle:DataTree')->findOneBy(
                array('ancestor' => $parent_datatype->getId(), 'descendant' => $child_datatype->getId())
            );

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
                    'is_master_theme' => $is_master_theme,
                    'multiple_allowed' => $datatree->getMultipleAllowed(),
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Saves an ODR ThemeDatatype properties form.  Kept separate from self::loadthemedatatypeAction()
     * because the 'master' theme designed by DisplaytemplateController.php needs to combine
     * Datatype, Datatree, and ThemeDatatype forms onto a single slideout, but every other theme
     * is only allowed to modify ThemeDatatype entries.
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
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Require users to be logged in and able to view the datatype before doing this...
            if ( $user === 'anon.' )
                throw new ODRForbiddenException();
            if ( !$pm_service->canViewDatatype($user, $parent_datatype) )
                throw new ODRForbiddenException();
            if ( !$pm_service->canViewDatatype($user, $child_datatype) )
                throw new ODRForbiddenException();


            if ($theme->getThemeType() == 'master' && !$pm_service->isDatatypeAdmin($user, $parent_datatype)) {
                // If this is a 'master' theme, then require the user to be a datatype admin
                throw new ODRForbiddenException();
            }
            else if ($theme->getThemeType() !== 'master'
                && $theme->getCreatedBy()->getId() !== $user->getId()
            ) {
                // If this isn't a 'master' theme, then require the user to have created the theme
                throw new ODRForbiddenException();
            }
            // --------------------


            // Populate new ThemeDataType form
            /** @var ThemeDataType $submitted_data */
            $submitted_data = new ThemeDataType();

            // Check if multiple child/linked datarecords are allowed for datatype
            /** @var DataTree $datatree */
            $datatree = $em->getRepository('ODRAdminBundle:DataTree')->findOneBy(
                array('ancestor' => $parent_datatype->getId(), 'descendant' => $child_datatype->getId())
            );

            // Form contents change slightly depending on whether this is a master theme or not
            $is_master_theme = false;
            if ($theme->getThemeType() == 'master')
                $is_master_theme = true;


            // Create the ThemeDatatype form object
            $theme_datatype_form = $this->createForm(
                UpdateThemeDatatypeForm::class,
                $submitted_data,
                array(
                    'is_master_theme' => $is_master_theme,
                    'multiple_allowed' => $datatree->getMultipleAllowed(),
                )
            );

            $theme_datatype_form->handleRequest($request);
            if ($theme_datatype_form->isSubmitted()) {

                if ($theme_datatype_form->isValid()) {
                    // Don't allow a themeDatatype belonging to a master theme to be hidden
                    if ($theme->getThemeType() == 'master')
                        $submitted_data->setHidden(0);

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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
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
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Require users to be logged in and able to view the datatype before doing this...
            if ( $user === 'anon.' )
                throw new ODRForbiddenException();
            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();


            if ($theme->getThemeType() == 'master' && !$pm_service->isDatatypeAdmin($user, $datatype)) {
                // If this is a 'master' theme, then require the user to be a datatype admin
                throw new ODRForbiddenException();
            }
            else if ($theme->getThemeType() !== 'master'
                && $theme->getCreatedBy()->getId() !== $user->getId()
            ) {
                // If this isn't a 'master' theme, then require the user to have created the theme
                throw new ODRForbiddenException();
            }
            // --------------------

            // Not allowed to create a new theme element for a table theme
//            if ($theme->getThemeType() == 'table')
//                throw new \Exception('Not allowed to have multiple theme elements in a table theme');


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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
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
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Require users to be logged in and able to view the datatype before doing this...
            if ( $user === 'anon.' )
                throw new ODRForbiddenException();
            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();


            if ($theme->getThemeType() == 'master' && !$pm_service->isDatatypeAdmin($user, $datatype)) {
                // If this is a 'master' theme, then require the user to be a datatype admin
                throw new ODRForbiddenException();
            }
            else if ($theme->getThemeType() !== 'master'
                && $theme->getCreatedBy()->getId() !== $user->getId()
            ) {
                // If this isn't a 'master' theme, then require the user to have created the theme
                throw new ODRForbiddenException();
            }
            // --------------------

            // Not allowed to modify properties of a theme element in a table theme
//            if ($theme->getThemeType() == 'table')
//                throw new \Exception('Not allowed to change properties of a theme element belonging to a table theme');

            // Form contents change slightly depending on whether the theme is master or not
            $is_master_theme = false;
            if ($theme->getThemeType() == 'master')
                $is_master_theme = true;

            // Populate new ThemeElement form
            $submitted_data = new ThemeElementMeta();
            $theme_element_form = $this->createForm(
                UpdateThemeElementForm::class,
                $submitted_data,
                array(
                    'is_master_theme' => $is_master_theme,
                )
            );

            $theme_element_form->handleRequest($request);
            if ($theme_element_form->isSubmitted()) {

                //$theme_element_form->addError( new FormError('do not save') );

                if ($theme_element_form->isValid()) {
                    // Not allowed to mark ThemeElements from 'master' themes as 'hidden'
                    if ($theme->getThemeType() == 'master')
                        $submitted_data->setHidden(0);

                    // If a value in the form changed, create a new ThemeElementMeta entity to store the change
                    $properties = array(
                        'hidden' => $submitted_data->getHidden(),
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
                        ),
                        'is_master_theme' => $is_master_theme,
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
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
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Require users to be logged in and able to view the datatype before doing this...
            if ( $user === 'anon.' )
                throw new ODRForbiddenException();
            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();


            if ($theme->getThemeType() == 'master' && !$pm_service->isDatatypeAdmin($user, $datatype)) {
                // If this is a 'master' theme, then require the user to be a datatype admin
                throw new ODRForbiddenException();
            }
            else if ($theme->getThemeType() !== 'master'
                && $theme->getCreatedBy()->getId() !== $user->getId()
            ) {
                // If this isn't a 'master' theme, then require the user to have created the theme
                throw new ODRForbiddenException();
            }
            // --------------------


            // Not allowed to delete a theme element from a table theme
//            if ($theme->getThemeType() == 'table')
//                throw new \Exception('Not allowed to delete a theme element from a table theme');

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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
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
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Require users to be logged in and able to view the datatype before doing this...
            if ( $user === 'anon.' )
                throw new ODRForbiddenException();
            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();


            if ($theme->getThemeType() == 'master' && !$pm_service->isDatatypeAdmin($user, $datatype)) {
                // If this is a 'master' theme, then require the user to be a datatype admin
                throw new ODRForbiddenException();
            }
            else if ($theme->getThemeType() !== 'master'
                && $theme->getCreatedBy()->getId() !== $user->getId()
            ) {
                // If this isn't a 'master' theme, then require the user to have created the theme
                throw new ODRForbiddenException();
            }
            // --------------------

            // Shouldn't happen since there's only one theme element per table theme
//            if ($theme->getThemeType() == 'table')
//                throw new \Exception('Not allowed to re-order theme elements inside a table theme');


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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
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
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Require users to be logged in and able to view the datatype before doing this...
            if ( $user === 'anon.' )
                throw new ODRForbiddenException();
            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();


            if ($theme->getThemeType() == 'master' && !$pm_service->isDatatypeAdmin($user, $datatype)) {
                // If this is a 'master' theme, then require the user to be a datatype admin
                throw new ODRForbiddenException();
            }
            else if ($theme->getThemeType() !== 'master'
                && $theme->getCreatedBy()->getId() !== $user->getId()
            ) {
                // If this isn't a 'master' theme, then require the user to have created the theme
                throw new ODRForbiddenException();
            }
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


            // There aren't appreciable differences between 'master', 'search_results', and 'table'
            //  themes...at least as far as changing datafield order is concerned

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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
