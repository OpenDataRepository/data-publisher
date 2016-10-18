<?php

namespace ODR\AdminBundle\Listener;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;

class JsonExceptionListener
{
    public function onKernelException(GetResponseForExceptionEvent $event)
    {
        $exception = $event->getException();
        $data = array(
            'error' => array(
                'code' => $exception->getCode(),
                'message' => $exception->getMessage()
            )
        );
        $response = new JsonResponse($data);
        $event->setResponse($response);
    }
}