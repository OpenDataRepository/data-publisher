<?php

/**
 * Open Data Repository Data Publisher
 * Ajax Authentication Listener
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Listens for exceptions raised by the firewall during execution of AJAX
 * events, and re-throws one of ODR's custom exceptions to "handle" it.
 *
 */

namespace ODR\AdminBundle\Component\Event;

// Exceptions
use ODR\AdminBundle\Exception\ODRForbiddenException;
// Symfony
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;


class AjaxAuthenticationListener
{

    /**
     * Handles security related exceptions.
     *
     * @param GetResponseForExceptionEvent $event An GetResponseForExceptionEvent instance
     */
    public function onCoreException(GetResponseForExceptionEvent $event)
    {
        $exception = $event->getException();
        $request = $event->getRequest();

        if ($request->isXmlHttpRequest()) {
            if ($exception instanceof AuthenticationException || $exception instanceof AccessDeniedException) {
                // If this exception was caused as a result of an AJAX request, change it to an ODR-defined exception
                $event->setException(
                    new ODRForbiddenException($exception->getMessage(), 0x050bfe48)
                );
            }
        }

        // Otherwise, just let symfony handle all exceptions
    }
}
