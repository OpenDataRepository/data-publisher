<?php

/**
 * Open Data Repository Data Publisher
 * Username Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service exists to provide a given user's associated jupyterhub username to any part of ODR.
 */

namespace ODR\OpenRepository\JupyterhubBridgeBundle\Component\Service;

use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;


class UsernameService
{

    /**
     * Returns the given user's Jupyterhub username.
     *
     * @param ODRUser $user
     *
     * @return string
     */
    public function getJupyterhubUsername($user)
    {
        // TODO - something more sophisticated?
        return 'jupyter_user_'.$user->getId();
    }
}
