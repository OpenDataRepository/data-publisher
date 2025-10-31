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
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\SidebarLayout;
use ODR\AdminBundle\Entity\SidebarLayoutMap;
use ODR\AdminBundle\Entity\SidebarLayoutMeta;
use ODR\AdminBundle\Entity\SidebarLayoutPreferences;
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
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\ODRRenderService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchSidebarService;
// Symfony
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Templating\EngineInterface;


class SearchSidebarController extends ODRCustomController
{

    /**
     * Re-renders and returns the HTML to search a datafield in the search sidebar.
     *
     * @param int $datafield_id
     * @param Request $request
     *
     * @return Response
     */
    public function reloadsearchdatafieldAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ( $datatype->getDeletedAt() !== null )
                throw new ODRNotFoundException('Datatype');


            // --------------------
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$permissions_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            if ( !$permissions_service->canViewDatafield($user, $datafield) )
                throw new ODRForbiddenException();
            // --------------------


            $searchable = $datafield->getSearchable();
            if ( $searchable === DataFields::NOT_SEARCHABLE ) {
                // Don't attempt to re-render the datafield if it's "not searchable"
                $return['d'] = array(
                    'needs_update' => false,
                    'html' => ''
                );
            }
            else {
                // Datafield is searchable, so it has an HTML element on the sidebar
                // Need the datafield's array entry in order to re-render it
                $datatype_array = $database_info_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't want links
                $df_array = $datatype_array[$datatype->getId()]['dataFields'][$datafield->getId()];

                $templating = $this->get('templating');
                $return['d'] = array(
                    'needs_update' => true,
                    'html' => $templating->render(
                        'ODROpenRepositorySearchBundle:Default:search_datafield.html.twig',
                        array(
                            'datatype_id' => $datatype->getId(),
                            'datafield' => $df_array,
                        )
                    )
                );
            }
        }
        catch (\Exception $e) {
            $source = 0x9d85646e;
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
     * Renders and returns the HTML for a reload of the search sidebar.
     *
     * @param string $search_key
     * @param string $intent
     * @param Request $request
     *
     * @return Response
     */
    public function reloadsearchsidebarAction($search_key, $intent, Request $request)
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
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var SearchSidebarService $search_sidebar_service */
            $search_sidebar_service = $this->container->get('odr.search_sidebar_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


            // Ensure it's a valid search key first...
            $search_params = $search_key_service->validateSearchKey($search_key);
            $dt_id = intval( $search_params['dt_id'] );

            /** @var DataType $target_datatype */
            $target_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($dt_id);
            if ( $target_datatype->getDeletedAt() !== null )
                throw new ODRNotFoundException('Datatype');


            // --------------------
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = $permissions_service->getUserPermissionsArray($user);
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];

            if ( !$permissions_service->canViewDatatype($user, $target_datatype) )
                throw new ODRForbiddenException();

            $logged_in = true;
            if ($user === 'anon.')
                $logged_in = false;
            // --------------------


            // ----------------------------------------
            // Need to determine whether the user is targetting a particular datatype id for inverse
            //  searching...
            $inverse_target_datatype_id = -1;
            if ( isset($search_params['inverse']) )
                $inverse_target_datatype_id = intval($search_params['inverse']);

            if ( $inverse_target_datatype_id === $target_datatype->getId() ) {
                $inverse_target_datatype_id = -1;
                unset( $search_params['inverse'] );
            }

            // Load the preferred sidebar layout unless dealing with StoredSearchKeys or the 'inverse'
            //  parameter
            $sidebar_layout_id = null;
            if ( !($intent === 'stored_search_keys' || $inverse_target_datatype_id !== -1) )
                $sidebar_layout_id = $search_sidebar_service->getPreferredSidebarLayoutId($user, $target_datatype->getId(), $intent);

            $sidebar_array = $search_sidebar_service->getSidebarDatatypeArray($user, $target_datatype->getId(), $search_params, $sidebar_layout_id);
            $user_list = $search_sidebar_service->getSidebarUserList($user, $sidebar_array);

            // No sense getting the inverse datatype names if dealing with the linking interface
            $inverse_dt_names = array();
            if ( $intent !== 'linking' )
                $inverse_dt_names = $search_sidebar_service->getSidebarInverseDatatypeNames($user, $target_datatype->getId());

            $preferred_theme_id = $theme_info_service->getPreferredThemeId($user, $target_datatype->getId(), 'search_results');
            $preferred_theme = $em->getRepository('ODRAdminBundle:Theme')->find($preferred_theme_id);

            $templating = $this->get('templating');
            $return['d'] = array(
                'num_params' => count($search_params),
                'html' => $templating->render(
                    'ODROpenRepositorySearchBundle:Default:search_sidebar.html.twig',
                    array(
                        'search_key' => $search_key,
                        'search_params' => $search_params,

                        // required twig/javascript parameters
                        'user' => $user,
                        'datatype_permissions' => $datatype_permissions,
                        'datafield_permissions' => $datafield_permissions,

                        'user_list' => $user_list,
                        'logged_in' => $logged_in,
                        'intent' => $intent,
                        'sidebar_reload' => true,

                        // datatype/datafields to search
                        'target_datatype' => $target_datatype,
                        'sidebar_array' => $sidebar_array,
                        'inverse_dt_names' => $inverse_dt_names,

                        // theme selection
                        'preferred_theme' => $preferred_theme,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0xaf1f4a0f;
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
     * Don't want to deal with base64 encoding/decoding inside javascript, so using this to trigger
     * a reload of a search key for inverse searching.
     *
     * @param integer $search_theme_id
     * @param string $search_key
     * @param integer $inverse_datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function reloadinversesearchkeyAction($search_theme_id, $search_key, $inverse_datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'json';
        $return['d'] = '';

        try {
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');

            // Convert the given search key into an array of parameters
            $search_params = $search_key_service->decodeSearchKey($search_key);

            // Not going to attempt to validate this here, since it'll be modified and forwarded to
            //  a controller action that will immediately validate the new search key
            $inverse_datatype_id = intval($inverse_datatype_id);

            if ( $inverse_datatype_id === -1 ) {
                // A value of '-1' means disable inverse searching
                unset( $search_params['inverse'] );
            }
            else {
                // Any other value means enable inverse searching...insert the parameter into the array
                $search_params['inverse'] = $inverse_datatype_id;
            }

            // Convert the array back into a base64encoded string...
            $new_search_key = $search_key_service->encodeSearchKey($search_params);

            // ...and generate/return a URL to run a new search with/without the 'inverse' param
            $path_str = $this->generateUrl(
                'odr_search_render',
                array(
                    'search_theme_id' => $search_theme_id,
                    'search_key' => $new_search_key
                )
            );
            $return['d'] = $path_str;
        }
        catch (\Exception $e) {
            $source = 0xa84a72f8;
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
     * Returns a list of sidebar layouts the current user can use in the current context for the given
     * datatype.
     *
     * @param integer $datatype_id
     * @param string $intent {@link SearchSidebarService::PAGE_INTENT}
     * @param string $search_key
     * @param Request $request
     *
     * @return Response $response
     */
    public function getavailablesidebarlayoutsAction($datatype_id, $intent, $search_key, Request $request)
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

            $is_super_admin = false;
            if ( $user instanceof ODRUser )
                $is_super_admin = $user->isSuperAdmin();
            // --------------------

            // Not attempting to verify intent...
            $selected_layout_id = 0;
            if ( $intent !== '' ) {
                // ...SearchSidebarService will verify if being called from a location where it matters
                $selected_layout_id = $search_sidebar_service->getPreferredSidebarLayoutId($user, $datatype_id, $intent);
            }

            // Get all available sidebar layouts for this datatype that the user can view
            $available_layouts = $search_sidebar_service->getAvailableSidebarLayouts($user, $datatype);


            // ----------------------------------------
            // Need to provide a formatted version of what getAvailableSidebarLayouts() returned
            //  as part of the "default_for" entry
            $formatted_intent = ucfirst( str_replace('_', ' ', $intent) );

            $available_intents = array();
            foreach (SearchSidebarService::PAGE_INTENT as $num => $str)
                $available_intents[$str] = ucfirst( str_replace('_', ' ', $str) );


            // Would prefer if this didn't use yet another dialog, but there's just too much
            //  useful information that needs displaying...
            $return['d'] = $templating->render(
                'ODROpenRepositorySearchBundle:Default:choose_sidebar_layout.html.twig',
                array(
                    'user' => $user,
                    'is_datatype_admin' => $is_datatype_admin,
                    'is_super_admin' => $is_super_admin,

                    'datatype' => $datatype,
                    'search_key' => $search_key,

                    'available_layouts' => $available_layouts,
                    'selected_layout_id' => $selected_layout_id,

                    'intent' => $intent,
                    'formatted_intent' => $formatted_intent,
                    'available_intents' => $available_intents,
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
     * Creates a new Sidebar Layout, then returns its ID
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

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
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

            // Delete the cached list of sidebar layouts
            $cache_service->delete('sidebar_layout_ids');
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
     * @param string $intent
     * @param string $search_key
     * @param Request $request
     *
     * @return Response
     */
    public function modifysidebarlayoutAction($datatype_id, $sidebar_layout_id, $intent, $search_key, Request $request)
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
            /** @var SearchSidebarService $search_sidebar_service */
            $search_sidebar_service = $this->container->get('odr.search_sidebar_service');


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

            // If $intent is the empty string, then this is likely being called from the datatype
            //  landing page...so it's probably safe to assume 'searching'
            if ( $intent === '' )
                $intent = 'searching';
            // Ensure the provided intent is valid
            $intent_id = array_search($intent, SearchSidebarService::PAGE_INTENT);
            if ( $intent_id === false )
                throw new ODRBadRequestException('"'.$intent.'" is not a supported sidebar intent');

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Throw an exception if the user isn't allowed to do this
            self::canModifySidebarLayout($user, $sidebar_layout);
            // --------------------

            // Easier on twig if the sidebar array is passed in...do not render with any search params,
            //  and do not fallback to the "master" sidebar layout if the requested sidebar layout
            //  is empty
            $search_params = array();
            $sidebar_array = $search_sidebar_service->getSidebarDatatypeArray($user, $datatype->getId(), $search_params, $sidebar_layout->getId(), false);

            // Render and return the page
            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'html' => $odr_render_service->getSidebarDesignHTML($user, $sidebar_layout, $sidebar_array, $intent, $search_key),
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

                // Need to unescape these values if they're coming from a wordpress install...
                $is_wordpress_integrated = $this->getParameter('odr_wordpress_integrated');
                if ( $is_wordpress_integrated ) {
                    $submitted_data->setLayoutName( stripslashes($submitted_data->getLayoutName()) );
                    $submitted_data->setLayoutDescription( stripslashes($submitted_data->getLayoutDescription()) );
                }

                $submitted_data->setLayoutName( trim($submitted_data->getLayoutName()) );
                if ( $submitted_data->getLayoutName() === '' )
                    $sidebar_layout_form->addError( new FormError("The Layout name can't be blank") );

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
     * For convenience, datatype admins are able to toggle a field's searchable status from a
     * sidebar layout design page.
     *
     * @param int $datafield_id
     * @param Request $request
     *
     * @return Response
     */
    public function sidebarsearchabletoggleAction($datafield_id, Request $request)
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
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$permissions_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Toggle the datafield's current searchable status
            if ( $datafield->getSearchable() === DataFields::NOT_SEARCHABLE ) {
                $properties = array(
                    'searchable' => DataFields::SEARCHABLE
                );
                $entity_modify_service->updateDatafieldMeta($user, $datafield, $properties);
            }
            else {
                $properties = array(
                    'searchable' => DataFields::NOT_SEARCHABLE
                );
                $entity_modify_service->updateDatafieldMeta($user, $datafield, $properties);
            }

        }
        catch (\Exception $e) {
            $source = 0x68103277;
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
            if ($sidebar_layout->getDefaultFor() > 0 && $sidebar_layout->isShared())
                throw new ODRBadRequestException("A 'default' layout must always be shared");


            // Toggle the shared status of the specified layout...
            if ( $sidebar_layout->isShared() ) {
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
     * @param string $intent {@link SearchSidebarService::PAGE_INTENT}
     * @param integer $sidebar_layout_id
     * @param Request $request
     *
     * @return Response
     */
    public function setdatabasedefaultlayoutAction($intent, $sidebar_layout_id, Request $request)
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


            // Ensure the provided intent is valid
            $intent_id = array_search($intent, SearchSidebarService::PAGE_INTENT);
            if ( $intent_id === false )
                throw new ODRBadRequestException('"'.$intent.'" is not a supported sidebar intent');

            // Query the database for the default top-level layout for this datatype/intent combo
            // NOTE: using native SQL, because Doctrine apparently hates the '&' operator
            $query =
               'SELECT sl.id
                FROM odr_sidebar_layout AS sl
                JOIN odr_sidebar_layout_meta AS slm ON slm.sidebar_layout_id = sl.id
                WHERE sl.data_type_id = :datatype_id AND (slm.default_for & :intent_id)
                AND sl.deletedAt IS NULL AND slm.deletedAt IS NULL';
            $params = array(
                'datatype_id' => $datatype->getId(),
                'intent_id' => $intent_id,
            );
            $conn = $em->getConnection();
            $results = $conn->executeQuery($query, $params);

            // Each of the layouts found by this query need to be set to "not default"...
            foreach ($results as $result) {
                $sl_id = $result['id'];
                if ( $sidebar_layout->getId() !== $sl_id ) {
                    /** @var SidebarLayout $sl */
                    $sl = $em->getRepository('ODRAdminBundle:SidebarLayout')->find($sl_id);
                    $new_defaults = $sl->getDefaultFor() - $intent_id;
                    $properties = array(
                        'defaultFor' => $new_defaults
                    );

                    $entity_modify_service->updateSidebarLayoutMeta($user, $sl, $properties, true);    // Don't flush immediately...
                }
            }

            // ...afterward, specify this layout as the default (and shared, if it isn't already)
            $new_defaults = $sidebar_layout->getDefaultFor() + $intent_id;
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
     * Because a sidebar layout can be a default for multiple intents, it's handy to have a way
     * to remove a default without requiring another layout to take its place.
     *
     * @param string $intent {@link SearchSidebarService::PAGE_INTENT}
     * @param integer $sidebar_layout_id
     * @param Request $request
     *
     * @return Response
     */
    public function unsetdatabasedefaultlayoutAction($intent, $sidebar_layout_id, Request $request)
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


            // Ensure the provided intent is valid
            $intent_id = array_search($intent, SearchSidebarService::PAGE_INTENT);
            if ( $intent_id === false )
                throw new ODRBadRequestException('"'.$intent.'" is not a supported sidebar intent');


            // Only do stuff if the layout is currently the default for this page type...
            if ( ($sidebar_layout->getDefaultFor() & $intent_id) ) {
                $new_defaults = $sidebar_layout->getDefaultFor() - $intent_id;
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
     * Because a sidebar layout can be a default for multiple intents, it's handy to have a way
     * to remove a default without requiring another layout to take its place.
     *
     * NOTE: the corresponding set is in SessionController::applyLayoutAction()
     *
     * @param string $intent {@link SearchSidebarService::PAGE_INTENT}
     * @param integer $sidebar_layout_id
     * @param Request $request
     *
     * @return Response
     */
    public function unsetpersonaldefaultlayoutAction($intent, $sidebar_layout_id, Request $request)
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


            // Ensure the provided intent is valid
            $intent_id = array_search($intent, SearchSidebarService::PAGE_INTENT);
            if ( $intent_id === false )
                throw new ODRBadRequestException('"'.$intent.'" is not a supported sidebar intent');


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
                if ( ($slp->getDefaultFor() & $intent_id) ) {
                    // Set the user's sidebarLayoutPreferences entry so it no longer refers to this intent
                    $bitfield_value = $slp->getDefaultFor();
                    $bitfield_value -= $intent_id;
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

            // Delete the SidebarLayout's meta entry...
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:SidebarLayoutMeta AS slm
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

            // ...and finally delete the SidebarLayout itself
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:SidebarLayout AS sl
                SET sl.deletedAt = :now, sl.deletedBy = :user
                WHERE sl.id = :sidebar_layout_id
                AND sl.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'user' => $user->getId(),
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
     * Clones the given SidebarLayout, setting the requesting user as its owner.
     *
     * @param int $sidebar_layout_id
     * @param Request $request
     *
     * @return Response
     */
    public function clonelayoutAction($sidebar_layout_id, Request $request)
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
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var Logger $logger */
            $logger = $this->get('logger');


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
            if ($user === 'anon.')
                throw new ODRForbiddenException();
            if ( !$permissions_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();

            // The user must be able to see the layout in order for them to be able to clone it
            if ( !$sidebar_layout->isShared() && $sidebar_layout->getCreatedBy()->getId() !== $user->getId() )
                throw new ODRForbiddenException();
            // --------------------


            // TODO - use a TrackedJob?  This is even faster than CloneThemeService, which is fast enough to not use TrackedJobs either...
            $logger->info('----------------------------------------');
            $logger->info('SearchSidebarController: attempting to make a clone of sidebar_layout '.$sidebar_layout->getId().', belonging to datatype '.$sidebar_layout->getDataType()->getId().' "'.$sidebar_layout->getDataType()->getShortName().'"...');

            $sidebar_layout_meta = $sidebar_layout->getSidebarLayoutMeta();
            $sidebar_layout_maps = $sidebar_layout->getSidebarLayoutMap();

            // Clone the sidebar layout...
            $new_sidebar_layout = clone $sidebar_layout;

            $new_sidebar_layout->setCreatedBy($user);
            $new_sidebar_layout->setUpdatedBy($user);
            $new_sidebar_layout->setCreated(new \DateTime());
            $new_sidebar_layout->setUpdated(new \DateTime());
            $em->persist($new_sidebar_layout);

            // Clone the sidebar layout's meta entry...
            $new_sidebar_layout_meta = clone $sidebar_layout_meta;
            $new_sidebar_layout_meta->setSidebarLayout($new_sidebar_layout);
            $new_sidebar_layout_meta->setLayoutName('Copy of '.$sidebar_layout_meta->getLayoutName());
            $new_sidebar_layout_meta->setLayoutDescription('Copy of '.$sidebar_layout_meta->getLayoutDescription());
            $new_sidebar_layout_meta->setShared(false);

            $new_sidebar_layout_meta->setCreatedBy($user);
            $new_sidebar_layout_meta->setUpdatedBy($user);
            $new_sidebar_layout_meta->setCreated(new \DateTime());
            $new_sidebar_layout_meta->setupdated(new \DateTime());
            $em->persist($new_sidebar_layout_meta);

            $logger->debug('SearchSidebarController: -- cloned the original sidebar_layout and its meta entry');

            // Clone each of the sidebar layout's mapping entries
            foreach ($sidebar_layout_maps as $sl_dfm) {
                /** @var SidebarLayoutMap $sl_dfm */
                $new_sl_dfm = clone $sl_dfm;
                $new_sl_dfm->setSidebarLayout($new_sidebar_layout);

                $new_sl_dfm->setCreatedBy($user);
                $new_sl_dfm->setUpdatedBy($user);
                $new_sl_dfm->setCreated(new \DateTime());
                $new_sl_dfm->setUpdated(new \DateTime());
                $em->persist($new_sl_dfm);

                if ( !is_null($sl_dfm->getDataField()) )
                    $logger->debug('SearchSidebarController: -- cloned sidebar_layout_map '.$sl_dfm->getId().' (df '.$sl_dfm->getDataField()->getId().' "'.$sl_dfm->getDataField()->getFieldName().'", dt '.$sl_dfm->getDataType()->getId().' "'.$sl_dfm->getDataType()->getShortName().'") from sidebar_layout '.$sidebar_layout->getId() );
                else
                    $logger->debug('SearchSidebarController: -- cloned sidebar_layout_map '.$sl_dfm->getId().' ("general search", dt '.$sl_dfm->getDataType()->getId().' "'.$sl_dfm->getDataType()->getShortName().'") from sidebar_layout '.$sidebar_layout->getId() );
            }

            // Save all changes and return the id of the new entity
            $em->flush();
            $em->refresh($new_sidebar_layout);

            $return['d'] = array(
                'new_sidebar_layout_id' => $new_sidebar_layout->getId()
            );

            // Delete the cached list of sidebar layouts
            $cache_service->delete('sidebar_layout_ids');
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
     * Attaches a datafield (or the "general search" input) to a layout.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function datafieldlayoutstatusAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = array();

        try {
            // Get post data
            $post = $request->request->all();
            if ( !isset($post['sidebar_layout_id']) /*|| !isset($post['datafield_id'])*/ || !isset($post['state']) )
                throw new ODRBadRequestException('Invalid Form');

            $sidebar_layout_id = intval( $post['sidebar_layout_id'] );
            $state = intval( $post['state'] );
            if ( !($state === SidebarLayoutMap::NEVER_DISPLAY || $state === SidebarLayoutMap::ALWAYS_DISPLAY /*|| $state === SidebarLayoutMap::EXTENDED_DISPLAY*/) )
                throw new ODRBadRequestException('Invalid Form');

            // Datafield id is optional
            $datafield_id = null;
            if ( isset($post['datafield_id']) )
                $datafield_id = intval( $post['datafield_id'] );


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var EntityCreationService $entity_create_service */
            $entity_create_service = $this->container->get('odr.entity_creation_service');
            /** @var ODRRenderService $odr_render_service */
            $odr_render_service = $this->container->get('odr.render_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchSidebarService $search_sidebar_service */
            $search_sidebar_service = $this->container->get('odr.search_sidebar_service');


            /** @var SidebarLayout $sidebar_layout */
            $sidebar_layout = $em->getRepository('ODRAdminBundle:SidebarLayout')->find($sidebar_layout_id);
            if ($sidebar_layout == null)
                throw new ODRNotFoundException('Sidebar Layout');

            $datatype = $sidebar_layout->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // The Datafield is optional...if not set, then it refers to the "general search" input
            $datafield = null;
            if ( !is_null($datafield_id) ) {
                /** @var DataFields $datafield */
                $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
                if ($datafield == null)
                    throw new ODRNotFoundException('Datafield');
            }


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = $permissions_service->getUserPermissionsArray($user);

            // Throw an exception if the user isn't allowed to do this
            self::canModifySidebarLayout($user, $sidebar_layout, $datafield);
            // --------------------

            if ( !is_null($datafield) ) {
                // Verify that the datafield is valid for this sidebar layout
                $dt_array = $database_info_service->getDatatypeArray($datatype->getGrandparent()->getId(), true);    // do need links here
                $dr_array = array();
                $permissions_service->filterByGroupPermissions($dt_array, $dr_array, $user_permissions);

                $found = false;
                foreach ($dt_array as $dt_id => $dt) {
                    if ( isset($dt['dataFields'][$datafield_id]) ) {
                        $found = true;
                        break;
                    }
                }

                if ( !$found )
                    throw new ODRBadRequestException('Invalid Datafield');
            }


            // What to do depends on what state is desired...
            /** @var SidebarLayoutMap $sidebar_layout_map */
            $sidebar_layout_map = $em->getRepository('ODRAdminBundle:SidebarLayoutMap')->findOneBy(
                array(
                    'sidebarLayout' => $sidebar_layout,
                    'dataField' => $datafield,    // NOTE: could be null
                )
            );

            $html = '';
            if ( $state === SidebarLayoutMap::NEVER_DISPLAY ) {
                // ...want to remove this datafield from this SidebarLayout

                if ($sidebar_layout_map != null) {
                    // If there's an entry tying this SidebarLayout to this datafield, then delete it
                    $sidebar_layout_map->setDeletedAt(new \DateTime());

                    // Mark the SidebarLayout itself as updated
                    $sidebar_layout->setUpdated(new \DateTime());
                    $sidebar_layout->setUpdatedBy($user);

                    $em->persist($sidebar_layout_map);
                    $em->persist($sidebar_layout);
                    $em->flush();
                }
            }
            else {
                // ...want to add this datafield to this SidebarLayout
                if ($sidebar_layout_map != null) {
                    // There's already an entry tying this SidebarLayout to this datafield...do nothing
                }
                else {
                    // Create an entry tying this datafield to the layout
                    if ( is_null($datafield) ) {
                        // Since datafield is null, this is a request to create a placeholder entry
                        //  for the "general search" input
                        $entity_create_service->createSidebarLayoutMap($user, $sidebar_layout, null, $datatype, SidebarLayoutMap::ALWAYS_DISPLAY);
                    }
                    else {
                        // Since datafield is not null, $datatype should refer to the field...doing
                        //  so eases dealing with datatype deletion or unlinking
                        $entity_create_service->createSidebarLayoutMap($user, $sidebar_layout, $datafield, $datafield->getDataType(), SidebarLayoutMap::ALWAYS_DISPLAY);
                    }

                    // It's easier if PHP renders and returns the HTML for the fake sidebar for the
                    //  UI...don't use any search params and do not fallback to the "master" layout
                    $search_params = array();
                    $sidebar_array = $search_sidebar_service->getSidebarDatatypeArray($user, $datatype->getId(), $search_params, $sidebar_layout->getId(), false);
                    $html = $odr_render_service->reloadSidebarDesignArea($datatype->getId(), $sidebar_array);

                    // Return the new sidebar array
                    $return['d']['html'] = $html;
                }
            }
        }
        catch (\Exception $e) {
            $source = 0x05d38949;
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
     * Updates the display order of DataFields inside a Sidebar Layout.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function datafieldorderAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $post = $request->request->all();
//print_r($post);  return;

            if ( !isset($post['sidebar_layout_id']) /*|| !isset($post['always_display']) || !isset($post['extended_display'])*/ )
                throw new ODRBadRequestException('Invalid Form');

            $sidebar_layout_id = $post['sidebar_layout_id'];

            $always_display = array();
            if ( isset($post['always_display']) ) {
                foreach ($post['always_display'] as $display_order => $df_id)
                    $always_display[$display_order] = intval($df_id);
            }

            $extended_display = array();
            if ( isset($post['extended_display']) ) {
                foreach ($post['extended_display'] as $display_order => $df_id)
                    $extended_display[$display_order] = intval($df_id);
            }

            // Verify that each datafield only shows up once across the entire post
            $df_ids = array();
            foreach ($always_display as $num => $df_id) {
                if ( isset($df_ids[$df_id]) )
                    throw new ODRBadRequestException('Invalid Form');
                $df_ids[$df_id] = 0;
            }
            foreach ($extended_display as $num => $df_id) {
                if ( isset($df_ids[$df_id]) )
                    throw new ODRBadRequestException('Invalid Form');
                $df_ids[$df_id] = 0;
            }


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
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

            // Complain if the user isn't allowed to modify this sidebar layout
            self::canModifySidebarLayout($user, $sidebar_layout);
            // --------------------


            // Need to verify that the datafields make sense for this sidebar layout...
            $dt_array = $database_info_service->getDatatypeArray($datatype->getGrandparent()->getId(), true);    // do need links here
            foreach ($dt_array as $dt_id => $dt) {
                if ( isset($dt['dataFields']) ) {
                    foreach ($dt['dataFields'] as $df_id => $df) {
                        if ( isset($df_ids[$df_id]) )
                            $df_ids[$df_id] = 1;
                    }
                }
            }
            // ...if any of the datafields weren't found, then complain
            foreach ($df_ids as $df_id => $num) {
                if ( $num === 0 && $df_id !== 0 )
                    throw new ODRBadRequestException('Invalid Form');
            }


            // ----------------------------------------
            // Now that everything is valid, save any changes to the mapping
            $repo_sidebar_layout_map = $em->getRepository('ODRAdminBundle:SidebarLayoutMap');

            self::updateSidebarLayoutMap($repo_sidebar_layout_map, $entity_modify_service, $user, $sidebar_layout, $always_display, SidebarLayoutMap::ALWAYS_DISPLAY);
            self::updateSidebarLayoutMap($repo_sidebar_layout_map, $entity_modify_service, $user, $sidebar_layout, $extended_display, SidebarLayoutMap::EXTENDED_DISPLAY);

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


    /**
     * @param EntityRepository $repo_sidebar_layout_map
     * @param EntityMetaModifyService $entity_modify_service
     * @param ODRUser $user
     * @param SidebarLayout $sidebar_layout
     * @param array $array An array of display_order -> datafield_ids
     * @param integer $category {@link SidebarLayoutMap::$category}
     */
    private function updateSidebarLayoutMap($repo_sidebar_layout_map, $entity_modify_service, $user, $sidebar_layout, $array, $category)
    {
        if ( empty($array) )
            return;

        foreach ($array as $display_order => $df_id) {
            // Attempt to locate the SidebarLayoutMap entry that refers to this datafield
            $sl_dfm = null;
            if ( $df_id !== 0 ) {
                // This should reference an existing datafield...
                $sl_dfm = $repo_sidebar_layout_map->findOneBy(
                    array(
                        'sidebarLayout' => $sidebar_layout,
                        'dataField' => $df_id,
                    )
                );
            }
            else {
                // This should reference the "general search" input...
                $sl_dfm = $repo_sidebar_layout_map->findOneBy(
                    array(
                        'sidebarLayout' => $sidebar_layout,
                        'dataField' => null,
                    )
                );
            }
            /** @var SidebarLayoutMap $sl_dfm */

            // The SidebarLayoutMap entry shouldn't be null at this point
            if ( !is_null($sl_dfm) ) {
                $properties = array(
                    'category' => $category,
                    'displayOrder' => $display_order
                );
                $entity_modify_service->updateSidebarLayoutMap($user, $sl_dfm, $properties);
            }
            else {
                // ...but throw an exception if it is for some reason
                throw new ODRException('Unable to locate SidebarLayoutMap entry for SidebarLayout '.$sidebar_layout->getId().', Datafield '.$df_id);
            }
        }
    }
}
