<?php

/**
 * Open Data Repository Data Publisher
 * ODRException Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This controller overrides Symfony's built-in ExceptionController, because
 * the default Responses it returns on uncaught errors/exceptions don't really
 * work nicely with ODR's extensive use of AJAX.
 *
 */

namespace ODR\AdminBundle\Controller;

// Symfony
use Symfony\Bundle\TwigBundle\Controller\ExceptionController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\FlattenException;
use Symfony\Component\HttpKernel\Log\DebugLoggerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
//? Twig
use Twig\Environment;


class ODRExceptionController extends ExceptionController
{

    /**
     * @var array
     */
    protected $accepted_formats;

    /**
     * @var TokenStorage
     */
    protected $token_storage;


    /**
     * @inheritdoc
     */
    public function __construct(Environment $twig, $debug, TokenStorage $token_storage)
    {
        $this->token_storage = $token_storage;
        parent::__construct($twig, $debug);

        $this->accepted_formats = array('html', 'json', 'txt', 'xml');
    }


    /**
     * @inheritdoc
     */
    public function showAction(Request $request, FlattenException $exception, DebugLoggerInterface $logger = null)
    {
        $currentContent = $this->getAndCleanOutputBuffering($request->headers->get('X-Php-Ob-Level', -1));
        $showException = $request->attributes->get('showException', $this->debug);


        // ----------------------------------------
        if ( $request->getRequestFormat() === 'html' ) {
            // ...most likely this is the default format for the request...attempt to figure out what format the error should be returned as
            $acceptable_content_types = $request->getAcceptableContentTypes();
            foreach ($acceptable_content_types as $content_type) {
                $format = $request->getFormat($content_type);

                if ( in_array($format, $this->accepted_formats) ) {
                    $request->setRequestFormat($format);
                    break;
                }
            }
        }

        // Determine whether a user is logged in or not
        $user = 'anon.';
        $token = $this->token_storage->getToken();
        $logged_in = false;

        if ($token !== null) {
            $user = $token->getUser();    // <-- will return 'anon.' when nobody is logged in
            if ($user != 'anon.')
                $logged_in = true;
        }

        // If not logged in and encountered a 403 error, they might be able to log in to fix it
        $status_code = $exception->getStatusCode();
        if (!$logged_in && $status_code == 403)
            $status_code = 401;

        // Convert the integer "code" variable back into a hex string...right-pad to 8 digit hex
        $exception_source = sprintf('0x%08x', $exception->getCode() );


        // ----------------------------------------
        // Basically...if in prod mode, this function will prefer to return "error.<format>.twig" filenames
        // If in dev mode, it will instead prefer to return the "exception.<format>.twig" filenames

        // ODR overrides most of these twig files in the /app/Resources/TwigBundle/views/Exception directory
        // "exception.html.twig" is intentionally not overridden...that one generates the formatted stack trace accessible with the debug toolbar
        $template = (string)$this->findTemplate($request, $request->getRequestFormat(), strval($status_code), $showException);


        // ----------------------------------------
        // Attempt to render the desired template for the error
        $response = new Response(
            $this->twig->render(
                $template,
                array(
                    'status_code' => $status_code,
                    'status_text' => isset(Response::$statusTexts[$status_code]) ? Response::$statusTexts[$status_code] : '',
                    'exception_source' => $exception_source,

                    'exception' => $exception,
                    'logger' => $logger,
                    'currentContent' => $currentContent,

                    'user' => $user,
                    'logged_in' => $logged_in,
                )
            )
        );

        // Symfony doesn't always do this reliably, it seems
        $response->setStatusCode($status_code);
        $response->headers->set('Content-Type', $request->getContentType());

        return $response;
    }
}
