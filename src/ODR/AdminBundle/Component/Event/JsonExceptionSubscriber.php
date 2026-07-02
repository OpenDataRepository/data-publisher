<?php

namespace ODR\AdminBundle\Component\Event;

use ODR\AdminBundle\Component\CustomException\ODRJsonException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Psr\Log\LoggerInterface;

/**
 * Returns a clean JSON error response whenever an {@link ODRJsonException} bubbles up to the kernel,
 * or whenever ANY exception is thrown while handling an API request.
 *
 * Ported from develop 2be0b76f (initial) + ad11b59d (API-wide JSON errors, real status codes).
 * Converted to Symfony 7: ExceptionEvent (was GetResponseForExceptionEvent), getThrowable() (was
 * getException()), and a PSR LoggerInterface (was the Monolog bridge Logger).
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
            // High priority so we run before (and override) the framework's
            // HTML ExceptionListener, which would otherwise replace our JSON
            // response with an error page.
            KernelEvents::EXCEPTION => array('onKernelException', 64)
        );
    }

    /**
     * @param ExceptionEvent $event
     */
    public function onKernelException(ExceptionEvent $event)
    {
        $e = $event->getThrowable();

        // Always JSON-ify an explicit ODRJsonException. Additionally, JSON-ify
        // ANY exception thrown while handling an API request, so API consumers
        // never receive an HTML error page. This covers every API controller
        // catch block (which typically rethrow ODRException), as well as
        // uncaught exceptions and routing failures (404 "No route found",
        // 405 "Method Not Allowed", etc.).
        $is_json_exception = $e instanceof ODRJsonException;
        $is_api_request = self::isApiRequest($event);

        if (!$is_json_exception && !$is_api_request)
            return;

        // Resolve a sane HTTP status. ODRException (extends HttpException),
        // ODRJsonException, and Symfony HttpExceptions all expose
        // getStatusCode(); fall back to the exception code, then 500.
        $status = 500;
        if (method_exists($e, 'getStatusCode')) {
            $sc = (int)$e->getStatusCode();
            if ($sc >= 400 && $sc < 600)
                $status = $sc;
        }
        elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
        }
        else {
            $code = (int)$e->getCode();
            if ($code >= 400 && $code < 600)
                $status = $code;
        }

        // Log the exception ourselves — we stop propagation below, which
        // would otherwise skip the framework's exception-logging listener.
        if ($this->logger !== null) {
            $this->logger->error(
                'API exception: '.get_class($e).' ('.$status.'): '.$e->getMessage(),
                array('exception' => $e)
            );
        }

        $data = array(
            'error' => array(
                'code' => $status,
                'message' => $e->getMessage()
            )
        );

        // Note: the JsonResponse now carries the real HTTP status code (the
        // old behavior returned 200 even for errors).
        $response = new JsonResponse($data, $status);
        $event->setResponse($response);

        // Prevent the framework's HTML ExceptionListener (and any other
        // later listener) from overwriting our JSON response.
        $event->stopPropagation();
    }

    /**
     * Whether the request being handled targets an ODR API endpoint. Matches
     * "/api/v<digits>" anywhere in the path so it also works when the routes
     * are mounted under a "/odr" prefix in WordPress-integrated mode.
     *
     * @param ExceptionEvent $event
     * @return bool
     */
    private function isApiRequest(ExceptionEvent $event)
    {
        $request = $event->getRequest();
        if ($request === null)
            return false;
        return (bool)preg_match('#/api/v\d#', $request->getPathInfo());
    }
}
