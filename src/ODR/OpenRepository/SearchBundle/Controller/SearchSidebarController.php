<?php

/**
 * Open Data Repository Data Publisher
 * Search Sidebar Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Contains controller actions specifically for modifying search sidebar layouts.
 */

namespace ODR\OpenRepository\SearchBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Controllers/Classes
use ODR\AdminBundle\Controller\ODRCustomController;
// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\SidebarLayout;
use ODR\AdminBundle\Entity\SidebarLayoutMeta;
use ODR\AdminBundle\Entity\SidebarLayoutPreferences;
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
use ODR\AdminBundle\Form\UpdateSidebarLayoutForm;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\CloneThemeService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\ODRRenderService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchSidebarService;
// Symfony
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Templating\EngineInterface;


class SearchSidebarController extends ODRCustomController
{

    /**
     * Returns a list of sidebar layouts the current user can use in the current context for the given
     * datatype.
     *
     * @param integer $datatype_id
     * @param string $page_type {@link SearchSidebarService::PAGE_TYPES}
     * @param string $search_key
     * @param Request $request
     *
     * @return Response $response
     */
    public function getavailablesidebarlayoutsAction($datatype_id, $page_type, $search_key, Request $request)
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
            /** @var SearchSidebarService $search_sidebar_service */
            $search_sidebar_service = $this->container->get('odr.search_sidebar_service');
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
            // --------------------

            // Not attempting to verify $page_type...
            $selected_layout_id = 0;
            if ( $page_type !== '' ) {
                // ...SearchSidebarService will verify if being called from a location where it matters
                $selected_layout_id = $search_sidebar_service->getPreferredSidebarLayoutId($user, $datatype_id, $page_type);
            }

            // Get all available sidebar layouts for this datatype that the user can view
            $available_layouts = $search_sidebar_service->getAvailableSidebarLayouts($user, $datatype);


            // ----------------------------------------
            // Need to provide a formatted version of what getAvailableSidebarLayouts() returned
            //  as part of the "default_for" entry
            $formatted_page_type = ucfirst( str_replace('_', ' ', $page_type) );

            $available_page_types = array();
            foreach (SearchSidebarService::PAGE_TYPES as $num => $str)
                $available_page_types[$str] = ucfirst( str_replace('_', ' ', $str) );


