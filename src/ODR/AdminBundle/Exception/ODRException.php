<?php

/**
 * Open Data Repository Data Publisher
 * ODRException
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This class grants ODR's controllers the ability to easily throw fatal runtime exceptions,
 * and have Symfony + ODRExceptionController generate an appropriate Response.
 */

namespace ODR\AdminBundle\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;


class ODRException extends HttpException
//class ODRException extends \Exception
{

    /**
     * @param string $message
     * @param string|null $statusCode
     * @param integer|null $exception_source
     * @param \Exception|null $previous_exception
     */
    public function __construct($message, $statusCode = null, $exception_source = null, \Exception $previous_exception = null)
    {
        if ( is_null($statusCode) )
            $statusCode = 500;

        parent::__construct($statusCode, $message, $previous_exception, array(), $exception_source);
    }
}
