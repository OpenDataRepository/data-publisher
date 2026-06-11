<?php
/**
 * Created by PhpStorm.
 * User: nate
 * Date: 10/18/16
 * Time: 3:21 PM
 */

namespace ODR\AdminBundle\Component\CustomException;
use Symfony\Component\HttpFoundation\JsonResponse;

class ODRJsonException extends \Exception implements ODRJsonExceptionInterface
{

    public function getStatusCode() {
        return $this->code;
    }

}
