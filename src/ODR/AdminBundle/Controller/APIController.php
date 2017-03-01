<?php

/**
 * Open Data Repository Data Publisher
 * API Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * TODO
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Forms
// Symfony
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class APIController extends ODRCustomController
{

    /**
     * Used by JupyterHub to determine which user has logged in via ODR's OAuth
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function userdataAction(Request $request)
    {
        /** @var ODRUser $user */
        $user = $this->container->get('security.token_storage')->getToken()->getUser();

        if ($user && $user->hasRole('ROLE_JUPYTERHUB_USER')) {
            return new JsonResponse(
                array(
                    'username' => $user->getUserString(),
                    'email' => $user->getEmail(),
                    'jupyterhub_username' => 'jupyter_user_'.$user->getId(),
                )
            );
        }

        return new JsonResponse(
            array(
                'message' => 'Invalid User'
            )
        );
    }
}
