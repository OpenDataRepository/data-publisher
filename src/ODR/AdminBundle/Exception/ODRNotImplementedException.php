<?php

/**
 * Open Data Repository Data Publisher
 * ODRNotImplemented Exception
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Wrapper class to get Symfony to return a 501 error.
 */

namespace ODR\AdminBundle\Exception;


class ODRNotImplementedException extends ODRException
{

    /**
     * @param string $message
     */
    public function __construct($message = '')
    {
        if ($message == '')
            $message = 'Not Implemented';

        parent::__construct($message, self::getStatusCode());
    }


    /**
     * @inheritdoc
     */
    public function getStatusCode()
    {
        return 501;
    }
}
