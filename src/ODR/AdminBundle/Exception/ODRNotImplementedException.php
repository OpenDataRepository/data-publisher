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
     * ODRNotImplementedException constructor.
     *
     * @param string $message
     * @param int $source
     */
    public function __construct($message = '', $source = 0)
    {
        if ($message == '')
            $message = 'Not Implemented';

        parent::__construct($message, self::getStatusCode(), $source);
    }


    /**
     * @inheritdoc
     */
    public function getStatusCode()
    {
        return 501;
    }
}
