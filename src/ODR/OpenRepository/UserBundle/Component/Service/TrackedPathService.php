<?php

/**
 * Open Data Repository Data Publisher
 * Tracked Path Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This currently exists as a service only so ODROpenRepository:SearchBundle:DefaultController
 * can more elegantly force the current user to the login page, and have it return back to the page
 * the user currently resides on.
 *
 * @see ODROpenRepository:UserBundle:UtilityController
 */

namespace ODR\OpenRepository\UserBundle\Component\Service;

// Services
use ODR\AdminBundle\Component\Service\ODRTabHelperService;
// Other
use Symfony\Component\HttpFoundation\RequestStack;


class TrackedPathService
{

    /**
     * @var RequestStack
     */
    private $requestStack;

    /**
     * @var ODRTabHelperService
     */
    private $tab_helper_service;


    /**
     * TrackedPathService constructor.
     *
     * @param RequestStack $requestStack
     * @param ODRTabHelperService $tabHelperService
     */
    public function __construct(
        RequestStack $requestStack,
        ODRTabHelperService $tabHelperService
    ) {
        $this->requestStack = $requestStack;
        $this->tab_helper_service = $tabHelperService;
    }


    /**
     * Utility function to ensure the user's session doesn't store a target path it shouldn't.
     *
     * @param boolean $clear_all_paths
     */
    public function clearTargetPaths($clear_all_paths = true)
    {
        $request = $this->requestStack->getCurrentRequest();
        $session = $request->getSession();

        // Remove the target path for linking an ODR account with an external OAuth provider
        if ( $session->has('_security.oauth_connect.redirect_path') )
            $session->remove('_security.oauth_connect.redirect_path');

        // Remove the target path for apps using ODR as an OAuth provider
        if ( $session->has('_security.oauth_authorize.target_path') )
            $session->remove('_security.oauth_authorize.target_path');

        if ($clear_all_paths) {
            // Remove the redirect path for conventional logins to ODR
            if ($session->has('_security.main.target_path')) {
                $session->remove('_security.main.target_path');

                if ($session->has('_security.url_fragment'))
                    $session->remove('_security.url_fragment');
            }
        }


        // Don't want to preserve any default session_themes picked up when browsing prior to
        //  logging into the site...
        if ( $session->has('session_themes') )
            $session->remove('session_themes');

        // Force an update to the list of datarecords for a search result if the user logs in...
        //  otherwise, the search headers in Display/Edit mode will continue to only display
        //  public datarecords...
        $this->tab_helper_service->clearDatarecordLists();
    }
}