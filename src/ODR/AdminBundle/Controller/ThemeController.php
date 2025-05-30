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
use ODR\AdminBundle\Entity\ThemePreferences;
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
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\ODRRenderService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
// Symfony
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Templating\EngineInterface;


class ThemeController extends ODRCustomController
{

    /**
     * Returns a list of themes the current user can use in the current context for the given
     * datatype.
     *
     * @param integer $datatype_id
     * @param string $page_type {@link ThemeInfoService::PAGE_TYPES}
     * @param string $search_key
     * @param Request $request
     *
     * @return Response $response
     */
    public function getavailablethemesAction($datatype_id, $page_type, $search_key, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'json';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( is_null($datatype) )
                throw new ODRNotFoundException('Datatype');

            // --------------------
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $is_datatype_admin = $permissions_service->isDatatypeAdmin($user, $datatype);

            $is_super_admin = false;
            if ( $user instanceof ODRUser )
                $is_super_admin = $user->isSuperAdmin();
            // --------------------

            // Not attempting to verify $page_type...
            $selected_theme_id = 0;
            if ( $page_type !== '' ) {
                // ...ThemeInfoService will verify if being called from a location where it matters
                $selected_theme_id = $theme_info_service->getPreferredThemeId($user, $datatype_id, $page_type);
            }

            // Get all available themes for this datatype that the user can view
            $available_themes = $theme_info_service->getAvailableThemes($user, $datatype);
            // Combine each theme's visibility flag with the current page type to determine whether
            //  they match
            foreach ($available_themes as $num => $theme_data) {
                $theme_visibility = $theme_data['theme_visibility'];

                // NOTE: currently the magic numbers come from UpdateThemeForm.php
                $in_context = false;
                if ( $theme_visibility == 0 )
                    $in_context = true;
                else if ( $theme_visibility == 1 && ($page_type == 'search_results' || $page_type == 'linking') )
                    $in_context = true;
                else if ( $theme_visibility == 2 && ($page_type == 'display' || $page_type == 'edit') )
                    $in_context = true;

                $available_themes[$num]['theme_visibility'] = $in_context;
            }

            $show_context_checkbox = false;
            foreach ($available_themes as $num => $theme_data) {
                if ( $theme_data['theme_visibility'] == false )
                    $show_context_checkbox = true;
            }


            // ----------------------------------------
            // Need to provide a formatted version of $page_type that matches what getAvailableThemes()
            //  returned as part of the "default_for" entry
            $formatted_page_type = ucfirst( str_replace('_', ' ', $page_type) );

            $available_page_types = array();
            foreach (ThemeInfoService::PAGE_TYPES as $num => $str)
                $available_page_types[$str] = ucfirst( str_replace('_', ' ', $str) );


            // Render and return the theme chooser dialog
            $return['d'] = $templating->render(
                'ODRAdminBundle:Default:choose_view.html.twig',
                array(
                    'user' => $user,
                    'is_datatype_admin' => $is_datatype_admin,
                    'is_super_admin' => $is_super_admin,

                    'datatype' => $datatype,
                    'search_key' => $search_key,

                    'available_themes' => $available_themes,
                    'selected_theme_id' => $selected_theme_id,

                    'page_type' => $page_type,
                    'show_context_checkbox' => $show_context_checkbox,
                    'formatted_page_type' => $formatted_page_type,
                    'available_page_types' => $available_page_types,
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x81fad8c3;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Most of the actions in this controller use the same logic to determine whether the user is
     * allowed to make changes to a theme.  This function throws an error if the user is not allowed.
     *
     * @param ODRUser $user
     * @param Theme $theme
     * @param DataFields|null $datafield
     * @throws ODRForbiddenException
     */
    private function canModifyTheme($user, $theme, $datafield = null)
    {
        /** @var PermissionsManagementService $permissions_service */
        $permissions_service = $this->container->get('odr.permissions_management_service');

        // ----------------------------------------
        // ODR supports linking of databases in order to reduce duplication of data.
        // e.g.  A database of "Samples" links to a "Mineral" database, so that the "Sample" database
        //  doesn't have to duplicate all the "Mineral" information it requires
        // Back in the early days...when ODR needed to render the "Sample" database, it would load
        //  both the master theme for the "Sample" database and the master theme for the "Mineral"
        //  database, and then render both of them in sequence.

        // This worked, but users of the "Sample" database didn't want or need all the fields from
        //  the "Mineral" database to always be displayed.  In order to allow the "Sample" database
        //  to pick and choose which "Mineral" fields to display, ODR was modified so it would make
        //  a "local copy" of the "Mineral" database's master Theme.

        // Themes received two new values...a "parent theme", which is used to quickly load all Themes
        //  for rendering purposes...and a "source theme", which is regularly checked to see if it
        //  has any new datafields or child/linked datatypes.  If so, any "local copies" get updated.

        // For a concrete example of the simplest possible "Sample"/"Mineral" setup...
        // Theme 1 ("Mineral" database's master theme)
        //  - datatype "Mineral", parent_theme_id 1, source_theme_id 1
        // Theme 2 ("Sample" database's master theme)
        //  - datatype "Sample", parent_theme_id 2, source_theme_id 2

        // The "Sample" database then creates a link to the "Mineral" database, which creates...
        // Theme 3 ("Sample" database's "local copy" of "Mineral" database's master theme)
        //  - datatype "Mineral" (it's displaying datafields/etc from "Mineral")
        //  - parent_theme_id 2  (so it's loaded with "Sample" database's master theme)
        //  - source_theme_id 1  (to keep track of any new datafields/etc added to "Mineral")


        // ----------------------------------------
        // This copying complicates permissions...most of the checks need to be peformed against
        //  the Theme's Datatype...
        $datatype = $theme->getDataType();

        // However, if this is a "local copy" of some "remote" datatype, then some of the checks
        //  need to be performed against the Datatype that owns the "local copy"
        $local_parent_datatype = $datatype;
        if ( $theme->getParentTheme()->getId() !== $theme->getId() )
            $local_parent_datatype = $theme->getParentTheme()->getDataType();

        // e.g. if this function gets passed Theme 3 from the earlier example...
        //  ...then $datatype == "Mineral", and $local_parent_datatype == "Sample"


        // ----------------------------------------
        // Users have to be logged in to make any of these changes...
        if ($user === "anon.")
            throw new ODRForbiddenException();

        // Super-admins should be able to modify any layout/theme_element/theme_datafield
        if ( $user->hasRole('ROLE_SUPER_ADMIN') )
            return;

        // ...and also have to be able to at least view the datatype being modified
        if ( !$permissions_service->canViewDatatype($user, $datatype) )
            throw new ODRForbiddenException();

        // If this theme is a "local copy" of a remote datatype, then also need to be able to view
        //  the local datatype to be able to make changes to this theme
        if ( !$permissions_service->canViewDatatype($user, $local_parent_datatype) )
            throw new ODRForbiddenException();

        // If the action is modifying a themeDatafield, then they need to be able to view the datafield too
        if ( !is_null($datafield) && !$permissions_service->canViewDatafield($user, $datafield) )
            throw new ODRForbiddenException();

        // Master themes can only be modified by admins of the local datatype
        if ( $theme->getThemeType() === 'master' && !$permissions_service->isDatatypeAdmin($user, $local_parent_datatype) )
            throw new ODRForbiddenException();
        // Datatype admins of remote datatypes shouldn't necessarily be able to modify "local copies"
        //  made by other datatypes linking to said remote datatypes

        // If the user didn't create this non-master theme...
        if ( $theme->getThemeType() !== 'master' && $theme->getCreatedBy()->getId() !== $user->getId() ) {
            // ...then they're only allowed to modify it if they're an admin of the local datatype
            if ( !$permissions_service->isDatatypeAdmin($user, $local_parent_datatype) )
                throw new ODRForbiddenException();
        }

        // Otherwise, user created the theme, so they're allowed to modify it
    }


    /**
     * Opens the page to modify a Theme.
     *
     * @param int $datatype_id
     * @param int $theme_id
     * @param string $search_key
     * @param Request $request
     *
     * @return Response
     */
    public function modifythemeAction($datatype_id, $theme_id, $search_key, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRRenderService $odr_render_service */
            $odr_render_service = $this->container->get('odr.render_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            if ( $theme->getDataType()->getId() !== $datatype->getId() )
                throw new ODRBadRequestException();

            // Don't allow direct modification of a child/linked theme
            if ( $theme->getParentTheme()->getId() !== $theme->getId() )
                throw new ODRBadRequestException('ThemeController::modifythemeAction() called on a child/linked theme');

            // Need to allow the derivative theme designer to work on a 'master' theme
//            if ($theme->getThemeType() === 'master')
//                throw new ODRBadRequestException('ThemeController::modifythemeAction() called on a master theme');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Throw an exception if the user isn't allowed to do this
            self::canModifyTheme($user, $theme);
            // --------------------

            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'html' => $odr_render_service->getThemeDesignHTML($user, $theme, $search_key),
            );

        }
        catch (\Exception $e) {
            $source = 0x1b840b54;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Saves changes made to a theme's name/description.  Loading the form is automatically done
     * inside ODRRenderService::getThemeDesignHTML()
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

            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Themes which aren't top-level shouldn't have names/descriptions
            if ( $theme->getId() !== $theme->getParentTheme()->getId() )
                throw new ODRBadRequestException('Not allowed to change properties of a child/linked theme');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Throw an exception if the user isn't allowed to do this
            self::canModifyTheme($user, $theme);
            // --------------------


            // Populate new Theme form
            $submitted_data = new ThemeMeta();
            $theme_form = $this->createForm(
                UpdateThemeForm::class,
                $submitted_data,
            );
            $theme_form->handleRequest($request);


            // --------------------
            if ($theme_form->isSubmitted()) {
//                $theme_form->addError( new FormError('DO NOT SAVE') );

                // Need to unescape these values if they're coming from a wordpress install...
                $is_wordpress_integrated = $this->getParameter('odr_wordpress_integrated');
                if ( $is_wordpress_integrated ) {
                    $submitted_data->setTemplateName( stripslashes($submitted_data->getTemplateName()) );
                    $submitted_data->setTemplateDescription( stripslashes($submitted_data->getTemplateDescription()) );
                }

                $submitted_data->setTemplateName( trim($submitted_data->getTemplateName()) );
                if ( $submitted_data->getTemplateName() === '' )
                    $theme_form->addError( new FormError("The Layout name can't be blank") );

                // 'Master' themes should always have this value set to 0
                if ( $theme->getThemeType() === 'master' )
                    $submitted_data->setThemeVisibility(0);

                if ($theme_form->isValid()) {
                    // Save any changes made in the form
                    $properties = array(
                        'templateName' => $submitted_data->getTemplateName(),
                        'templateDescription' => $submitted_data->getTemplateDescription(),
                        'disableSearchSidebar' => $submitted_data->getDisableSearchSidebar(),
                        'themeVisibility' => $submitted_data->getThemeVisibility(),
                        'isTableTheme' => $submitted_data->getIsTableTheme(),
                        'displaysAllResults' => $submitted_data->getDisplaysAllResults(),
                        'enableHorizontalScrolling' => $submitted_data->getEnableHorizontalScrolling(),
                    );
                    $entity_modify_service->updateThemeMeta($user, $theme, $properties);

                    // Update the cached version of this theme
                    $theme_info_service->updateThemeCacheEntry($theme, $user);
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * By default, Themes are only visible and usable by their creator...this toggles whether any
     * user is allowed to view/use this Theme.  "Shared" and "Public" are synonyms here.
     *
     * @param integer $theme_id
     * @param Request $request
     *
     * @return Response
     */
    public function themesharedAction($theme_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = array();

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Child/linked themes inherit this property from their top-level parent
            if ( $theme->getId() !== $theme->getParentTheme()->getId() )
                throw new ODRBadRequestException('Not allowed to change properties of a child/linked theme');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Throw an exception if the user isn't allowed to do this
            self::canModifyTheme($user, $theme);

            // If the user is not a super-admin...
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') ) {
                // ...then they can only change public status on themes they created
                if ( $theme->getCreatedBy()->getId() !== $user->getId() )
                    throw new ODRForbiddenException();
            }

            // Datatype admins aren't allowed to change public status on themes they didn't create
            // TODO - remove this?  they're currently allowed to do everything else to this datatype's themes, except delete it...
            // --------------------


            // If the theme is a 'master' theme, then don't allow it to be set to "not shared"
            if ($theme->getThemeType() === 'master' && $theme->isShared())
                throw new ODRBadRequestException("A datatype's 'master' theme must always be shared");

            // If the theme is currently a "default" theme for the datatype, then it also needs
            //  to always remain "shared"
            if ($theme->getDefaultFor() > 0 && $theme->isShared())
                throw new ODRBadRequestException("A 'default' theme must always be shared");


            // Toggle the shared status of the specified theme
            if ( $theme->isShared() ) {
                // Theme is currently shared...
                $properties = array(
                    'shared' => false,
                );
                $entity_modify_service->updateThemeMeta($user, $theme, $properties);

                $return['d']['public'] = false;
            }
            else {
                // Theme is not currently shared...
                $properties = array(
                    'shared' => true,
                );
                $entity_modify_service->updateThemeMeta($user, $theme, $properties);

                $return['d']['public'] = true;
            }

            // Update cached version of theme
            $theme_info_service->updateThemeCacheEntry($theme, $user);
        }
        catch (\Exception $e) {
            $source = 0xd67b2938;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Datatypes default to the "master" Theme at first, but datatype admins have the ability to set
     * any shared theme as "default" for a given "page_type".
     *
     * @param string $page_type {@link ThemeInfoService::PAGE_TYPES}
     * @param integer $theme_id
     * @param Request $request
     *
     * @return Response
     */
    public function setdatabasedefaultthemeAction($page_type, $theme_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Child/linked themes inherit this property from their top-level parent
            if ( $theme->getId() !== $theme->getParentTheme()->getId() )
                throw new ODRBadRequestException('Not allowed to change properties of a child/linked theme');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // The user must be an admin of the relevant datatype to change this
            if ($user === "anon.")
                throw new ODRForbiddenException();
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Ensure the provided page_type is valid
            $page_type_id = array_search($page_type, ThemeInfoService::PAGE_TYPES);
            if ( $page_type_id === false )
                throw new ODRBadRequestException('"'.$page_type.'" is not a supported page type');

            // Query the database for the default top-level theme for this datatype/page_type combo
            // NOTE: using native SQL, because Doctrine apparently hates the '&' operator
            $query =
               'SELECT t.id
                FROM odr_theme AS t
                JOIN odr_theme_meta AS tm ON tm.theme_id = t.id
                WHERE t.data_type_id = :datatype_id AND (tm.default_for & :page_type_id)
                AND t.id = t.parent_theme_id
                AND t.deletedAt IS NULL AND tm.deletedAt IS NULL';
            $params = array(
                'datatype_id' => $datatype->getId(),
                'page_type_id' => $page_type_id,
            );
            $conn = $em->getConnection();
            $results = $conn->executeQuery($query, $params);

            // Each of the themes in this query need to be set to "not default"...
            foreach ($results as $result) {
                $t_id = $result['id'];
                if ( $theme->getId() !== $t_id ) {
                    /** @var Theme $t */
                    $t = $em->getRepository('ODRAdminBundle:Theme')->find($t_id);
                    $new_defaults = $t->getDefaultFor() - $page_type_id;
                    $properties = array(
                        'defaultFor' => $new_defaults
                    );

                    $entity_modify_service->updateThemeMeta($user, $t, $properties, true);    // Don't flush immediately...
                    // Update cached versions of these themes
                    $theme_info_service->updateThemeCacheEntry($t, $user);
                }
            }

            // ...afterwards, specify this theme as the default (and shared, if it isn't already)
            $new_defaults = $theme->getDefaultFor() + $page_type_id;
            $properties = array(
                'shared' => true,
                'defaultFor' => $new_defaults
            );
            $entity_modify_service->updateThemeMeta($user, $theme, $properties, true);    // Don't flush immediately...

            // Updated cached version of this theme
            $theme_info_service->updateThemeCacheEntry($theme, $user);

            $return['d'] = "success";
        }
        catch (\Exception $e) {
            $source = 0x763a86a6;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Because a theme can be a default for multiple page_types, it's handy to have a way to remove
     * a default without requiring another theme to take its place.
     *
     * @param string $page_type {@link ThemeInfoService::PAGE_TYPES}
     * @param integer $theme_id
     * @param Request $request
     *
     * @return Response
     */
    public function unsetdatabasedefaultthemeAction($page_type, $theme_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Child/linked themes inherit this property from their top-level parent
            if ( $theme->getId() !== $theme->getParentTheme()->getId() )
                throw new ODRBadRequestException('Not allowed to change properties of a child/linked theme');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // The user must be an admin of the relevant datatype to change this
            if ($user === "anon.")
                throw new ODRForbiddenException();
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Ensure the provided page_type is valid
            $page_type_id = array_search($page_type, ThemeInfoService::PAGE_TYPES);
            if ( $page_type_id === false )
                throw new ODRBadRequestException('"'.$page_type.'" is not a supported page type');


            // Only do stuff if the theme is currently the default for this page type...
            if ( ($theme->getDefaultFor() & $page_type_id) ) {
                $new_defaults = $theme->getDefaultFor() - $page_type_id;
                $properties = array(
                    'shared' => true,
                    'defaultFor' => $new_defaults
                );
                $entity_modify_service->updateThemeMeta($user, $theme, $properties, true);    // Don't flush immediately...

                // Updated cached version of this theme
                $theme_info_service->updateThemeCacheEntry($theme, $user);
            }
            else {
                // If it's not the default for this page type, just return without doing anything
            }

            $return['d'] = "success";
        }
        catch (\Exception $e) {
            $source = 0xbd4a27f9;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Because a theme can be a default for multiple page_types, it's handy to have a way to remove
     * a default without requiring another theme to take its place.
     *
     * NOTE: the corresponding set is in {@link SessionController::applythemeAction()}
     *
     * @param string $page_type {@link ThemeInfoService::PAGE_TYPES}
     * @param integer $theme_id
     * @param Request $request
     *
     * @return Response
     */
    public function unsetpersonaldefaultthemeAction($page_type, $theme_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');


            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Child/linked themes inherit this property from their top-level parent
            if ( $theme->getId() !== $theme->getParentTheme()->getId() )
                throw new ODRBadRequestException('Not allowed to change properties of a child/linked theme');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // The user has to be logged in to manage their layout preferences
            if ($user === "anon.")
                throw new ODRForbiddenException();
            // --------------------


            // Ensure the provided page_type is valid
            $page_type_id = array_search($page_type, ThemeInfoService::PAGE_TYPES);
            if ( $page_type_id === false )
                throw new ODRBadRequestException('"'.$page_type.'" is not a supported page type');


            // Only do stuff if the user has a themePreference involving this theme and page type...
            /** @var ThemePreferences $tp */
            $tp = $em->getRepository('ODRAdminBundle:ThemePreferences')->findOneBy(
                array(
                    'theme' => $theme->getId(),
                    'createdBy' => $user->getId(),
                )
            );

            if ($tp == null ) {
                // Nothing to do when the user doesn't have a themePreference entry for this theme
            }
            else {
                if ( ($tp->getDefaultFor() & $page_type_id) ) {
                    // Set the user's themePreference entry so it no longer refers to this page_type
                    $bitfield_value = $tp->getDefaultFor();
                    $bitfield_value -= $page_type_id;
                    $tp->setDefaultFor($bitfield_value);
                    $tp->setUpdatedBy($user);

                    $em->persist($tp);
                    $em->flush();
                    $em->refresh($tp);
                }
                else {
                    // If the user's themePreference entry doesn't have this theme listed as the
                    //  user's default, just return without doing anything
                }
            }

            $return['d'] = "success";
        }
        catch (\Exception $e) {
            $source = 0x70ec27b2;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes a Theme.  Will not delete 'master' or 'default' themes.
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

            // Require users to be logged in...
            if ($user === "anon.")
                throw new ODRForbiddenException();

            // Super-admins have permission to delete any theme (outside of the three exceptions below)

            // If the user is not a super-admin...
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') ) {
                // ...then they can only delete themes they created
                if ( $theme->getCreatedBy()->getId() !== $user->getId() )
                    throw new ODRForbiddenException();
            }

            // Datatype admins aren't allowed to delete themes they didn't create
            // --------------------


            // If the theme is a 'master' theme, then nobody is allowed to delete it
            if ( $theme->getThemeType() === 'master' )
                throw new ODRBadRequestException('Unable to delete a "master" Theme');

            // If the theme is currently marked as a default for the datatype, don't delete it
            if ( $theme->getDefaultFor() > 0 )
                throw new ODRBadRequestException('Unable to delete a Theme marked as "default"');

            // Don't delete themes for child/linked datatypes
            if ( $theme->getParentTheme()->getId() !== $theme->getId() )
                throw new ODRBadRequestException('Unable to delete a Theme for a child/linked datatype');


            // ----------------------------------------
            // Ensure users no longer have this Theme as their "personal default"
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

            // Delete this Theme and all of its "children"
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Clones the given theme, setting the requesting user as its owner.
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
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');


            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Don't run this on themes for child datatypes
            if ($theme->getId() !== $theme->getParentTheme()->getId())
                throw new ODRBadRequestException('Not allowed to clone a Theme for a child Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Require users to be logged in and able to view the datatype before doing this...
            if ($user === 'anon.')
                throw new ODRForbiddenException();
            if ( !$permissions_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();

            // The user must be able to see the theme in order for them to be able to clone it
            if ( !$theme->isShared() && $theme->getCreatedBy()->getId() !== $user->getId() )
                throw new ODRForbiddenException();
            // --------------------


            // This actually seems to be fast enough that it doesn't need the TrackedJobService...
            $new_theme = $clone_theme_service->cloneSourceTheme($user, $theme, 'custom');    // this controller action should never create a "master" theme

            $return['d'] = array(
                'new_theme_id' => $new_theme->getId()
            );


            // Delete the cached list of top-level themes
            $cache_service->delete('top_level_themes');
        }
        catch (\Exception $e) {
            $source = 0x4391891a;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
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

            /** @var EntityCreationService $entity_create_service */
            $entity_create_service = $this->container->get('odr.entity_creation_service');
            /** @var ODRRenderService $odr_render_service */
            $odr_render_service = $this->container->get('odr.render_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


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

            // Throw an exception if the user isn't allowed to do this
            self::canModifyTheme($user, $theme);
            // --------------------


            // Create a new theme element entity
            $theme_element = $entity_create_service->createThemeElement($user, $theme);

            // Update cached version of theme
            $theme_info_service->updateThemeCacheEntry($theme, $user);

            // Need to render a blank theme element so the page can get updated...
            $html = "";
            if ($theme->getThemeType() === 'master') {
                $html = $odr_render_service->reloadMasterDesignThemeElement(
                    $user,
                    $theme_element
                );
            }
            else {
                $html = $odr_render_service->reloadThemeDesignThemeElement(
                    $user,
                    $theme_element
                );
            }

            // Return the new theme element's id
            $return['d'] = array(
                'theme_element_id' => $theme_element->getId(),
                'datatype_id' => $datatype->getId(),
                'html' => $html,
            );

        }
        catch (\Exception $e) {
            $source = 0x7cbe82a5;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
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

            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


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

            // Throw an exception if the user isn't allowed to do this
            self::canModifyTheme($user, $theme);
            // --------------------


            // Don't allow deletion of themeElement if it still is being used for something
            if ( $theme_element->getThemeDataFields()->count() > 0 )
                throw new ODRBadRequestException('Unable to delete a theme element that contains datafields');
            if ( $theme_element->getThemeDataType()->count() > 0 )
                throw new ODRBadRequestException('Unable to delete a theme element that contains child/linked datatypes');
            if ( $theme_element->getThemeRenderPluginInstance()->count() > 0 )
                throw new ODRBadRequestException('Unable to delete a theme element that is being used by a render plugin');


            // Going to delete both the themeElement and its meta entry...
            $entities_to_remove = array();
            $entities_to_remove[] = $theme_element;
            $entities_to_remove[] = $theme_element->getThemeElementMeta();

            // Save who is deleting this themeElement
            $theme_element->setDeletedBy($user);
            $em->persist($theme_element);
            $em->flush();

            // Delete both the themeElement and its meta entry
            foreach ($entities_to_remove as $entity)
                $em->remove($entity);
            $em->flush();

            // Update cached version of theme
            $theme_info_service->updateThemeCacheEntry($theme, $user);
        }
        catch (\Exception $e) {
            $source = 0x6d3a448c;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
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

            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


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

            // Throw an exception if the user isn't allowed to do this
            self::canModifyTheme($user, $theme);
            // --------------------


            // Populate new ThemeElement form
            $submitted_data = new ThemeElementMeta();
            $theme_element_form = $this->createForm(
                UpdateThemeElementForm::class,
                $submitted_data
            );

            $theme_element_form->handleRequest($request);
            if ($theme_element_form->isSubmitted()) {
//                $theme_element_form->addError( new FormError('DO NOT SAVE') );

                if ($theme_element_form->isValid()) {
                    // Users need to be able to change the "hidden" property on a "master" theme
                    // Display/Edit/SearchResults/TextResults will respect this property, but most
                    //  other areas of ODR ignore it
//                    if ($theme->getThemeType() === 'master')
//                        $submitted_data->setHidden(0);

                    // Save any changes made to the form
                    $properties = array(
                        'hidden' => $submitted_data->getHidden(),
                        'hideBorder' => $submitted_data->getHideBorder(),
                        'cssWidthMed' => $submitted_data->getCssWidthMed(),
                        'cssWidthXL' => $submitted_data->getCssWidthXL(),
                    );
                    $entity_modify_service->updateThemeElementMeta($user, $theme_element, $properties);

                    // Update the cached version of this theme
                    $theme_info_service->updateThemeCacheEntry($theme, $user);
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
                    )
                );

                // Return the slideout html
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
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
            $post = $request->request->all();

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_theme_element = $em->getRepository('ODRAdminBundle:ThemeElement');

            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


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

            // Throw an exception if the user isn't allowed to do this
            self::canModifyTheme($user, $theme);
            // --------------------


            // Go through all of the theme elements, updating their display order if needed
            $changes_made = false;
            foreach ($post as $index => $theme_element_id) {
                /** @var ThemeElement $theme_element */
                $theme_element = $repo_theme_element->find($theme_element_id);
                $em->refresh($theme_element);

                if ( $theme_element->getDisplayOrder() !== $index ) {
                    // Need to update this theme_element's display order
                    $properties = array(
                        'displayOrder' => $index
                    );
                    $entity_modify_service->updateThemeElementMeta($user, $theme_element, $properties, true);    // don't flush immediately
                    $changes_made = true;
                }
            }

            if ($changes_made)
                $em->flush();


            // Update cached version of theme
            $theme_info_service->updateThemeCacheEntry($theme, $user);
        }
        catch (\Exception $e) {
            $source = 0x26e49fd1;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Toggles the hidden status of a ThemeElement.  This is its own action because it's toggled
     * with a UI element, instead of using Symfony's form system.
     *
     * @param integer $theme_element_id
     * @param Request $request
     *
     * @return Response
     */
    public function themeelementvisibilityAction($theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = array();

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ( is_null($theme_element) )
                throw new ODRNotFoundException('ThemeElement');

            $theme = $theme_element->getTheme();
            if ( !is_null($theme->getDeletedAt()) )
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ( !is_null($datatype->getDeletedAt()) )
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Throw an exception if the user isn't allowed to do this
            self::canModifyTheme($user, $theme);
            // --------------------

            // Users need to be able to change the "hidden" property on a "master" theme
            // Display/Edit/SearchResults/TextResults will respect this property, but most other
            //  areas of ODR ignore it
//            if ( $theme->getThemeType() === 'master' )
//                throw new ODRBadRequestException("Unable to change hidden status of ThemeElements on a datatype's master theme");


            // Toggle the hidden status of the specified theme_element
            if ( $theme_element->getHidden() ) {
                $properties = array(
                    'hidden' => false,
                );
                $entity_modify_service->updateThemeElementMeta($user, $theme_element, $properties);
            }
            else {
                $properties = array(
                    'hidden' => true,
                );
                $entity_modify_service->updateThemeElementMeta($user, $theme_element, $properties);
            }

            // Update cached version of theme
            $theme_info_service->updateThemeCacheEntry($theme, $user);
        }
        catch (\Exception $e) {
            $source = 0xd43cc3b9;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Toggles whether a ThemeElement displays its border or not.  This is its own action because
     * it's toggled with a UI element, instead of using Symfony's form system.
     *
     * The UI only allows this on themeElements for top-level datatypes to match the CSS definitions,
     * but there's technically nothing stopping it from working on themeElements of descendents.
     *
     * @param integer $theme_element_id
     * @param Request $request
     *
     * @return Response
     */
    public function themeelementbordervisibilityAction($theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = array();

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ( is_null($theme_element) )
                throw new ODRNotFoundException('ThemeElement');

            $theme = $theme_element->getTheme();
            if ( !is_null($theme->getDeletedAt()) )
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ( !is_null($datatype->getDeletedAt()) )
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Throw an exception if the user isn't allowed to do this
            self::canModifyTheme($user, $theme);
            // --------------------

            // Users need to be able to change the "hidden" property on a "master" theme
            // Display/Edit/SearchResults/TextResults will respect this property, but most other
            //  areas of ODR ignore it
//            if ( $theme->getThemeType() === 'master' )
//                throw new ODRBadRequestException("Unable to change hidden status of ThemeElements on a datatype's master theme");


            // Toggle the hidden status of the specified theme_element
            if ( $theme_element->getHideBorder() ) {
                $properties = array(
                    'hideBorder' => false,
                );
                $entity_modify_service->updateThemeElementMeta($user, $theme_element, $properties);
            }
            else {
                $properties = array(
                    'hideBorder' => true,
                );
                $entity_modify_service->updateThemeElementMeta($user, $theme_element, $properties);
            }

            // Update cached version of theme
            $theme_info_service->updateThemeCacheEntry($theme, $user);
        }
        catch (\Exception $e) {
            $source = 0x88462342;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Triggers a re-render and reload of a ThemeElement in the Derivative theme designer
     *
     * @param integer $theme_element_id The database id of the ThemeElement that needs to be re-rendered
     * @param Request $request
     *
     * @return Response
     */
    public function reloadthemeelementAction($theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRRenderService $odr_render_service */
            $odr_render_service = $this->container->get('odr.render_service');


            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                throw new ODRNotFoundException('ThemeElement');

            $theme = $theme_element->getTheme();
            if ( !is_null($theme->getDeletedAt()) )
                throw new ODRNotFoundException('Theme');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Throw an exception if the user isn't allowed to do this
            self::canModifyTheme($user, $theme);
            // --------------------


            // ----------------------------------------
            // Render the required version of the page
            $html = $odr_render_service->reloadThemeDesignThemeElement($user, $theme_element);
            $return['d'] = array(
                'theme_element_id' => $theme_element_id,
                'theme_element_hidden' => $theme_element->getHidden(),
                'html' => $html,
            );
        }
        catch (\Exception $e) {
            $source = 0xdb066a2d;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Loads a ThemeDatatype properties form.
     *
     * This is kept separate from DisplaytemplateController::datatypepropertiesAction() because that
     * controller action needs to combine the Datatype/Datatree/ThemeDatatype forms into a single
     * unit for the purposes of designing a "master" theme...however, for any other theme, the
     * ThemeDatatype form is the only one that should get changed.
     *
     * @param integer $theme_element_id The id of the theme element holding the child/linked datatype
     * @param integer $datatype_id The id of the child/linked datatype itself
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

            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ( is_null($theme_element) )
                throw new ODRNotFoundException('ThemeElement');

            /** @var DataType $child_datatype */
            $child_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( is_null($child_datatype) )
                throw new ODRNotFoundException('Datatype');

            /** @var ThemeDataType $theme_datatype */
            $theme_datatype = $em->getRepository('ODRAdminBundle:ThemeDataType')->findOneBy(
                array('themeElement' => $theme_element->getId(), 'dataType' => $child_datatype->getId())
            );
            if ( is_null($theme_datatype) )
                throw new ODRNotFoundException('Theme Datatype');


            // Need to ensure that all of these entities exist...
            $theme = $theme_element->getTheme();
            if ( !is_null($theme->getDeletedAt()) )
                throw new ODRNotFoundException('Theme');

            $parent_datatype = $theme->getDataType();
            if ( !is_null($parent_datatype->getDeletedAt()) )
                throw new ODRNotFoundException('Datatype');


            // Also need this in order to render the form, though it can't be changed from here
            /** @var DataTree $datatree */
            $datatree = $em->getRepository('ODRAdminBundle:DataTree')->findOneBy(
                array('ancestor' => $parent_datatype->getId(), 'descendant' => $child_datatype->getId())
            );
            if ( $datatree == null )
                throw new ODRNotFoundException('Datatree');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Throw an exception if the user isn't allowed to do this
            self::canModifyTheme($user, $theme);

            // Also ensure users can view the child datatype
            if ( !$permissions_service->canViewDatatype($user, $child_datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // Might be useful in the future...
            $is_top_level = true;
            if ( $child_datatype->getId() !== $child_datatype->getGrandparent()->getId() )
                $is_top_level = false;

            // Create the ThemeDatatype form object
            $theme_datatype_form = $this->createForm(
                UpdateThemeDatatypeForm::class,
                $theme_datatype,
                array(
                    'is_top_level' => $is_top_level,
                    'multiple_allowed' => $datatree->getMultipleAllowed(),
                )
            )->createView();


            // Return the slideout html
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Saves a ThemeDatatype properties form.  Kept separate from self::loadthemedatatypeAction()
     * because the 'master' theme designed by DisplaytemplateController.php needs to combine Datatype,
     * Datatree, and ThemeDatatype forms into a single slideout, but every other theme is only allowed
     * to modify ThemeDatatype entries.
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

            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ( is_null($theme_element) )
                throw new ODRNotFoundException('ThemeElement');

            /** @var DataType $child_datatype */
            $child_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( is_null($child_datatype) )
                throw new ODRNotFoundException('Datatype');

            /** @var ThemeDataType $theme_datatype */
            $theme_datatype = $em->getRepository('ODRAdminBundle:ThemeDataType')->findOneBy(
                array('themeElement' => $theme_element->getId(), 'dataType' => $child_datatype->getId())
            );
            if ( is_null($theme_datatype) )
                throw new ODRNotFoundException('Theme Datatype');


            // Need to ensure that all of these entities exist...
            $theme = $theme_element->getTheme();
            if ( !is_null($theme->getDeletedAt()) )
                throw new ODRNotFoundException('Theme');

            $parent_datatype = $theme->getDataType();
            if ( !is_null($parent_datatype->getDeletedAt()) )
                throw new ODRNotFoundException('DataType');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Throw an exception if the user isn't allowed to do this
            self::canModifyTheme($user, $theme);

            // Also ensure users can view the child datatype
            if ( !$permissions_service->canViewDatatype($user, $child_datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Populate new ThemeDataType form
            $submitted_data = new ThemeDataType();

            // Check if multiple child/linked datarecords are allowed for datatype
            /** @var DataTree $datatree */
            $datatree = $em->getRepository('ODRAdminBundle:DataTree')->findOneBy(
                array('ancestor' => $parent_datatype->getId(), 'descendant' => $child_datatype->getId())
            );

            // Might be useful in the future...
            $is_top_level = true;
            if ( $child_datatype->getId() !== $child_datatype->getGrandparent()->getId() )
                $is_top_level = false;

            // Create the ThemeDatatype form object
            $theme_datatype_form = $this->createForm(
                UpdateThemeDatatypeForm::class,
                $submitted_data,
                array(
                    'is_top_level' => $is_top_level,
                    'multiple_allowed' => $datatree->getMultipleAllowed(),
                )
            );

            $theme_datatype_form->handleRequest($request);
            if ($theme_datatype_form->isSubmitted()) {

                if ($theme_datatype_form->isValid()) {
                    // Save all changes made via the form
                    $properties = array(
                        'display_type' => $submitted_data->getDisplayType(),
                    );
                    $entity_modify_service->updateThemeDatatype($user, $theme_datatype, $properties);

                    // Update cached version of theme
                    $theme_info_service->updateThemeCacheEntry($theme, $user);
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Loads an ODR ThemeDatafield properties form.  Kept separate from self::savethemedatafieldAction()
     * because the 'master' theme designed by DisplaytemplateController.php needs to combine Datafield
     * and ThemeDatafield forms onto a single slideout, but every other theme is only allowed to
     * modify ThemeDatafield entries.
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

            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ( is_null($datafield) )
                throw new ODRNotFoundException('Datafield');

            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ( is_null($theme_element) )
                throw new ODRNotFoundException('ThemeElement');

            // Locate the ThemeDatafield entity
            /** @var ThemeDataField $theme_datafield */
            $theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField')->findOneBy(
                array('dataField' => $datafield->getId(), 'themeElement' => $theme_element->getId())
            );
            if ( is_null($theme_datafield) )
                throw new ODRNotFoundException('ThemeDatafield');


            // Need to ensure that all of these entities exist...
            $theme = $theme_element->getTheme();
            if ( !is_null($theme->getDeletedAt()) )
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ( !is_null($datatype->getDeletedAt()) )
                throw new ODRNotFoundException('Datatype');

            if ($datafield->getDataType()->getId() !== $datatype->getId())
                throw new ODRBadRequestException('Invalid Form');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Throw an exception if the user isn't allowed to do this
            self::canModifyTheme($user, $theme, $datafield);
            // --------------------


            // Create the ThemeDatafield form object
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
            $return['d'] = $templating->render(
                'ODRAdminBundle:Theme:theme_datafield_properties_form.html.twig',
                array(
                    'theme_datafield' => $theme_datafield,
                    'theme_datafield_form' => $theme_datafield_form,
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x7c3192e4;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Saves an ODR ThemeDatafield properties form.  Kept separate from self::loadthemedatafieldAction()
     * because the 'master' theme designed by DisplaytemplateController.php needs to combine Datafield
     * and ThemeDatafield forms onto a single slideout, but every other theme is only allowed to
     * modify ThemeDatafield entries.
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

            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ( is_null($theme_element) )
                throw new ODRNotFoundException('ThemeElement');

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ( is_null($datafield) )
                throw new ODRNotFoundException('Datafield');

            /** @var ThemeDataField $theme_datafield */
            $theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField')->findOneBy(
                array('themeElement' => $theme_element->getId(), 'dataField' => $datafield->getId())
            );
            if ( is_null($theme_datafield) )
                throw new ODRNotFoundException('ThemeDatafield');


            // Need to ensure that all of these entities exist...
            $theme = $theme_element->getTheme();
            if ( !is_null($theme->getDeletedAt()) )
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ( !is_null($datatype->getDeletedAt()) )
                throw new ODRNotFoundException('Datatype');

            if ($datafield->getDataType()->getId() !== $datatype->getId())
                throw new ODRBadRequestException('Invalid Form');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Throw an exception if the user isn't allowed to do this
            self::canModifyTheme($user, $theme, $datafield);
            // --------------------


            // Populate new ThemeDataField form
            $submitted_data = new ThemeDataField();
            $theme_datafield_form = $this->createForm(
                UpdateThemeDatafieldForm::class,
                $submitted_data
            );

            $theme_datafield_form->handleRequest($request);
            if ($theme_datafield_form->isSubmitted()) {

//$theme_datafield_form->addError( new FormError('DO NOT SAVE') );

                if ($theme_datafield_form->isValid()) {
                    // Users need to be able to change the "hidden" property on a "master" theme
                    // Display/Edit/SearchResults/TextResults will respect this property, but most
                    //  other areas of ODR ignore it
//                    if ($theme->getThemeType() === 'master')
//                        $submitted_data->setHidden(0);

                    // Save all changes made via the submitted form
                    $properties = array(
//                        'displayOrder' => $submitted_data->getDisplayOrder(),    // Not allowed to change this value through this controller action
                        'cssWidthMed' => $submitted_data->getCssWidthMed(),
                        'cssWidthXL' => $submitted_data->getCssWidthXL(),

                        // Not allowed to change these values through this controller action
//                        'hidden' => $submitted_data->getHidden(),
//                        'hideHeader' => $submitted_data->getHideHeader(),
//                        'useIconInTables' => $submitted_data->getUseIconInTables(),
                    );
                    $entity_modify_service->updateThemeDatafield($user, $theme_datafield, $properties);

                    // Update the cached version of the theme
                    $theme_info_service->updateThemeCacheEntry($theme, $user);
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Toggles the hidden status of a ThemeDatafield.
     *
     * @param integer $theme_element_id
     * @param integer $datafield_id
     * @param Request $request
     *
     * @return Response
     */
    public function themedatafieldvisibilityAction($theme_element_id, $datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = array();

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ( is_null($datafield) )
                throw new ODRNotFoundException('Datafield');

            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ( is_null($theme_element) )
                throw new ODRNotFoundException('ThemeElement');

            /** @var ThemeDataField $theme_datafield */
            $theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField')->findOneBy(
                array(
                    'themeElement' => $theme_element_id,
                    'dataField' => $datafield_id,
                )
            );
            if ( is_null($theme_datafield) )
                throw new ODRNotFoundException('ThemeDatafield');

            $theme = $theme_element->getTheme();
            if ( !is_null($theme->getDeletedAt()) )
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Throw an exception if the user isn't allowed to do this
            self::canModifyTheme($user, $theme, $datafield);
            // --------------------

            // Users need to be able to change the "hidden" property on a "master" theme
            // Display/Edit/SearchResults/TextResults will respect this property, but most other
            //  areas of ODR ignore it
//            if ( $theme->getThemeType() === 'master' )
//                throw new ODRBadRequestException("Unable to change hidden status of ThemeDatafields on a datatype's master theme");


            // Toggle the hidden status of the specified theme_datafield
            if ( $theme_datafield->getHidden() ) {
                $properties = array(
                    'hidden' => false,
                );
                $entity_modify_service->updateThemeDatafield($user, $theme_datafield, $properties);
            }
            else {
                $properties = array(
                    'hidden' => true,
                );
                $entity_modify_service->updateThemeDatafield($user, $theme_datafield, $properties);
            }

            // Update cached version of theme
            $theme_info_service->updateThemeCacheEntry($theme, $user);
        }
        catch (\Exception $e) {
            $source = 0x1dbd0605;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Toggles the hidden status of a header for a ThemeDatafield.
     *
     * @param integer $theme_element_id
     * @param integer $datafield_id
     * @param Request $request
     *
     * @return Response
     */
    public function themedatafieldheadervisibilityAction($theme_element_id, $datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = array();

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ( is_null($datafield) )
                throw new ODRNotFoundException('Datafield');

            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ( is_null($theme_element) )
                throw new ODRNotFoundException('ThemeElement');

            /** @var ThemeDataField $theme_datafield */
            $theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField')->findOneBy(
                array(
                    'themeElement' => $theme_element_id,
                    'dataField' => $datafield_id,
                )
            );
            if ( is_null($theme_datafield) )
                throw new ODRNotFoundException('ThemeDatafield');

            $theme = $theme_element->getTheme();
            if ( !is_null($theme->getDeletedAt()) )
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // For the moment, this only makes sense on an Image datafield
            if ( $datafield->getFieldType()->getTypeClass() !== 'Image' )
                throw new ODRBadRequestException();


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Throw an exception if the user isn't allowed to do this
            self::canModifyTheme($user, $theme, $datafield);
            // --------------------

            // Users need to be able to change the "hidden" property on a "master" theme
            // Display/Edit/SearchResults/TextResults will respect this property, but most other
            //  areas of ODR ignore it
//            if ( $theme->getThemeType() === 'master' )
//                throw new ODRBadRequestException("Unable to change hidden status of ThemeDatafields on a datatype's master theme");


            // Toggle the hidden status of the specified theme_datafield
            if ( $theme_datafield->getHideHeader() ) {
                $properties = array(
                    'hideHeader' => false,
                );
                $entity_modify_service->updateThemeDatafield($user, $theme_datafield, $properties);
            }
            else {
                $properties = array(
                    'hideHeader' => true,
                );
                $entity_modify_service->updateThemeDatafield($user, $theme_datafield, $properties);
            }

            // Update cached version of theme
            $theme_info_service->updateThemeCacheEntry($theme, $user);
        }
        catch (\Exception $e) {
            $source = 0xc077b07d;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Toggles whether the file field displays a filename or an icon in a Table theme.
     *
     * @param integer $theme_element_id
     * @param integer $datafield_id
     * @param Request $request
     *
     * @return Response
     */
    public function themedatafielduseiconAction($theme_element_id, $datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = array();

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ( is_null($datafield) )
                throw new ODRNotFoundException('Datafield');

            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ( is_null($theme_element) )
                throw new ODRNotFoundException('ThemeElement');

            /** @var ThemeDataField $theme_datafield */
            $theme_datafield = $em->getRepository('ODRAdminBundle:ThemeDataField')->findOneBy(
                array(
                    'themeElement' => $theme_element_id,
                    'dataField' => $datafield_id,
                )
            );
            if ( is_null($theme_datafield) )
                throw new ODRNotFoundException('ThemeDatafield');

            $theme = $theme_element->getTheme();
            if ( !is_null($theme->getDeletedAt()) )
                throw new ODRNotFoundException('Theme');

            $datatype = $theme->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // For the moment, this only makes sense on an File datafield
            if ( $datafield->getFieldType()->getTypeClass() !== 'File' )
                throw new ODRBadRequestException();


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Throw an exception if the user isn't allowed to do this
            self::canModifyTheme($user, $theme, $datafield);
            // --------------------


            // Toggle the property for the specified theme_datafield
            if ( $theme_datafield->getUseIconInTables() ) {
                $properties = array(
                    'useIconInTables' => false,
                );
                $entity_modify_service->updateThemeDatafield($user, $theme_datafield, $properties);
            }
            else {
                $properties = array(
                    'useIconInTables' => true,
                );
                $entity_modify_service->updateThemeDatafield($user, $theme_datafield, $properties);
            }

            // Update cached version of theme
            $theme_info_service->updateThemeCacheEntry($theme, $user);

            // TableThemeHelperService builds the actual string displayed for a file field, so don't
            //  need to wipe the cached_table_data_<dr_id> entry
        }
        catch (\Exception $e) {
            $source = 0xe2e50d01;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Updates the display order of DataFields inside a ThemeElement, and/or moves the DataField to
     * a new ThemeElement.
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
            $post = $request->request->all();
//print_r($post);  return;

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_theme_element = $em->getRepository('ODRAdminBundle:ThemeElement');

            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


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

            // Throw an exception if the user isn't allowed to do this
            self::canModifyTheme($user, $theme);
            // --------------------


            // ----------------------------------------
            // Ensure this datafield isn't trying to move into an illegal theme element
            if ( $ending_theme_element->getThemeDataType()->count() > 0 )
                throw new \Exception('Unable to move a Datafield into a ThemeElement that already has a child/linked Datatype');
            if ( $ending_theme_element->getThemeRenderPluginInstance()->count() > 0 )
                throw new \Exception('Unable to move a Datafield into a ThemeElement that is already being used by a RenderPlugin');

            // NOTE - could technically check for deleted datafields, but it *shouldn't* matter if
            //  any exist...don't think displayOrder can get messed up, and it's not really a
            //  problem even if it does


            // ----------------------------------------
            // Ensure all the datafields in the $post belong to a single datatype
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


            // When changing datafield order, there aren't any appreciable differences between
            //  'master', 'search_results', and 'table' themes

            // Load all theme_datafield entries currently in the destination theme element
            $query = $em->createQuery(
               'SELECT tdf
                FROM ODRAdminBundle:ThemeDataField tdf
                WHERE tdf.themeElement = :theme_element_id
                AND tdf.deletedAt IS NULL'
            )->setParameters( array('theme_element_id' => $ending_theme_element->getId()) );
            $results = $query->getResult();
            /** @var ThemeDataField[] $results */

            $tdf_list = array();
            foreach ($results as $num => $tdf)
                $tdf_list[ $tdf->getDataField()->getId() ] = $tdf;


            // ----------------------------------------
            // Update the order of the datafields in the destination theme element
            foreach ($post as $index => $df_id) {

                if ( isset($tdf_list[$df_id]) ) {
                    // Ensure each datafield within the ending theme_element has the correct
                    //  display_order
                    $tdf = $tdf_list[$df_id];
                    if ( $tdf->getDisplayOrder() !== $index ) {
                        $properties = array(
                            'displayOrder' => $index
                        );
                        $entity_modify_service->updateThemeDatafield($user, $tdf, $properties, true);    // don't flush immediately...
                    }
                }
                else {
                    // If the datafield is not currently listed within the ending theme_element, then
                    //  it got moved into the ending theme_element from somewhere else

                    /** @var ThemeDataField $old_tdf_entry */
                    $old_tdf_entry = $em->getRepository('ODRAdminBundle:ThemeDataField')->findOneBy(
                        array(
                            'dataField' => $df_id,
                            'themeElement' => $initial_theme_element->getId()
                        )
                    );

                    if ($old_tdf_entry == null) {
                        // If this previous tdf entry doesn't exist, then something is wrong
                        throw new \Exception('Previous theme_datafield entry for Datafield '.$df_id.' themeElement '.$initial_theme_element_id.' does not exist');
                    }
                    else {
                        // Otherwise, update the previous tdf entry so it's in the desired position
                        //  in the ending theme_element
                        $properties = array(
                            'displayOrder' => $index,
                            'themeElement' => $ending_theme_element,
                        );
                        $entity_modify_service->updateThemeDatafield($user, $old_tdf_entry, $properties, true);    // don't flush immediately

                        // Don't need to redo display_order of the other theme_datafield entries in
                        //  the initial theme_element...the values don't need to be contiguous
                    }
                }
            }
            $em->flush();

            // Update the cached version of the theme
            $theme_info_service->updateThemeCacheEntry($theme, $user);
        }
        catch (\Exception $e) {
            $source = 0x7d6d495b;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
