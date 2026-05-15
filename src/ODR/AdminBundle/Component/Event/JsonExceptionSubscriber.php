<?php

namespace ODR\AdminBundle\Component\Event;
use Synfony\Component\CustomException\ODRJsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;

class JsonExceptionSubscriber implements EventSubscriberInterface
{


    /**
     * @var Logger
     */
    private $logger;

    /**
     * ODREventSubscriber constructor.
     *
     * @param string $environment
     * @param ContainerInterface $container
     * @param UserManager $user_manager
     * @param Logger $logger
     */
    public function __construct(
        string $environment,
        Logger $logger
    ) {
        $this->env = $environment;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents()
    {
        return array(
            KernelEvents::EXCEPTION => 'onKernelException'
        );
    }

    public function onKernelException(GetResponseForExceptionEvent $event)
    {

        $e = $event->getException();
        if (!$e instanceof \ODR\AdminBundle\Component\CustomException\ODRJsonException) {
            return;
        }

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
