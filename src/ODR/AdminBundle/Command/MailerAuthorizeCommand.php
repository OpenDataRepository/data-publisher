<?php

/**
 * Open Data Repository Data Publisher
 * MailerAuthorize Command
 * (C) 2026 by Nathan Stone (nate.stone@opendatarepository.org)
 * Released under the GPLv2
 *
 * Obtains the refresh token that GmailApiTransport uses for the OAuth flow.
 *
 * This is the alternative to a service-account key, and the only option when the organization
 * enforces iam.disableServiceAccountKeyCreation.  Run it once per sending mailbox:
 *
 *   1.  php app/console odr:mailer:authorize --client-id=... --client-secret=...
 *       ...prints a url.  Open it in a browser, signed in AS THE SENDING MAILBOX, and approve.
 *
 *   2.  The browser is redirected to http://localhost:8765/?code=...  That page will fail to
 *       load -- nothing is listening, and nothing needs to be.  Copy the "code" value out of the
 *       address bar.
 *
 *   3.  php app/console odr:mailer:authorize --client-id=... --client-secret=... --code=PASTED
 *       ...prints the refresh token to put in parameters.yml.
 *
 * NOTE: Google retired the old "out of band" redirect, so a loopback address is used instead.
 * Whether that needs registering depends on the OAuth client's type:
 *
 *   Desktop app       -- loopback is accepted automatically, nothing to register.
 *   Web application   -- EVERY redirect uri must be listed on the client in the Cloud Console.
 *                        An unregistered one is refused before the consent screen even appears:
 *                        "You can't sign in to this app because it doesn't comply with Google's
 *                        OAuth 2.0 policy."  Either add http://localhost:8765/ to the client's
 *                        authorized redirect URIs, or pass --redirect-uri with one it already
 *                        has.  The same value must be used in both steps.
 */

namespace ODR\AdminBundle\Command;

use ODR\AdminBundle\Command\ContainerAwareCommand;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


class MailerAuthorizeCommand extends ContainerAwareCommand
{

