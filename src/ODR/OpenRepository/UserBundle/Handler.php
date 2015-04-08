<?php 

namespace ODR\OpenRepository\UserBundle\Handler;

// "use" statements here

class AuthenticationHandler
implements AuthenticationSuccessHandlerInterface,
           AuthenticationFailureHandlerInterface
{
    public function onAuthenticationSuccess(Request $request, TokenInterface $token)
    {
        if ($request->isXmlHttpRequest()) {
            $result = array('success' => true);
            return new Response(json_encode($result));
        } else {
            // Handle non XmlHttp request here
        }
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception)
    {
        if ($request->isXmlHttpRequest()) {
            $result = array('success' => false);
            return new Response(json_encode($result));
        } else {
            // Handle non XmlHttp request here
        }
    }
}


/* 

Register the handler as a service:

services:
    authentication_handler:
        class: YourVendor\UserBundle\Handler\AuthenticationHandler
Register the service in the firewall:

firewalls:
    main:
        form_login:
            success_handler: authentication_handler
            failure_handler: authentication_handler
This is a rough example to give you the general idea — you'll need to figure out the details by yourself. If you're stuck and need further clarifications, put your questions in the comments and I'll try to elaborate the example.

*/
