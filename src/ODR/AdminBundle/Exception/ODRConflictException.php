<?php

/**
 * Open Data Repository Data Publisher
 * ODRBadRequest Exception
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Wrapper class to get Symfony to return a 400 error.
 */

namespace ODR\AdminBundle\Exception;


class ODRConflictException extends ODRException
{

    /**
     * ODRBadRequestException constructor.
     *
     * @param string $message
     * @param int $source
     */
    public function __construct($message = '', $source = 0)
    {
        if ($message == '')
            $message = 'Conflict';

        parent::__construct($message, self::getStatusCode(), $source);
    }


    /**
     * @inheritdoc
     */
    public function getStatusCode()
    {
        return 409;
    }
}
