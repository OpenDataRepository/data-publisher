<?php

/**
 * Open Data Repository Data Publisher
 * MailerTest Command
 * (C) 2026 by Nathan Stone (nate.stone@opendatarepository.org)
 * Released under the GPLv2
 *
 * Sends one message through whatever transport this instance is configured to use, and says
 * plainly what happened.
 *
 * The password-reset flow deliberately shows the same page whether or not an account exists, so
 * it can't tell you whether mail is actually going out.  This can.
 *
 * Usage:
 *   php app/console odr:mailer:test someone@example.org
 *   php app/console odr:mailer:test someone@example.org --show-config
 */

namespace ODR\AdminBundle\Command;

use ODR\AdminBundle\Command\ContainerAwareCommand;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Symfony\Component\Mime\Email;


class MailerTestCommand extends ContainerAwareCommand
{

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr:mailer:test')
            ->setDescription("Sends a test email through this instance's configured transport")
            ->addArgument('to', InputArgument::REQUIRED, 'Where to send it')
            ->addOption('show-config', null, InputOption::VALUE_NONE,
                'Report the transport and sender settings without sending anything');
    }


    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $container = $this->getContainer();

        // NOTE: the parameter_bag SERVICE is private, so go through the container's own
        //  parameter accessors instead
        $bag = new class ($container) {
            public function __construct(private $c) {}
            public function has(string $k): bool { return $this->c->hasParameter($k); }
            public function get(string $k) { return $this->c->getParameter($k); }
            // a parameter that exists but is null counts as unset -- parameters.yml.dist ships
            // mailer_user as null, which would otherwise print as an empty value
            public function value(string $k): string {
                if (!$this->c->hasParameter($k)) return '';
                $v = $this->c->getParameter($k);
                return ($v === null || !is_scalar($v)) ? '' : (string)$v;
            }
        };

        $dsn = $bag->has('mailer_dsn') ? (string)$bag->get('mailer_dsn') : '(not set)';
        $scheme = parse_url($dsn, PHP_URL_SCHEME);

        $output->writeln('Transport');
        $output->writeln('  mailer_dsn: <info>'.self::redactDsn($dsn).'</info>');

        if ($scheme === 'null') {
            $output->writeln('');
            $output->writeln('<comment>This instance discards all mail (null://null).  Nothing will be delivered,');
            $output->writeln('and the password-reset pages will still look like they worked.</comment>');
        }

        if ($scheme === 'gmail+api') {
            // which credential set is in play decides what to report, and what can go wrong
            $oauth = $bag->value('gmail_api_client_id') !== '' && $bag->value('gmail_api_refresh_token') !== '';
            $output->writeln('  mode: <info>'.($oauth ? 'OAuth client + refresh token' : 'service account + domain-wide delegation').'</info>');

            if ($oauth) {
                $output->writeln('  gmail_api_client_id: <info>'.$bag->value('gmail_api_client_id').'</info>');
                foreach (array('gmail_api_client_secret', 'gmail_api_refresh_token') as $key)
                    $output->writeln('  '.$key.': <info>'.($bag->value($key) !== '' ? 'set' : '(not set)').'</info>');
                $value = $bag->value('gmail_api_user');
                $output->writeln('  gmail_api_user: <info>'.($value !== '' ? $value : '(not set)').'</info>');
            }
            else {
                foreach (array('gmail_api_service_account', 'gmail_api_user') as $key) {
                    $value = $bag->value($key);
                    $output->writeln('  '.$key.': <info>'.($value !== '' ? $value : '(not set)').'</info>');
                }
                $output->writeln('  gmail_api_private_key: <info>'
                    .($bag->value('gmail_api_private_key') !== '' ? 'set' : '(not set)').'</info>');
            }
        }

        $from = $bag->value('mailer_from_email');
        if ($from === '')
            $from = $bag->value('mailer_user');       // pre-rename instances
        if ($from === '')
            $from = $bag->value('gmail_api_user');
        $from = ($from === '') ? null : $from;
        $output->writeln('  from: <info>'.($from !== null ? $from : '(falls back to noreply@<site host>)').'</info>');

        if ($input->getOption('show-config'))
            return 0;

        // ----------------------------------------
        $to = $input->getArgument('to');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $output->writeln('');
            $output->writeln('<error>"'.$to.'" is not a valid email address.</error>');
            return 1;
        }

        if ($from === null) {
            $site = $bag->value('site_baseurl');
            $host = parse_url($site, PHP_URL_HOST);
            $from = 'noreply@'.($host !== null && $host !== '' ? $host : 'localhost');
        }

        $message = (new Email())
            ->subject('ODR mailer test')
            ->from($from)
            ->to($to)
            ->text(
                "This is a test message from ODR's odr:mailer:test command.\n\n"
                ."If you received it, this instance can send mail.\n"
                ."Sent at ".date('c')."\n"
            );

        $output->writeln('');
        $output->writeln('Sending to <info>'.$to.'</info> ...');

        try {
            // 'mailer' itself is private; odr.mailer is a public alias for it
            $container->get('odr.mailer')->send($message);
        } catch (\Throwable $e) {
            $output->writeln('');
            $output->writeln('<error>Failed: '.$e->getMessage().'</error>');

            // the transport's own message already names the likely cause for the common google
            // failures, so don't bury it under a stack trace
            return 1;
        }

        $output->writeln('<info>Sent.</info>  If it doesn\'t arrive, check the recipient\'s spam folder');
        $output->writeln('before assuming the transport is at fault.');

        return 0;
    }


    /**
     * Hides any password in a DSN before printing it.
     *
     * @param string $dsn
     *
     * @return string
     */
    private static function redactDsn($dsn)
    {
        return preg_replace('#://([^:@/]+):([^@]+)@#', '://$1:********@', $dsn);
    }
}
