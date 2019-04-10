<?php

/**
 * Open Data Repository Data Publisher
 * Search Redirect Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Contains functions to cooperate with common.js's LoadContentFullAjax() to reload pages with
 * hash fragments.  Required because symfony's built-in route redirection functions don't change
 * these hash fragments.
 */

namespace ODR\OpenRepository\SearchBundle\Component\Service;

// Entities
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Services
use ODR\AdminBundle\Component\Service\ODRTabHelperService;
// Other
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Router;


class SearchRedirectService
{

    /**
     * @var ODRTabHelperService
     */
    private $tab_helper_service;

    /**
     * @var Router
     */
    private $router;

    /**
     * @var EngineInterface
     */
    private $templating;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * SearchRedirectService constructor.
     *
     * @param ODRTabHelperService $tabHelperService
     * @param Router $router
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(
        ODRTabHelperService $tab_helper_service,
        Router $router,
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->tab_helper_service = $tab_helper_service;
        $this->router = $router;
        $this->templating = $templating;
        $this->logger = $logger;
    }


    /**
     * Silently redirects the user to a different search results page...this doesn't notify them
     * that something is happening, so it's only really useful for redirects to empty datarecord
     * lists or changes to the search_theme_id
     *
     * @param string $search_key
     * @param int $search_theme_id
     *
     * @return Response
     */
    public function redirectToSearchResult($search_key, $search_theme_id)
    {
        // Can't use $this->redirect, because it won't update the hash...
        $return = array(
            'r' => 2,    // so common.js::LoadContentFullAjax() updates page instead of reloading
            't' => '',
            'd' => array(
                'url' => $this->router->generate(
                    'odr_search_render',
                    array(
                        'search_theme_id' => $search_theme_id,
                        'search_key' => $search_key,
                    )
                )
            )
        );

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Sends the user to a page which notifies them that they're being redirected to a different
     * set of search results...one that they can actually view.
     *
     * @param ODRUser $user
     * @param string $search_key
     * @param int $search_theme_id
     *
     * @return Response
     */
    public function redirectToFilteredSearchResult($user, $search_key, $search_theme_id)
    {
        // Generate the new URL that the user will be redirected to after the javascript executes
        $new_url = $this->router->generate(
            'odr_search_render',
            array(
                'search_theme_id' => $search_theme_id,
                'search_key' => $search_key,
            )
        );

        // If the user isn't logged in, display a "login" button...
        $logged_in = true;
        if ($user === 'anon.')
            $logged_in = false;

        $return = array(
            'r' => 0,
            't' => '',
            'd' => array(
                'html' => $this->templating->render(
                    'ODROpenRepositorySearchBundle:Default:searchpage_redirect.html.twig',
                    array(
                        'logged_in' => $logged_in,
                        'url' => $new_url,
                    )
                )
            )
        );

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * When the search result only contains a single datarecord, the user should be redirected to
     * the view page for that single datarecord.
     *
     * @param $datarecord_id
     *
     * @return Response
     */
    public function redirectToSingleDatarecord($datarecord_id)
    {
        // Can't use $this->redirect, because it won't update the hash...
        $return = array(
            'r' => 2,    // so common.js::LoadContentFullAjax() updates page instead of reloading
            't' => '',
            'd' => array(
                'url' => $this->router->generate(
                    'odr_display_view',
                    array(
                        'datarecord_id' => $datarecord_id
                    )
                )
            )
        );

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * When the user can't view the search key on a Display page, then the user needs to be
     * redirected to a Display page with a proper search key.
     *
     * @param int $datarecord_id
     * @param int $search_theme_id
     * @param string $filtered_search_key
     * @param int $offset
     *
     * @return Response
     */
    public function redirectToViewPage($datarecord_id, $search_theme_id, $filtered_search_key, $offset)
    {
        // Can't use $this->redirect, because it won't update the hash...
        $return = array(
            'r' => 2,    // so common.js::LoadContentFullAjax() updates page instead of reloading
            't' => '',
            'd' => array(
                'url' => $this->router->generate(
                    'odr_display_view',
                    array(
                        'datarecord_id' => $datarecord_id,
                        'search_theme_id' => $search_theme_id,
                        'search_key' => $filtered_search_key,
                        'offset' => $offset,
                    )
                )
            )
        );

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * When the user can't view the search key on an Edit page, then the user needs to be
     * redirected to a Display page with a proper search key.
     *
     * @param int $datarecord_id
     * @param int $search_theme_id
     * @param string $filtered_search_key
     * @param int $offset
     *
     * @return Response
     */
    public function redirectToEditPage($datarecord_id, $search_theme_id, $filtered_search_key, $offset)
    {
        // Can't use $this->redirect, because it won't update the hash...
        $return = array(
            'r' => 2,    // so common.js::LoadContentFullAjax() updates page instead of reloading
            't' => '',
            'd' => array(
                'url' => $this->router->generate(
                    'odr_record_edit',
                    array(
                        'datarecord_id' => $datarecord_id,
                        'search_theme_id' => $search_theme_id,
                        'search_key' => $filtered_search_key,
                        'offset' => $offset,
                    )
                )
            )
        );

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
