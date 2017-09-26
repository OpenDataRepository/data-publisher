<?php

/**
 * Open Data Repository Data Publisher
 * ODRPermissionDenied Exception
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Wrapper class to get Symfony to return a 401 or 403 error...ODRExceptionController will change this to
 * a 401 error if the user isn't currently logged in.
 */

namespace ODR\AdminBundle\Exception;


class ODRForbiddenException extends ODRException
{

    /**
     * @param string|null $message
     */
    public function __construct($message = '', $source = 0)
    {
        if ($message == '')
            $message = "You don't have the permissions to do this.";

        parent::__construct($message, self::getStatusCode(), $source);
    }


    /**
     * @inheritdoc
     */
    public function getStatusCode()
    {
        return 403;
    }
}
