<?php

namespace ODR\AdminBundle\Component\Event;

use ODR\AdminBundle\Component\CustomException\ODRJsonException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Psr\Log\LoggerInterface;

/**
 * Returns a clean JSON error response whenever an {@link ODRJsonException} bubbles up to the kernel.
 *
 * Ported from develop 2be0b76f, replacing the old JsonExceptionListener. Converted to Symfony 7:
 * ExceptionEvent (was GetResponseForExceptionEvent), getThrowable() (was getException()), and a PSR
 * LoggerInterface (was the Monolog bridge Logger).
 */
class JsonExceptionSubscriber implements EventSubscriberInterface
{
    /**
     * @var string
     */
    private $env;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param string $environment
     * @param LoggerInterface $logger
     */
    public function __construct(
        string $environment,
        LoggerInterface $logger
    ) {
        $this->env = $environment;
        $this->logger = $logger;
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            KernelEvents::EXCEPTION => 'onKernelException'
        );
    }

    /**
     * @param ExceptionEvent $event
     */
    public function onKernelException(ExceptionEvent $event)
    {
        $e = $event->getThrowable();
        if ( !$e instanceof ODRJsonException )
            return;

        $data = array(
            'error' => array(
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            )
        );
        $event->setResponse(new JsonResponse($data));
    }
}
