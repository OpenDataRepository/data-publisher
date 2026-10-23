<?php

/**
 * Open Data Repository Data Publisher
 * UpdateBannedBotIps Command
 * (C) 2026 by Nathan Stone (nate.stone@opendatarepository.org)
 * Released under the GPLv2
 *
 * Downloads the addresses that unwanted bots publish for themselves, and writes them into a plain
 * list for the server to ban at the firewall.  The counterpart of odr:crawler_ips:update, which
 * lists the crawlers that are welcome.
 *
 * This only writes the list.  Banning needs root, so docs/server/bot-bans/bin/odr-ban-bot-ips
 * (run from root's cron) feeds the list to fail2ban's odr-bots jail.  A bot that identifies itself
 * is also refused by user agent in docs/server/bot-bans/apache/odr-bot-bans.conf, which catches
 * it even from an address that isn't published yet.
 *
 * To ban another bot, add it to SOURCES.
 *
 * Usage:
 *   php app/console odr:banned_bot_ips:update
 *   php app/console odr:banned_bot_ips:update --dry-run
 *   php app/console odr:banned_bot_ips:update --out=/path/to/banned_bot_ips.txt
 */

namespace ODR\AdminBundle\Command;

use ODR\AdminBundle\Command\ContainerAwareCommand;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


class UpdateBannedBotIpsCommand extends ContainerAwareCommand
{

    /**
     * The bots to ban, and where each publishes its addresses.
     *
     * Each url is read for "ipv4Prefix" / "ipv6Prefix" entries -- the schema Google, Bing and
     * DuckDuckGo publish -- whether the url returns that json directly or embeds it in an html
     * page, as amazon does.
     *
     * NOTE: deliberately never "any address-looking text on the page".  Amazon's page also
     *  carries the *visitor's* address in an analytics block, so a scrape would ban whichever
     *  machine ran this update.
     *
     * NOTE: amazon publishes two lists of individual addresses that don't overlap -- the
     *  addresses rotate -- so both are read, and the list should be refreshed several times a day.
     */
    const SOURCES = array(
        'amazonbot' => array(
            'urls' => array(
                'https://developer.amazon.com/amazonbot/ip-addresses/',
                'https://developer.amazon.com/amazonbot/live-ip-addresses/',
            ),
        ),
    );

    /**
     * A source yielding fewer addresses than this is treated as broken (an error page, a redesign
     * that moved the list), and keeps its previous addresses instead.
     */
    const MINIMUM_ADDRESSES = 20;

    /**
     * Seconds to wait on each url.
     */
    const TIMEOUT = 30;


    /**
     * @inheritdoc
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr:banned_bot_ips:update')
            ->setDescription('Downloads the published addresses of banned bots (Amazonbot, ...) into a plain list for the firewall')
            ->addOption(
                'out',
                null,
                InputOption::VALUE_REQUIRED,
                'Where to write the list.  Defaults to the odr_banned_bot_ip_file parameter.'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Downloads and reports what would change, but writes nothing'
            );
    }


    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $container = $this->getContainer();
        $logger = $container->get('logger');

        $file = $input->getOption('out');
        if ( $file === null || $file === '' )
            $file = $container->getParameter('odr_banned_bot_ip_file');

        $dry_run = (bool)$input->getOption('dry-run');
        if ( !$dry_run ) {
            $problem = self::checkWritable($file);
            if ( $problem !== true ) {
                $output->writeln('<error>Cannot write '.$file.': '.$problem.'</error>');
                $output->writeln('Nothing was downloaded.');
                return 1;
            }
        }

        // What's stored now, so a source that fails to download keeps its previous addresses
        $existing = self::readExisting($file);

        /** @var \ODR\AdminBundle\Component\Service\CrawlerVerificationService $crawler_service */
        $crawler_service = $container->get('odr.crawler_verification_service');

        $addresses = array();    // address => source
        $summary = array();      // source => line for the file header
        $failures = 0;

