<?php

/**
 * Open Data Repository Data Publisher
 * ODRSystem Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This controller provides system-level utilities and diagnostics
 * that are restricted to super-admin users.
 *
 */

namespace ODR\AdminBundle\Controller;

// Entities
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRException;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class ODRSystemController extends ODRCustomController
{

    /**
     * Renders the OPcache GUI for monitoring and managing PHP OPcache.
     * Only accessible to users with ROLE_SUPER_ADMIN.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function opcacheAction(Request $request)
    {
        try {
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Only super admins can access this page
            if ($user === 'anon.' || !$user->hasRole('ROLE_SUPER_ADMIN')) {
                throw new ODRForbiddenException();
            }

            // Path to the opcache-gui index.php
            $opcacheGuiPath = $this->container->getParameter('kernel.root_dir')
                . '/../vendor/amnuts/opcache-gui/index.php';

            if (!file_exists($opcacheGuiPath)) {
                throw new ODRException('OPcache GUI not found. Please install amnuts/opcache-gui via composer.');
            }

            // Capture the output from opcache-gui
            ob_start();
            include $opcacheGuiPath;
            $content = ob_get_clean();

            return new Response($content);
        }
        catch (\Exception $e) {
            $source = 0x7a8f3c2d;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }
}
