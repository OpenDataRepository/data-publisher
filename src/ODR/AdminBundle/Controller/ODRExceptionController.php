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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Log\DebugLoggerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
//? Twig
use Twig\Environment;


/**
 * Wired in as framework.error_controller (see app/config/config.yml). Replaces Symfony's
 * built-in error rendering, because the default Responses it returns on uncaught
 * errors/exceptions don't really work nicely with ODR's extensive use of AJAX.
 *
 * Previously extended the deprecated TwigBundle ExceptionController; the handful of helper
 * methods it used (findTemplate/templateExists/getAndCleanOutputBuffering) are now inlined
 * so the controller no longer depends on the removed-in-5.0 twig.exception_controller path.
 */
class ODRExceptionController
{

    /**
     * @var Environment
     */
    protected $twig;

    /**
     * @var bool
     */
    protected $debug;

    /**
     * @var array
     */
    protected $accepted_formats;

    /**
     * @var TokenStorageInterface
     */
    protected $token_storage;


    /**
     * @inheritdoc
     */
    public function __construct(Environment $twig, $debug, TokenStorageInterface $token_storage)
    {
        $this->twig = $twig;
        $this->debug = $debug;
        $this->token_storage = $token_storage;

        $this->accepted_formats = ['html', 'json', 'txt', 'xml'];
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
                [
                    'status_code' => $status_code,
                    'status_text' => Response::$statusTexts[$status_code] ?? '',
                    'exception_source' => $exception_source,

                    'exception' => $exception,
                    'logger' => $logger,
                    'currentContent' => $currentContent,

                    'user' => $user,
                    'logged_in' => $logged_in,
                ]
            )
        );

        // Symfony doesn't always do this reliably, it seems
        $response->setStatusCode($status_code);

        // Set any other headers the exception specified
        $headers = $exception->getHeaders();
        foreach ($headers as $key => $value)
            $response->headers->set($key, $value);

        return $response;
    }


    /**
     * Locates the twig template to render for a given format/status code. ODR overrides most of
     * these in app/Resources/TwigBundle/views/Exception/. Inlined from the former TwigBundle
     * ExceptionController parent.
     */
    protected function findTemplate(Request $request, $format, $code, $showException)
    {
        $name = $showException ? 'exception' : 'error';
        if ($showException && 'html' == $format) {
            $name = 'exception_full';
        }

        // For error pages, try to find a template for the specific HTTP status code and format
        if (!$showException) {
            $template = sprintf('@ODRException/%s%s.%s.twig', $name, $code, $format);
            if ($this->templateExists($template)) {
                return $template;
            }
        }

        // try to find a template for the given format
        $template = sprintf('@ODRException/%s.%s.twig', $name, $format);
        if ($this->templateExists($template)) {
            return $template;
        }

        // default to a generic HTML page: try the detailed dev exception page, then the branded
        // (prod) error page. ODR no longer ships exception_full.html.twig (it used to fall back to
        // TwigBundle's, removed in SF5), so the standalone exception.html.twig is the dev default.
        $request->setRequestFormat('html');

        $candidates = array();
        if ($showException) {
            $candidates[] = '@ODRException/exception_full.html.twig';
            $candidates[] = '@ODRException/exception.html.twig';
        }
        $candidates[] = '@ODRException/error.html.twig';

        foreach ($candidates as $candidate) {
            if ($this->templateExists($candidate)) {
                return $candidate;
            }
        }

        return '@ODRException/error.html.twig';
    }


    /**
     * @param string $template
     * @return bool
     */
    protected function templateExists($template)
    {
        return $this->twig->getLoader()->exists($template);
    }


    /**
     * @param int $startObLevel
     * @return string
     */
    protected function getAndCleanOutputBuffering($startObLevel)
    {
        if (ob_get_level() <= $startObLevel) {
            return '';
        }

        Response::closeOutputBuffers($startObLevel + 1, true);

        return ob_get_clean();
    }
}