        foreach (self::SOURCES as $name => $source) {
            $output->writeln('Fetching <info>'.$name.'</info> ...');

            $found = array();
            $errors = array();
            foreach ($source['urls'] as $url) {
                $result = self::fetch($url);
                if ( is_string($result) ) {
                    $errors[] = $url.': '.$result;
                    continue;
                }

                $parsed = self::parsePrefixes($result['body']);
                $output->writeln('  '.count($parsed).' addresses from '.$url);
                foreach ($parsed as $address)
                    $found[$address] = true;
            }

            // Never ban a welcome crawler, however the address got onto a page
            $skipped = 0;
            foreach (array_keys($found) as $address) {
                $plain = explode('/', $address)[0];
                if ( $crawler_service->identifyCrawler($plain) !== null ) {
                    unset($found[$address]);
                    $skipped++;
                }
            }
            if ( $skipped > 0 )
                $output->writeln('  <comment>left out '.$skipped.' that belong to verified search crawlers</comment>');

            if ( !empty($errors) || count($found) < self::MINIMUM_ADDRESSES ) {
                $failures++;
                foreach ($errors as $error)
                    $output->writeln('  <error>failed: '.$error.'</error>');
                if ( count($found) < self::MINIMUM_ADDRESSES )
                    $output->writeln('  <error>only '.count($found).' addresses, expected at least '.self::MINIMUM_ADDRESSES.'</error>');
                $logger->error('UpdateBannedBotIpsCommand.php: could not update "'.$name.'": '.implode('; ', $errors));

                // Partial results aren't trusted either...keep what was stored, if anything
                $previous = isset($existing[$name]) ? $existing[$name] : array();
                $output->writeln('  <comment>keeping the '.count($previous).' previously stored addresses</comment>');
                foreach ($previous as $address)
                    $addresses[$address] = $name;
                $summary[$name] = count($previous).' addresses, kept from an earlier run';
                continue;
            }

            foreach (array_keys($found) as $address)
                $addresses[$address] = $name;

            $before = isset($existing[$name]) ? ' (was '.count($existing[$name]).')' : '';
            $output->writeln('  <info>'.count($found).'</info> addresses'.$before);
            $summary[$name] = count($found).' addresses, fetched '.gmdate('c');
        }

        // ----------------------------------------
        $output->writeln('');
        $output->writeln('Total: <info>'.count($addresses).'</info> addresses');

        if ( empty($addresses) ) {
            $output->writeln('<error>No addresses from any source, and nothing previously stored...refusing to write an empty list.</error>');
            return 1;
        }

        if ( $dry_run ) {
            $output->writeln('<comment>--dry-run given, nothing written</comment>');
            return ( $failures > 0 ) ? 1 : 0;
        }

        $written = self::writeFile($file, self::generateFile($addresses, $summary));
        if ( $written !== true ) {
            $output->writeln('<error>Could not write '.$file.': '.$written.'</error>');
            $logger->error('UpdateBannedBotIpsCommand.php: could not write "'.$file.'": '.$written);
            return 1;
        }
        $output->writeln('Wrote <info>'.$file.'</info>');

