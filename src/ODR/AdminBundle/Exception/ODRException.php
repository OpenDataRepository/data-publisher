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
{

//    protected $message;
    protected $source_code;

    /**
     * ODRException constructor.
     *
     * @param string $message
     * @param string|null $statusCode
     * @param integer|null $source_code
     * @param \Exception|null $previous_exception
     */
    public function __construct(
        $message,
        $statusCode = null,
        $source_code = 0,
        \Exception $previous_exception = null
    ) {

//        $this->message = $message;
        $this->source_code = $source_code;

        if ( is_null($statusCode) )
            $statusCode = 500;

        parent::__construct($statusCode, $message, $previous_exception, array(), $source_code);
    }


    /**
     * TODO -
     *
     * @param null $code
     * @return int|null
     */
    public function getSourceCode($code = null) {
        if ($this->source_code == 0 && $code != null)
            return $code;

        return $this->source_code;
    }
}
