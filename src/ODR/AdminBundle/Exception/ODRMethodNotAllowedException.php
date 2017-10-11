<?php

/**
 * Open Data Repository Data Publisher
 * ODRMethodNotAllowed Exception
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Wrapper class to get Symfony to return a 405 error.
 */

namespace ODR\AdminBundle\Exception;


class ODRMethodNotAllowedException extends ODRException
{

    /**
     * @param string $message
     */
    public function __construct($message, $source = 0)
    {
        parent::__construct($message, self::getStatusCode(), $source);
    }


    /**
     * @inheritdoc
     */
    public function getStatusCode()
    {
        return 405;
    }
}
