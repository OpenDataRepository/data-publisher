<?php

/**
 * Open Data Repository Data Publisher
 * Session Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This controller holds all functions to change settings related to a user's session, such as
 * changing pagelength of search results, changing which theme they want to use to view results, etc.
 *
 * Some of the stuff in TextResultsController technically could go here...but it's tightly coupled
 * to Datatables.js, so it makes more sense to leave those controller actions where they are.
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\SidebarLayout;
use ODR\AdminBundle\Entity\Theme;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\ODRTabHelperService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchSidebarService;
// Symfony
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class SessionController extends ODRCustomController
{

    /**
     * Changes the number of Datarecords displayed per ShortResults page.  This will also end up
     * changing the session variable the datatables plugin uses to store page length.
     *
     * A page currently using the datatables plugin uses TextResultsController::datatablesrowrequestAction()
     * to change its own length.
     *
     * @param integer $length  How many Datarecords to display on a page.
     * @param string $intent 'searching' or 'linking'
     * @param Request $request
     *
     * @return Response
     */
    public function pagelengthAction($length, $intent, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $cookie_key = '';
        $cookie_value = '';

        if ( $intent === '' )
            $intent = 'searching';

        try {
            // Grab necessary objects
            /** @var ODRTabHelperService $odr_tab_service */
            $odr_tab_service = $this->container->get('odr.tab_helper_service');

            // Grab the tab's id, if it exists
            $params = $request->query->all();
            $odr_tab_id = '';
            if ( isset($params['odr_tab_id']) )
                $odr_tab_id = $params['odr_tab_id'];

            if ($odr_tab_id !== '') {
                // Need to store this both in the user's session and a cookie
                $length = intval($length);
                $tab_data = $odr_tab_service->getTabData($odr_tab_id);

                // Store the change to this tab's page_length in the session
                $odr_tab_service->setPageLength($odr_tab_id, $length, $intent);

                // Also update the cookie value
                $dt_id = $tab_data['dt_id'];
                $cookie_key = 'datatype_'.$dt_id.'_page_length';
                if ( $intent === 'linking' )
                    $cookie_key = 'datatype_'.$dt_id.'_linking_page_length';

                // The value is stored back in the cookie after the response is created below
                $cookie_value = $length;
            }
        }
        catch (\Exception $e) {
            $source = 0x1cfad2a4;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->setCookie(new Cookie($cookie_key, $cookie_value));
        return $response;
    }


    /**
     * Changes the number of records displayed in the "current linked records" tables on the SearchLink
     * page.
     *
     * @param integer $remote_datatype_id
     * @param integer $length
     * @param Request $request
     *
     * @return Response
     */
    public function searchlinkpagelengthAction($remote_datatype_id, $length, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $cookie_key = '';
        $cookie_value = '';

        try {
            // Need to store this in a cookie...
            $cookie_key = 'datatype_'.$remote_datatype_id.'_searchlink_page_length';
            $cookie_value = $length;

            // The value is stored back in the cookie after the response is created below
        }
        catch (\Exception $e) {
            $source = 0xfd4b1a29;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->setCookie(new Cookie($cookie_key, $cookie_value));
        return $response;
    }


    /**
     * Typically, if a user has the permission to edit datarecords for a datatype, then they're
     * permitted to edit all datarecords they can view.  However, when a user has a datarecord
     * restriction, then usually there's a difference between the list of datarecords the user can
     * view and the list of datarecords they can edit.
     *
     * When a user with a datarecord restriction on a datatype does a search on that datatype, by
     * default ODR hides the datarecords they can't edit.  This controller action allows the user
     * to invert that setting, and store the preference in a browser cookie.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function toggleshoweditableAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $cookie_key = '';
        $cookie_value = '';

        try {
            // Pull the tab id from the current request
            $post = $request->request->all();
            if ( !isset($post['odr_tab_id']) || !isset($post['datatype_id']) )
                throw new ODRBadRequestException('invalid form');

            $odr_tab_id = $post['odr_tab_id'];
            $datatype_id = $post['datatype_id'];

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            $cookies = $request->cookies;

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( is_null($datatype) )
                throw new ODRNotFoundException('Datatype');


            // Load the current value of the cookie
            $cookie_key = 'datatype_'.$datatype->getId().'_editable_only';

            // Can't use boolean here it seems
            $display = 1;
            if ( $cookies->has($cookie_key) )
                $display = intval( $cookies->get($cookie_key) );

            // Invert the value stored
            if ($display === 1)
                $cookie_value = 0;
            else
                $cookie_value = 1;

            // The value is stored back in the cookie after the response is created below
        }
        catch (\Exception $e) {
            $source = 0xbf591415;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->setCookie(new Cookie($cookie_key, $cookie_value));
        return $response;
    }


    /**
     * Edit mode typically respects whether a field wants to be hidden or not, but there are times
     * when you kind of want to be able to see everything regardless.  Because this also needs to
     * affect InlineLink, it's easiest to use a cookie to store/transfer the preference.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function editshowsallAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $cookie_key = '';
        $cookie_value = '';

        try {
            // Pull the tab id from the current request
            $post = $request->request->all();
            if ( !isset($post['odr_tab_id']) || !isset($post['datatype_id']) )
                throw new ODRBadRequestException('invalid form');

            $odr_tab_id = $post['odr_tab_id'];
            $datatype_id = $post['datatype_id'];

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            $cookies = $request->cookies;

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( is_null($datatype) )
                throw new ODRNotFoundException('Datatype');


            // Load the current value of the cookie
            $cookie_key = 'datatype_'.$datatype->getId().'_edit_shows_all';

            // Can't use boolean here it seems
            $display = 0;
            if ( $cookies->has($cookie_key) )
                $display = intval( $cookies->get($cookie_key) );

            // Invert the value stored
            if ($display === 1)
                $cookie_value = 0;
            else
                $cookie_value = 1;

            // The value is stored back in the cookie after the response is created below
        }
        catch (\Exception $e) {
            $source = 0xe4f8d574;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->setCookie(new Cookie($cookie_key, $cookie_value));
        return $response;
    }


    /**
     * CSVExport mode typically respects whether a field wants to be hidden or not, but there are
     * times when you kind of want to be able to see everything regardless.  Might as well make it
     * work like the similar Edit mode toggle.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function csvexportshowsallAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $cookie_key = '';
        $cookie_value = '';

        try {
            // Pull the tab id from the current request
            $post = $request->request->all();
            if ( !isset($post['odr_tab_id']) || !isset($post['datatype_id']) )
                throw new ODRBadRequestException('invalid form');

            $odr_tab_id = $post['odr_tab_id'];
            $datatype_id = $post['datatype_id'];

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            $cookies = $request->cookies;

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( is_null($datatype) )
                throw new ODRNotFoundException('Datatype');


            // Load the current value of the cookie
            $cookie_key = 'datatype_'.$datatype->getId().'_csvexport_shows_all';

            // Can't use boolean here it seems
            $display = 0;
            if ( $cookies->has($cookie_key) )
                $display = intval( $cookies->get($cookie_key) );

            // Invert the value stored
            if ($display === 1)
                $cookie_value = 0;
            else
                $cookie_value = 1;

            // The value is stored back in the cookie after the response is created below
        }
        catch (\Exception $e) {
            $source = 0x07217063;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->setCookie(new Cookie($cookie_key, $cookie_value));
        return $response;
    }


    /**
     * Allows a user to set their session or default theme.
     *
     * NOTE: the corresponding unset is in {@link ThemeController::unsetpersonaldefaultthemeAction()}
     *
     * @param integer $datatype_id
     * @param string $page_type {@link ThemeInfoService::PAGE_TYPES}
     * @param integer $theme_id
     * @param integer $persist If 1, then save this choice to the database
     * @param Request $request
     *
     * @return Response
     */
    public function applythemeAction($datatype_id, $page_type, $theme_id, $persist, Request $request)
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
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            if ($theme->getDataType()->getId() !== $datatype->getId())
                throw new ODRBadRequestException('Theme does not match Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $is_super_admin = false;
            if ( $user !== 'anon.' && $user->hasRole('ROLE_SUPER_ADMIN') )
                $is_super_admin = true;

            // If the user can't view the datatype, then they shouldn't be setting themes for it
            if ( !$permissions_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();

            if ($user === 'anon.') {
                // If the user isn't logged in, then they can only use shared themes
                if ( !$theme->isShared() )
                    throw new ODRForbiddenException();

                // Otherwise, set it as the session theme
                $theme_info_service->setSessionThemeId($datatype->getId(), $page_type, $theme->getId());

                // Silently ignore attempts to save this preference to the database
            }
            else {
                // Super-admins can use any layout...
                if ( !$is_super_admin ) {
                    // ...otherwise the layout has to be public, or the user has to have created the
                    //  layout to use it
                    if ( !$theme->isShared() && $theme->getCreatedBy()->getId() !== $user->getId() )
                        throw new ODRForbiddenException();
                }

                // Otherwise, set it as the session theme
                $theme_info_service->setSessionThemeId($datatype->getId(), $page_type, $theme->getId());

                // If the user indicated they wanted to save this as their default, do so
                if ($persist == 1)
                    $theme_info_service->setUserThemePreference($user, $theme, $page_type);
            }

        }
        catch (\Exception $e) {
            $source = 0x1aeac909;
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
     * Allows a user to set their session or default sidebar layout.
     *
     * NOTE: the corresponding unset is in SearchSidebarController::unsetpersonaldefaultlayoutAction()
     *
     * @param integer $datatype_id
     * @param string $intent {@link SearchSidebarService::PAGE_INTENT}
     * @param integer $sidebar_layout_id
     * @param integer $persist If 1, then save this choice to the database
     * @param Request $request
     *
     * @return Response
     */
    public function applysidebarlayoutAction($datatype_id, $intent, $sidebar_layout_id, $persist, Request $request)
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
            /** @var SearchSidebarService $search_sidebar_service */
            $search_sidebar_service = $this->container->get('odr.search_sidebar_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // Unlike themes, the "master" sidebar layout does not have an id to use
            /** @var SidebarLayout|null $sidebar_layout */
            $sidebar_layout = null;
            if ( $sidebar_layout_id != 0 ) {
                $sidebar_layout = $em->getRepository('ODRAdminBundle:SidebarLayout')->find($sidebar_layout_id);
                if ($sidebar_layout == null)
                    throw new ODRNotFoundException('Sidebar Layout');

                if ($sidebar_layout->getDataType()->getId() !== $datatype->getId())
                    throw new ODRBadRequestException('Sidebar Layout does not match Datatype');
            }


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $is_super_admin = false;
            if ( $user !== 'anon.' && $user->hasRole('ROLE_SUPER_ADMIN') )
                $is_super_admin = true;

            // If the user can't view the datatype, then they shouldn't be setting layouts for it
            if ( !$permissions_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();

            if ( is_null($sidebar_layout) ) {
                // If a sidebar layout wasn't specified, then just set the user's session to use
                //  the "master" sidebar layout
                $search_sidebar_service->setSessionSidebarLayoutId($datatype->getId(), $intent, 0);    // passing zero to use the datatype's "master" sidebar layout

                // If a logged-in user requested this to be their default sidebar layout, then
                //  unset any existing preference they have
                if ( $user !== 'anon.' && $persist == 1 )
                    $search_sidebar_service->resetUserSidebarLayoutPreference($datatype->getId(), $user, $intent);
            }
            else if ($user === 'anon.') {
                // The sidebar layout must be public for an anonymous user to be able to use it
                if ( !$sidebar_layout->isShared() )
                    throw new ODRForbiddenException();

                $search_sidebar_service->setSessionSidebarLayoutId($datatype->getId(), $intent, $sidebar_layout->getId());

                // Silently ignore attempts to save this preference to the database
            }
            else {
                // Super-admins can use any sidebar layout...
                if ( !$is_super_admin ) {
                    // ...otherwise the sidebar layout has to be public, or the user has to have
                    //  created the layout to use it
                    if ( !$sidebar_layout->isShared() && $sidebar_layout->getCreatedBy()->getId() !== $user->getId() )
                        throw new ODRForbiddenException();
                }

                $search_sidebar_service->setSessionSidebarLayoutId($datatype->getId(), $intent, $sidebar_layout->getId());

                // Save this sidebar layout as their default if they wanted
                if ($persist == 1)
                    $search_sidebar_service->setUserSidebarLayoutPreference($user, $sidebar_layout, $intent);
            }

        }
        catch (\Exception $e) {
            $source = 0xda22f5ec;
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