    const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const SCOPE = 'https://www.googleapis.com/auth/gmail.send';
    /**
     * Default redirect, used when --redirect-uri isn't given.
     *
     * NOTE: a "Desktop app" OAuth client accepts loopback addresses without registering them.  A
     * "Web application" client does NOT -- every redirect uri has to be listed on the client in
     * the Cloud Console, or Google refuses the request before the consent screen with
     * "doesn't comply with Google's OAuth 2.0 policy".  Hence --redirect-uri, so an already
     * registered address can be used instead.
     */
    const REDIRECT_URI = 'http://localhost:8765/';


    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr:mailer:authorize')
            ->setDescription('Gets a Gmail refresh token for the OAuth mail transport')
            ->addOption('client-id', null, InputOption::VALUE_REQUIRED, 'OAuth client id; defaults to the gmail_api_client_id parameter')
            ->addOption('client-secret', null, InputOption::VALUE_REQUIRED, 'OAuth client secret; defaults to the gmail_api_client_secret parameter')
            ->addOption('code', null, InputOption::VALUE_REQUIRED, 'The code from the redirect url, to exchange for a refresh token')
            ->addOption('redirect-uri', null, InputOption::VALUE_REQUIRED,
                'A redirect uri registered on the OAuth client.  Must be identical in both steps.  Default: '.self::REDIRECT_URI);
    }


    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $container = $this->getContainer();

        $client_id = $input->getOption('client-id');
        if (!$client_id && $container->hasParameter('gmail_api_client_id'))
            $client_id = (string)$container->getParameter('gmail_api_client_id');

        $client_secret = $input->getOption('client-secret');
        if (!$client_secret && $container->hasParameter('gmail_api_client_secret'))
            $client_secret = (string)$container->getParameter('gmail_api_client_secret');

        if (!$client_id || !$client_secret) {
            $output->writeln('<error>Need an OAuth client id and secret.</error>');
            $output->writeln('Pass --client-id and --client-secret, or set gmail_api_client_id and');
            $output->writeln('gmail_api_client_secret in this instance\'s parameters.yml first.');
            return 1;
        }

        $redirect_uri = $input->getOption('redirect-uri');
        if (!$redirect_uri)
            $redirect_uri = self::REDIRECT_URI;

        $code = $input->getOption('code');

        // ---- step one: print the consent url ------------------------------------------
        if (!$code) {
            $url = self::AUTH_URL.'?'.http_build_query(array(
                'client_id' => $client_id,
                'redirect_uri' => $redirect_uri,
                'response_type' => 'code',
                'scope' => self::SCOPE,
                // offline + consent is what makes Google return a REFRESH token rather than just
                // an access token.  Without prompt=consent it is only issued the first time a
                // given client/account pair is approved, which makes re-running this confusing.
                'access_type' => 'offline',
                'prompt' => 'consent',
            ));

            $output->writeln('Open this in a browser, signed in as the mailbox you want to send from:');
            $output->writeln('');
            $output->writeln('<info>'.$url.'</info>');
            $output->writeln('');
            $output->writeln('After approving, the browser goes to '.$redirect_uri.'?code=...');
            $output->writeln('It does not matter whether that page loads -- the code is in the address bar.');
            $output->writeln('Copy the <comment>code</comment> value out of it, then run:');
            $output->writeln('');
            $output->writeln('  php app/console odr:mailer:authorize --code=THE_CODE'
                .($redirect_uri === self::REDIRECT_URI ? '' : ' --redirect-uri='.$redirect_uri));
            $output->writeln('');
            $output->writeln('The code is single-use and expires within a few minutes.');

            return 0;
        }

        // ---- step two: exchange it -----------------------------------------------------
        $output->writeln('Exchanging the code ...');

        $ch = curl_init(self::TOKEN_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array(
            'code' => $code,
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'redirect_uri' => $redirect_uri,
            'grant_type' => 'authorization_code',
        )));
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            $output->writeln('<error>Could not reach Google: '.$error.'</error>');
            return 1;
        }

        $decoded = json_decode($body, true);

        if ($status !== 200 || !isset($decoded['refresh_token'])) {
            $reason = is_array($decoded) && isset($decoded['error']) ? $decoded['error'] : 'unexpected response';
            $output->writeln('<error>Exchange failed (HTTP '.$status.'): '.$reason.'</error>');

            if ($reason === 'invalid_grant')
                $output->writeln('The code was already used, has expired, or was issued for a different redirect uri.  Start again without --code.');
            else if ($reason === 'redirect_uri_mismatch')
                $output->writeln('The redirect uri must be identical in both steps, and registered on the client.  Used here: <comment>'.$redirect_uri.'</comment>');
            else if (is_array($decoded) && isset($decoded['access_token']))
                $output->writeln('Google returned an access token but no refresh token.  Re-run step one -- the url includes prompt=consent, which forces one to be issued.');

            return 1;
        }

        $output->writeln('');
        $output->writeln('<info>Got it.</info>  Add these to this instance\'s app/config/parameters.yml,');
        $output->writeln('inside the parameters: mapping, indented to match its other keys:');
        $output->writeln('');
        $output->writeln("    mailer_dsn: 'gmail+api://default'");
        $output->writeln("    gmail_api_client_id: '".$client_id."'");
        $output->writeln("    gmail_api_client_secret: '".$client_secret."'");
        $output->writeln("    gmail_api_refresh_token: '".$decoded['refresh_token']."'");
        $output->writeln("    gmail_api_user: 'the.mailbox@you.just.authorized'");
        $output->writeln('');
        $output->writeln('Then clear this instance\'s container cache and run:');
        $output->writeln('  php app/console odr:mailer:test you@example.org');

        return 0;
    }
}