            // TODO - don't really want another dialog just for selecting a different sidebar layout...
            // Render and return the layout chooser dialog
            $return['d'] = $templating->render(
                'ODRAdminBundle:Default:choose_view.html.twig',
                array(
                    'user' => $user,
                    'is_datatype_admin' => $is_datatype_admin,

                    'datatype' => $datatype,
                    'search_key' => $search_key,

                    'available_layouts' => $available_layouts,
                    'selected_layout_id' => $selected_layout_id,

                    'page_type' => $page_type,
                    'formatted_page_type' => $formatted_page_type,
                    'available_page_types' => $available_page_types,
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x4a6c9218;
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
     * allowed to make changes to a layout.  This function throws an error if the user is not allowed.
     *
     * @param ODRUser $user
     * @param SidebarLayout $sidebar_layout
     * @param DataFields|null $datafield
     * @throws ODRForbiddenException
     */
    private function canModifySidebarLayout($user, $sidebar_layout, $datafield = null)
    {
        /** @var PermissionsManagementService $permissions_service */
        $permissions_service = $this->container->get('odr.permissions_management_service');

        $datatype = $sidebar_layout->getDataType();

        // ----------------------------------------
        // Users have to be logged in to make any of these changes...
        if ($user === "anon.")
            throw new ODRForbiddenException();

        // ...and also have to be able to at least view the datatype being modified
        if ( !$permissions_service->canViewDatatype($user, $datatype) )
            throw new ODRForbiddenException();
//
//        // If this theme is a "local copy" of a remote datatype, then also need to be able to view
//        //  the local datatype to be able to make changes to this theme
//        if ( !$permissions_service->canViewDatatype($user, $local_parent_datatype) )
//            throw new ODRForbiddenException();

        // If the action is modifying a datafield, then they need to be able to view it too
        if ( !is_null($datafield) && !$permissions_service->canViewDatafield($user, $datafield) )
            throw new ODRForbiddenException();

        // If the user didn't create this layout...
        if ( $sidebar_layout->getCreatedBy()->getId() !== $user->getId() ) {
            // ...then they're only allowed to modify it if they're an admin of the local datatype
            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
        }

        // Otherwise, user created the layout, so they're allowed to modify it
    }


    /**
     * Creates a new Sidebar Layout, then opens its edit page.
     *
     * @param int $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function createsidebarlayoutAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var EntityCreationService $entity_create_service */
            $entity_create_service = $this->container->get('odr.entity_creation_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Throw an exception if the user isn't allowed to do this
            if ( !$permissions_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // Create a new SidebarLayout
            $sidebar_layout = $entity_create_service->createSidebarLayout($user, $datatype);

            // Return the id of the new entity
            $return['d'] = array(
                'sidebar_layout_id' => $sidebar_layout->getId(),
            );

        }
        catch (\Exception $e) {
            $source = 0x9eb6132a;
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
     * Opens the page to modify a Sidebar layout.
     *
     * @param int $datatype_id
     * @param int $sidebar_layout_id
     * @param string $search_key
     * @param Request $request
     *
     * @return Response
     */
    public function modifysidebarlayoutAction($datatype_id, $sidebar_layout_id, $search_key, Request $request)
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

            /** @var SidebarLayout $sidebar_layout */
            $sidebar_layout = $em->getRepository('ODRAdminBundle:SidebarLayout')->find($sidebar_layout_id);
            if ($sidebar_layout == null)
                throw new ODRNotFoundException('Sidebar Layout');

            if ( $sidebar_layout->getDataType()->getId() !== $datatype->getId() )
                throw new ODRBadRequestException();

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Throw an exception if the user isn't allowed to do this
            self::canModifySidebarLayout($user, $sidebar_layout);
            // --------------------

            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'html' => $odr_render_service->getSidebarDesignHTML($user, $sidebar_layout, $search_key),
            );

        }
        catch (\Exception $e) {
            $source = 0x17ec0229;
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
     * Saves changes made to a sidebar layout's name/description.  Loading the form is automatically
     * done inside ODRRenderService::getSidebarDesignHTML()
     *
     * @param int $sidebar_layout_id
     * @param Request $request
     *
     * @return Response
     */
    public function savesidebarlayoutpropertiesAction($sidebar_layout_id, Request $request)
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


            /** @var SidebarLayout $sidebar_layout */
            $sidebar_layout = $em->getRepository('ODRAdminBundle:SidebarLayout')->find($sidebar_layout_id);
            if ($sidebar_layout == null)
                throw new ODRNotFoundException('Sidebar Layout');

            $datatype = $sidebar_layout->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Throw an exception if the user isn't allowed to do this
            self::canModifySidebarLayout($user, $sidebar_layout);
            // --------------------


            // Populate new SidebarLayout form
            $submitted_data = new SidebarLayoutMeta();
            $sidebar_layout_form = $this->createForm(
                UpdateSidebarLayoutForm::class,
                $submitted_data,
            );
            $sidebar_layout_form->handleRequest($request);


            // --------------------
            if ($sidebar_layout_form->isSubmitted()) {
//                $sidebar_layout_form->addError( new FormError('DO NOT SAVE') );

                if ($sidebar_layout_form->isValid()) {
                    // Save any changes made in the form
                    $properties = array(
                        'layoutName' => $submitted_data->getLayoutName(),
                        'layoutDescription' => $submitted_data->getLayoutDescription(),
                    );
                    $entity_modify_service->updateSidebarLayoutMeta($user, $sidebar_layout, $properties);
                }
                else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($sidebar_layout_form);
                    throw new \Exception($error_str);
                }
            }
        }
        catch (\Exception $e) {
            $source = 0x6f2b6cb7;
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
     * By default, SidebarLayouts are only visible and usable by their creator...this toggles whether
     * any user is allowed to view/use them.  "Shared" and "Public" are synonyms here.
     *
     * @param integer $sidebar_layout_id
     * @param Request $request
     *
     * @return Response
     */
    public function sidebarlayoutsharedAction($sidebar_layout_id, Request $request)
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


            /** @var SidebarLayout $sidebar_layout */
            $sidebar_layout = $em->getRepository('ODRAdminBundle:SidebarLayout')->find($sidebar_layout_id);
            if ($sidebar_layout == null)
                throw new ODRNotFoundException('Sidebar Layout');

            $datatype = $sidebar_layout->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Throw an exception if the user isn't allowed to do this
            self::canModifySidebarLayout($user, $sidebar_layout);
            // --------------------


            // If the layout is currently a "default" for the datatype, then it needs to always
            //  remain "shared"
            if ($sidebar_layout->getDefaultFor() > 0 && $sidebar_layout->getShared())
                throw new ODRBadRequestException("A 'default' layout must always be shared");


            // Toggle the shared status of the specified layout...
            if ( $sidebar_layout->getShared() ) {
                // Layout is currently shared...
                $properties = array(
                    'shared' => false,
                );
                $entity_modify_service->updateSidebarLayoutMeta($user, $sidebar_layout, $properties);

                $return['d']['public'] = false;
            }
            else {
                // Layout is not currently shared...
                $properties = array(
                    'shared' => true,
                );
                $entity_modify_service->updateSidebarLayoutMeta($user, $sidebar_layout, $properties);

                $return['d']['public'] = true;
            }
        }
        catch (\Exception $e) {
            $source = 0x3eefd4e5;
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
     * Datatype admins have the ability to set any shared sidebar layout as "default" for a given
     * datatype.
     *
     * @param string $page_type {@link SearchSidebarService::PAGE_TYPES}
     * @param integer $sidebar_layout_id
     * @param Request $request
     *
     * @return Response
     */
    public function setdatabasedefaultlayoutAction($page_type, $sidebar_layout_id, Request $request)
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


            /** @var SidebarLayout $sidebar_layout */
            $sidebar_layout = $em->getRepository('ODRAdminBundle:SidebarLayout')->find($sidebar_layout_id);
            if ($sidebar_layout == null)
                throw new ODRNotFoundException('Sidebar Layout');

            $datatype = $sidebar_layout->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


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
            $page_type_id = array_search($page_type, SearchSidebarService::PAGE_TYPES);
            if ( $page_type_id === false )
                throw new ODRBadRequestException('"'.$page_type.'" is not a supported page type');

            // Query the database for the default top-level layout for this datatype/page_type combo
            // NOTE: using native SQL, because Doctrine apparently hates the '&' operator
            $query =
               'SELECT sl.id
                FROM odr_sidebar_layout AS sl
                JOIN odr_sidebar_layout_meta AS slm ON slm.sidebar_layout_id = sl.id
                WHERE sl.data_type_id = :datatype_id AND (slm.default_for & :page_type_id)
                AND sl.deletedAt IS NULL AND slm.deletedAt IS NULL';
            $params = array(
                'datatype_id' => $datatype->getId(),
                'page_type_id' => $page_type_id,
            );
            $conn = $em->getConnection();
            $results = $conn->executeQuery($query, $params);

            // Each of the layouts found by this query need to be set to "not default"...
            foreach ($results as $result) {
                $sl_id = $result['id'];
                if ( $sidebar_layout->getId() !== $sl_id ) {
                    /** @var SidebarLayout $sl */
                    $sl = $em->getRepository('ODRAdminBundle:SidebarLayout')->find($sl_id);
                    $new_defaults = $sl->getDefaultFor() - $page_type_id;
                    $properties = array(
                        'defaultFor' => $new_defaults
                    );

                    $entity_modify_service->updateSidebarLayoutMeta($user, $sl, $properties, true);    // Don't flush immediately...
                }
            }

            // ...afterward, specify this layout as the default (and shared, if it isn't already)
            $new_defaults = $sidebar_layout->getDefaultFor() + $page_type_id;
            $properties = array(
                'shared' => true,
                'defaultFor' => $new_defaults
            );
            $entity_modify_service->updateSidebarLayoutMeta($user, $sidebar_layout, $properties);

            $return['d'] = "success";
        }
        catch (\Exception $e) {
            $source = 0x2b53b879;
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
     * Because a sidebar layout can be a default for multiple page_types, it's handy to have a way
     * to remove a default without requiring another layout to take its place.
     *
     * @param string $page_type {@link SearchSidebarService::PAGE_TYPES}
     * @param integer $sidebar_layout_id
     * @param Request $request
     *
     * @return Response
     */
    public function unsetdatabasedefaultlayoutAction($page_type, $sidebar_layout_id, Request $request)
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


            /** @var SidebarLayout $sidebar_layout */
            $sidebar_layout = $em->getRepository('ODRAdminBundle:SidebarLayout')->find($sidebar_layout_id);
            if ($sidebar_layout == null)
                throw new ODRNotFoundException('Sidebar Layout');

            $datatype = $sidebar_layout->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


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
            $page_type_id = array_search($page_type, SearchSidebarService::PAGE_TYPES);
            if ( $page_type_id === false )
                throw new ODRBadRequestException('"'.$page_type.'" is not a supported page type');


            // Only do stuff if the layout is currently the default for this page type...
            if ( ($sidebar_layout->getDefaultFor() & $page_type_id) ) {
                $new_defaults = $sidebar_layout->getDefaultFor() - $page_type_id;
                $properties = array(
                    'shared' => true,
                    'defaultFor' => $new_defaults
                );
                $entity_modify_service->updateSidebarLayoutMeta($user, $sidebar_layout, $properties);
            }
            else {
                // If it's not the default for this page type, just return without doing anything
            }

            $return['d'] = "success";
        }
        catch (\Exception $e) {
            $source = 0x23ff0585;
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
     * Because a sidebar layout can be a default for multiple page_types, it's handy to have a way
     * to remove a default without requiring another layout to take its place.
     *
     * NOTE: the corresponding set is in SessionController::applyLayoutAction()
     *
     * @param string $page_type {@link SearchSidebarService::PAGE_TYPES}
     * @param integer $sidebar_layout_id
     * @param Request $request
     *
     * @return Response
     */
    public function unsetpersonaldefaultlayoutAction($page_type, $sidebar_layout_id, Request $request)
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


            /** @var SidebarLayout $sidebar_layout */
            $sidebar_layout = $em->getRepository('ODRAdminBundle:SidebarLayout')->find($sidebar_layout_id);
            if ($sidebar_layout == null)
                throw new ODRNotFoundException('Sidebar Layout');

            $datatype = $sidebar_layout->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


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
            $page_type_id = array_search($page_type, SearchSidebarService::PAGE_TYPES);
            if ( $page_type_id === false )
                throw new ODRBadRequestException('"'.$page_type.'" is not a supported page type');


            // Only do stuff if the user has a relevant sidebarLayoutPreferences entry...
            /** @var SidebarLayoutPreferences $slp */
            $slp = $em->getRepository('ODRAdminBundle:SidebarLayoutPreferences')->findOneBy(
                array(
                    'sidebarLayout' => $sidebar_layout->getId(),
                    'createdBy' => $user->getId(),
                )
            );

            if ($slp == null ) {
                // Nothing to do when the user doesn't have a sidebarLayoutPreferences entry
            }
            else {
                if ( ($slp->getDefaultFor() & $page_type_id) ) {
                    // Set the user's sidebarLayoutPreferences entry so it no longer refers to this page_type
                    $bitfield_value = $slp->getDefaultFor();
                    $bitfield_value -= $page_type_id;
                    $slp->setDefaultFor($bitfield_value);
                    $slp->setUpdatedBy($user);

                    $em->persist($slp);
                    $em->flush();
                    $em->refresh($slp);
                }
                else {
                    // If the user's sidebarLayoutPreferences entry doesn't have this layout listed
                    //  as the user's default, just return without doing anything
                }
            }

            $return['d'] = "success";
        }
        catch (\Exception $e) {
            $source = 0xaa95e19b;
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
     * Deletes a Sidebar Layout.
     *
     * @param int $sidebar_layout_id
     * @param Request $request
     *
     * @return Response
     */
    public function deletesidebarlayoutAction($sidebar_layout_id, Request $request)
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
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');


            /** @var SidebarLayout $sidebar_layout */
            $sidebar_layout = $em->getRepository('ODRAdminBundle:SidebarLayout')->find($sidebar_layout_id);
            if ($sidebar_layout == null)
                throw new ODRNotFoundException('Sidebar Layout');

            $datatype = $sidebar_layout->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Require users to be logged in and able to view the datatype before doing this...
            if ($user === "anon.")
                throw new ODRForbiddenException();
            if ( !$permissions_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();

            // Sidebar Layouts are only allowed to be deleted by the users who created them...
            if ( $sidebar_layout->getCreatedBy()->getId() !== $user->getId() ) {
                throw new ODRForbiddenException();

                // TODO - is there a reason to allow super admins to delete layouts they didn't create?
            }
            // Datatype admins aren't allowed to delete layouts they didn't create
            // --------------------


            // If the layout is currently marked as a default for the datatype, don't delete it
            if ($sidebar_layout->getDefaultFor() > 0)
                throw new ODRBadRequestException('Unable to delete a Sidebar Layout marked as "default"');


            // ----------------------------------------
            // Delete any datafield mappings tied to this SidebarLayout
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:SidebarLayoutMap AS slm
                SET slm.deletedAt = :now
                WHERE slm.sidebarLayout = :sidebar_layout_id
                AND slm.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'sidebar_layout_id' => $sidebar_layout->getId(),
                )
            );
            $rows = $query->execute();

            // Ensure users no longer have this SidebarLayout as their "personal default"
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:SidebarLayoutPreferences AS slp
                SET slp.deletedAt = :now
                WHERE slp.sidebarLayout = :sidebar_layout_id
                AND slp.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'sidebar_layout_id' => $sidebar_layout->getId(),
                )
            );
            $rows = $query->execute();

            // Delete this SidebarLayout
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:SidebarLayout AS sl, ODRAdminBundle:SidebarLayoutMeta AS slm
                SET sl.deletedAt = :now, sl.deletedBy = :user, slm.deletedAt = :now
                WHERE sl.id = :sidebar_layout_id
                AND slm.sidebarLayout = sl
                AND sl.deletedAt IS NULL AND slm.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'sidebar_layout_id' => $sidebar_layout->getId(),
                )
            );
            $rows = $query->execute();


            // ----------------------------------------
            // Delete the relevant cache entries
            $cache_service->delete('sidebar_layout_ids');
        }
        catch (\Exception $e) {
            $source = 0x22a03737;
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
    public function clonelayoutAction($theme_id, Request $request)
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
            $theme = $em->getRepository('ODRAdminBundle:SidebarLayout')->find($theme_id);
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
            $post = $_POST;
//print_r($post);  return;

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_theme_element = $em->getRepository('ODRAdminBundle:SidebarLayoutElement');

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
            self::canModifySidebarLayout($user, $theme);
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
                FROM ODRAdminBundle:SidebarLayoutDataField tdf
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
                    $old_tdf_entry = $em->getRepository('ODRAdminBundle:SidebarLayoutDataField')->findOneBy(
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
