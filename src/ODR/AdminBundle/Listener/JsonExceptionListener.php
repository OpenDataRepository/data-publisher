<?php

namespace ODR\AdminBundle\Listener;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;

class JsonExceptionListener
{
    public function onKernelException(\Symfony\Component\HttpKernel\Event\ExceptionEvent $event)
    {
        $exception = $event->getThrowable();
        $data = [
            'error' => [
                'code' => $exception->getCode(),
                'message' => $exception->getMessage()
            ]
        ];
        $response = new JsonResponse($data);
        $event->setResponse($response);
    }
}