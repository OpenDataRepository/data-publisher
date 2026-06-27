<?php
/**
 * Created by PhpStorm.
 * User: nate
 * Date: 10/18/16
 * Time: 3:21 PM
 */

namespace ODR\AdminBundle\Component\CustomException;

class ODRJsonException extends \Exception implements ODRJsonExceptionInterface
{
    /**
     * Returns the exception's HTTP status code (ported from develop 2be0b76f).
     *
     * @return int
     */
    public function getStatusCode()
    {
        return $this->code;
    }
}
