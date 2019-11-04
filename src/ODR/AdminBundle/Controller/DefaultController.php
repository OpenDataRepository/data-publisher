<?php

/**
 * Open Data Repository Data Publisher
 * Default Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The Default controller handles the loading of the base template
 * and AJAX handlers that the rest of the site uses.  It also
 * handles the creation of the information displayed on the site's
 * dashboard.
 *
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
// Services
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class DefaultController extends ODRCustomController
{

    /**
     * Triggers the loading of base.html.twig, and sets up session cookies.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function indexAction(Request $request)
    {
        try {
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            // Grab the current user
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($user);


            // Render the base html for the page...$this->render() apparently creates and automatically returns a full Reponse object
            $html = $this->renderView(
                'ODRAdminBundle:Default:index.html.twig',
                array(
                    'user' => $user,
                    'user_permissions' => $datatype_permissions,
                )
            );

            $response = new Response($html);
            $response->headers->set('Content-Type', 'text/html');
            return $response;
        }
        catch (\Exception $e) {
            $source = 0xe75008d8;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }
}