        // A partial failure is still worth a non-zero exit, so cron reports it
        return ( $failures > 0 ) ? 1 : 0;
    }


    /**
     * Downloads one url.  Returns array('body' => ...), or a string describing why it couldn't be
     * used.
     *
     * @param string $url
     *
     * @return array|string  array('body' => ...) on success
     */
    private static function fetch($url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
        curl_setopt($ch, CURLOPT_USERAGENT, 'ODR banned-bot updater');

        $body = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ( $body === false )
            return 'curl error: '.$curl_error;
        if ( $http_code !== 200 )
            return 'HTTP '.$http_code;

        return array('body' => $body);
    }


    /**
     * Every "ipv4Prefix" / "ipv6Prefix" value in a response, whether it's a json document or an
     * html page with the json embedded in it.
     *
     * @param string $body
     *
     * @return string[]
     */
    private static function parsePrefixes($body)
    {
        $addresses = array();
        preg_match_all('#"ipv[46]Prefix"\s*:\s*"([^"]+)"#', html_entity_decode($body), $matches);
        foreach ($matches[1] as $token) {
            $address = self::normalize($token);
            if ( $address !== null )
                $addresses[$address] = true;
        }

        return array_keys($addresses);
    }


    /**
     * A valid, public address or prefix in canonical form, or null.  Private, reserved and overly
     * broad prefixes are refused...this list feeds a firewall.
     *
     * @param string $token
     *
     * @return string|null
     */
    private static function normalize($token)
    {
        $parts = explode('/', trim($token), 2);
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

        if ( filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | $flags) !== false ) {
            $max = 32;
            $minimum_prefix = 16;
        }
        else if ( filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | $flags) !== false ) {
            $max = 128;
            $minimum_prefix = 32;
        }
        else {
            return null;
        }

        $address = inet_ntop(inet_pton($parts[0]));
        if ( !isset($parts[1]) )
            return $address;

        $bits = (int)$parts[1];
        if ( !ctype_digit($parts[1]) || $bits > $max || $bits < $minimum_prefix )
            return null;
        if ( $bits === $max )
            return $address;

        return $address.'/'.$bits;
    }


    /**
     * The addresses stored by an earlier run, grouped by source.
     *
     * @param string $file
     *
     * @return array source => string[]
     */
    private static function readExisting($file)
    {
        $existing = array();
        if ( !is_readable($file) )
            return $existing;

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ( $line === '' || $line[0] === '#' )
                continue;
            $fields = preg_split('/\s+/', trim($line));
            if ( count($fields) >= 2 )
                $existing[$fields[1]][] = $fields[0];
        }

        return $existing;
    }


    /**
     * The list file: one "<address> <source>" per line, after a commented header.
     *
     * @param array $addresses address => source
     * @param array $summary source => description
     *
     * @return string
     */
    private static function generateFile($addresses, $summary)
    {
        $lines = array();
        $lines[] = '# Banned bot addresses, read by docs/server/bot-bans/bin/odr-ban-bot-ips.';
        $lines[] = '# GENERATED FILE -- do not edit by hand.  Regenerate with:';
        $lines[] = '#     php app/console odr:banned_bot_ips:update';
        $lines[] = '# generated '.gmdate('c');
        foreach ($summary as $name => $description)
            $lines[] = '# '.$name.': '.$description;
        $lines[] = '';

        ksort($addresses, SORT_NATURAL);
        foreach ($addresses as $address => $source)
            $lines[] = $address.' '.$source;

        return implode("\n", $lines)."\n";
    }


    /**
     * Writes the file atomically.  Returns true, or a string explaining what went wrong.
     *
     * @param string $file
     * @param string $contents
     *
     * @return true|string
     */
    private static function writeFile($file, $contents)
    {
        $problem = self::checkWritable($file);
        if ( $problem !== true )
            return $problem;

        // NOTE: same approach as UpdateCrawlerIpsCommand...write alongside, then rename into
        //  place, so a reader never sees half a file
        $temp_file = $file.'.new.'.getmypid();
        if ( @file_put_contents($temp_file, $contents) === false ) {
            @unlink($temp_file);
            return 'could not write the temporary file '.$temp_file;
        }

        // root's cron job reads this
        @chmod($temp_file, 0644);

        if ( !@rename($temp_file, $file) ) {
            @unlink($temp_file);
            return 'could not move '.$temp_file.' into place';
        }

        return true;
    }


    /**
     * Returns true when the given path can be written, or a string explaining why it can't.
     *
     * @param string $file
     *
     * @return true|string
     */
    private static function checkWritable($file)
    {
        $directory = dirname($file);

        if ( !is_dir($directory) )
            return 'there is no directory '.$directory;
        if ( !is_writable($directory) )
            return $directory.' is not writable by the current user';
        if ( file_exists($file) && !is_writable($file) )
            return $file.' is not writable by the current user';

        return true;
    }
}
