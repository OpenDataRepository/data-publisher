<?php

/**
 * Open Data Repository Data Publisher
 * UpdateCrawlerIps Command
 * (C) 2026 by Nathan Stone (nate.stone@opendatarepository.org)
 * Released under the GPLv2
 *
 * Downloads the IP ranges that Google, Bing, and DuckDuckGo publish for their crawlers, and
 * writes them into a generated php file for CrawlerVerificationService to read.
 *
 * Nothing is downloaded while serving a request...run this from cron (weekly is plenty, Google's
 * list is the only one that moves much) and commit the result.
 *
 * All three sources happen to publish the same schema:
 *   { "creationTime": "...", "prefixes": [ { "ipv4Prefix": "..." }, { "ipv6Prefix": "..." } ] }
 *
 * Usage:
 *   php app/console odr:crawler_ips:update
 *   php app/console odr:crawler_ips:update --out=/path/to/other/crawler_ips.php
 *   php app/console odr:crawler_ips:update --check=66.249.66.1
 */

namespace ODR\AdminBundle\Command;

use ODR\AdminBundle\Command\ContainerAwareCommand;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


class UpdateCrawlerIpsCommand extends ContainerAwareCommand
{

    /**
     * Where each search engine publishes the IP ranges its crawlers use.
     *
     * NOTE: google used to publish this at /static/search/apis/ipranges/googlebot.json, which now
     * redirects.  'common-crawlers' is the indexing crawlers...'special-crawlers' (AdsBot) and
     * 'user-triggered-fetchers' (Search Console tests, Translate) are deliberately not included,
     * since neither is indexing the site.
     */
    const SOURCES = array(
        'google' => 'https://developers.google.com/static/crawling/ipranges/common-crawlers.json',
        'bing' => 'https://www.bing.com/toolbox/bingbot.json',
        'duckduckgo' => 'https://duckduckgo.com/duckduckbot.json',
    );

    /**
     * A source returning fewer prefixes than this is treated as broken, so a truncated or
     * error-page response can't quietly empty out the list and de-index the site.
     */
    const MINIMUM_PREFIXES = 5;

    /**
     * Seconds to wait on each source.
     */
    const TIMEOUT = 30;


    /**
     * @inheritdoc
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr:crawler_ips:update')
            ->setDescription('Downloads the published crawler IP ranges for Google/Bing/DuckDuckGo into a generated php file')
            ->addOption(
                'out',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Where to write the generated file.  Repeatable, so one run can also update e.g. a wordpress theme copy.  Defaults to the file CrawlerVerificationService reads.'
            )
            ->addOption(
                'check',
                null,
                InputOption::VALUE_REQUIRED,
                "Doesn't download anything...just reports which crawler (if any) the given IP address belongs to, according to the stored file"
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

        $default_file = $container->getParameter('odr_crawler_ip_file');

        // ----------------------------------------
        // The --check option is for verifying a server has usable data, and doesn't download
        if ( $input->getOption('check') !== null ) {
            return $this->checkAddress($input->getOption('check'), $output);
        }


        // ----------------------------------------
        $output_files = $input->getOption('out');
        if ( empty($output_files) )
            $output_files = array($default_file);

        $dry_run = (bool)$input->getOption('dry-run');

        // Check the destinations first, so a permission problem is reported before anything is
        //  downloaded rather than after
        if ( !$dry_run ) {
            $blocked = false;
            foreach ($output_files as $file) {
                $problem = self::checkWritable($file);
                if ( $problem !== true ) {
                    $output->writeln('<error>Cannot write '.$file.': '.$problem.'</error>');
                    $blocked = true;
                }
            }

            if ( $blocked ) {
                $output->writeln('');
                $output->writeln('Nothing was downloaded.  Either fix the permissions, or run this as the user that owns those files.');
                return 1;
            }
        }

        // Load whatever is currently stored, so a source that fails to download keeps its
        //  previous prefixes instead of silently vanishing from the list
        $existing = array();
        if ( file_exists($default_file) ) {
            $tmp = @include $default_file;
            if ( is_array($tmp) && isset($tmp['sources']) )
                $existing = $tmp;
        }

        $prefixes = array();
        $sources = array();
        $failures = 0;

        foreach (self::SOURCES as $name => $url) {
            $output->writeln('Fetching <info>'.$name.'</info> from '.$url.' ...');

            $parsed = $this->fetchSource($url);
            if ( is_string($parsed) ) {
                // Couldn't use this source...carry the previous data forward if there is any
                $failures++;
                $output->writeln('  <error>failed: '.$parsed.'</error>');
                $logger->error('UpdateCrawlerIpsCommand.php: could not update "'.$name.'": '.$parsed);

                $carried = self::carryForward($existing, $name);
                if ( $carried === null ) {
                    $output->writeln('  <comment>no previous data to keep for '.$name.'</comment>');
                }
                else {
                    $output->writeln('  <comment>keeping previously stored '.count($carried['prefixes']).' prefixes (published '.$carried['published'].')</comment>');
                    $prefixes = array_merge($prefixes, $carried['prefixes']);
                    $sources[$name] = $carried['source'];
                }

                continue;
            }

            $source_prefixes = array();
            foreach ($parsed['prefixes'] as $entry) {
                $prefix = null;
                if ( isset($entry['ipv4Prefix']) )
                    $prefix = $entry['ipv4Prefix'];
                else if ( isset($entry['ipv6Prefix']) )
                    $prefix = $entry['ipv6Prefix'];

                if ( $prefix === null )
                    continue;

                $converted = self::convertPrefix($prefix, $name);
                if ( $converted !== null )
                    $source_prefixes[] = $converted;
            }

            $prefixes = array_merge($prefixes, $source_prefixes);
            $sources[$name] = array(
                'url' => $url,
                'published' => isset($parsed['creationTime']) ? $parsed['creationTime'] : null,
                'prefixes' => count($source_prefixes),
            );

            $previous_count = isset($existing['sources'][$name]['prefixes']) ? $existing['sources'][$name]['prefixes'] : null;
            $delta = ( $previous_count === null ) ? '' : ' (was '.$previous_count.')';
            $output->writeln('  <info>'.count($source_prefixes).'</info> prefixes, published '.$sources[$name]['published'].$delta);
        }


        // ----------------------------------------
        if ( empty($prefixes) ) {
            $output->writeln('');
            $output->writeln('<error>No prefixes from any source, and nothing previously stored...refusing to write an empty file.</error>');
            return 1;
        }

        // Split by family so the service doesn't have to, and pre-reduce the IPv4 prefixes to a
        //  network/mask pair of integers so matching them is just integer ops
        $ipv4 = array();
        $ipv6 = array();
        foreach ($prefixes as $prefix) {
            if ( $prefix['family'] === 4 )
                $ipv4[] = array($prefix['network'], $prefix['mask'], $prefix['source']);
            else
                $ipv6[] = array($prefix['network_hex'], $prefix['bits'], $prefix['source']);
        }

        $contents = self::generateFile($sources, $ipv4, $ipv6);

        $output->writeln('');
        $output->writeln('Total: <info>'.count($ipv4).'</info> IPv4 and <info>'.count($ipv6).'</info> IPv6 prefixes');

        if ( $dry_run ) {
            $output->writeln('<comment>--dry-run given, nothing written</comment>');
            return ( $failures > 0 ) ? 1 : 0;
        }

        foreach ($output_files as $file) {
            $written = self::writeFile($file, $contents);
            if ( $written !== true ) {
                $output->writeln('<error>Could not write '.$file.': '.$written.'</error>');
                $logger->error('UpdateCrawlerIpsCommand.php: could not write "'.$file.'": '.$written);
                return 1;
            }

            $output->writeln('Wrote <info>'.$file.'</info>');
        }

        // A partial failure is still worth a non-zero exit, so cron reports it
        return ( $failures > 0 ) ? 1 : 0;
    }


    /**
     * Reports which crawler the given IP address belongs to, according to the stored file.
     *
     * @param string $ip
     * @param OutputInterface $output
     *
     * @return int
     */
    private function checkAddress($ip, OutputInterface $output)
    {
        /** @var \ODR\AdminBundle\Component\Service\CrawlerVerificationService $crawler_service */
        $crawler_service = $this->getContainer()->get('odr.crawler_verification_service');

        $metadata = $crawler_service->getMetadata();
        if ( $metadata['generated'] === null ) {
            $output->writeln('<error>No stored crawler ranges...run this command without --check first.</error>');
            return 1;
        }

        $output->writeln('Stored ranges generated <info>'.$metadata['generated'].'</info>');
        foreach ($metadata['sources'] as $name => $source) {
            $output->writeln('  '.str_pad($name, 12).str_pad($source['prefixes'], 6, ' ', STR_PAD_LEFT).' prefixes, published '.$source['published']);
        }
        $output->writeln('');

        $crawler = $crawler_service->identifyCrawler($ip);
        if ( $crawler === null ) {
            $output->writeln($ip.' is <comment>not</comment> a verified crawler');
            return 0;
        }

        $output->writeln($ip.' is a verified <info>'.$crawler.'</info> crawler');
        return 0;
    }


    /**
     * Downloads and decodes one source.  Returns the decoded array, or a string describing why
     * the source couldn't be used.
     *
     * @param string $url
     *
     * @return array|string
     */
    private function fetchSource($url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
        curl_setopt($ch, CURLOPT_USERAGENT, 'ODR crawler-ip updater');

        $body = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ( $body === false )
            return 'curl error: '.$curl_error;
        if ( $http_code !== 200 )
            return 'HTTP '.$http_code;

        $parsed = json_decode($body, true);
        if ( !is_array($parsed) )
            return 'response was not json';
        if ( !isset($parsed['prefixes']) || !is_array($parsed['prefixes']) )
            return 'json had no "prefixes" array';
        if ( count($parsed['prefixes']) < self::MINIMUM_PREFIXES )
            return 'only '.count($parsed['prefixes']).' prefixes, expected at least '.self::MINIMUM_PREFIXES;

        return $parsed;
    }


    /**
     * Pulls one source's previously stored prefixes back out of the generated file, for when that
     * source can't be downloaded.
     *
     * @param array $existing
     * @param string $name
     *
     * @return array|null
     */
    private static function carryForward($existing, $name)
    {
        if ( !isset($existing['sources'][$name]) )
            return null;

        $prefixes = array();
        foreach ($existing['ipv4'] as $entry) {
            if ( $entry[2] === $name ) {
                $prefixes[] = array(
                    'family' => 4,
                    'network' => $entry[0],
                    'mask' => $entry[1],
                    'source' => $name,
                );
            }
        }
        foreach ($existing['ipv6'] as $entry) {
            if ( $entry[2] === $name ) {
                $prefixes[] = array(
                    'family' => 6,
                    'network_hex' => $entry[0],
                    'bits' => $entry[1],
                    'source' => $name,
                );
            }
        }

        if ( empty($prefixes) )
            return null;

        return array(
            'prefixes' => $prefixes,
            'published' => $existing['sources'][$name]['published'],
            'source' => $existing['sources'][$name],
        );
    }


    /**
     * Converts a CIDR string into the form the generated file stores.
     *
     * @param string $prefix e.g. "66.249.64.0/27" or "2001:4860:4801:10::/64"
     * @param string $source
     *
     * @return array|null
     */
    private static function convertPrefix($prefix, $source)
    {
        $pieces = explode('/', $prefix);
        if ( count($pieces) !== 2 )
            return null;

        $packed = @inet_pton($pieces[0]);
        if ( $packed === false )
            return null;

        $bits = (int)$pieces[1];

        if ( strlen($packed) === 4 ) {
            if ( $bits < 0 || $bits > 32 )
                return null;

            $mask = ( $bits === 0 ) ? 0 : ((0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF);
            $network = unpack('N', $packed)[1] & $mask;

            return array(
                'family' => 4,
                'network' => $network,
                'mask' => $mask,
                'source' => $source,
            );
        }

        if ( $bits < 0 || $bits > 128 )
            return null;

        return array(
            'family' => 6,
            'network_hex' => bin2hex($packed),
            'bits' => $bits,
            'source' => $source,
        );
    }


    /**
     * Renders the generated php file.
     *
     * @param array $sources
     * @param array $ipv4
     * @param array $ipv6
     *
     * @return string
     */
    private static function generateFile($sources, $ipv4, $ipv6)
    {
        $lines = array();
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = '/**';
        $lines[] = ' * Search engine crawler IP ranges, read by CrawlerVerificationService.';
        $lines[] = ' *';
        $lines[] = ' * GENERATED FILE -- do not edit by hand.  Regenerate with:';
        $lines[] = ' *     php app/console odr:crawler_ips:update';
        $lines[] = ' *';
        $lines[] = ' * ipv4 entries are [network, mask, source] with network/mask as integers.';
        $lines[] = ' * ipv6 entries are [packed network in hex, prefix length in bits, source].';
        $lines[] = ' */';
        $lines[] = '';
        $lines[] = 'return array(';
        $lines[] = "    'generated' => '".date('c')."',";
        $lines[] = "    'sources' => array(";
        foreach ($sources as $name => $source) {
            $published = ( $source['published'] === null ) ? 'null' : "'".addslashes($source['published'])."'";
            $lines[] = "        '".$name."' => array(";
            $lines[] = "            'url' => '".addslashes($source['url'])."',";
            $lines[] = "            'published' => ".$published.",";
            $lines[] = "            'prefixes' => ".$source['prefixes'].",";
            $lines[] = '        ),';
        }
        $lines[] = '    ),';

        $lines[] = "    'ipv4' => array(";
        foreach ($ipv4 as $entry) {
            $lines[] = '        array('.$entry[0].', '.$entry[1].", '".$entry[2]."'),";
        }
        $lines[] = '    ),';

        $lines[] = "    'ipv6' => array(";
        foreach ($ipv6 as $entry) {
            $lines[] = "        array('".$entry[0]."', ".$entry[1].", '".$entry[2]."'),";
        }
        $lines[] = '    ),';
        $lines[] = ');';
        $lines[] = '';

        return implode("\n", $lines);
    }


    /**
     * Writes the generated file in one step, so a reader never sees it half-written.
     *
     * Returns true on success, or a string explaining why the file couldn't be written.
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

        // Write alongside the target and rename it into place, so a reader never sees a partial
        //  file, and so the rename stays on the same filesystem.
        // NOTE: deliberately not tempnam()...that emits "file created in the system's temporary
        //  directory" and silently falls back to /tmp when the given directory isn't writable,
        //  which symfony's error handler then escalates into a fatal error
        $temp_file = $file.'.new.'.getmypid();

        if ( @file_put_contents($temp_file, $contents) === false ) {
            @unlink($temp_file);
            return 'could not write the temporary file '.$temp_file;
        }

        // The webserver user has to be able to read this
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
     * Checked before anything is downloaded, so permission problems are reported immediately
     * instead of after three http requests.
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
            return $directory.' is not writable by '.self::currentUser();
        if ( file_exists($file) && !is_writable($file) )
            return $file.' is not writable by '.self::currentUser();

        return true;
    }


    /**
     * Returns the name of the user running this command, for permission error messages.
     *
     * @return string
     */
    private static function currentUser()
    {
        if ( function_exists('posix_geteuid') && function_exists('posix_getpwuid') ) {
            $info = posix_getpwuid(posix_geteuid());
            if ( is_array($info) && isset($info['name']) )
                return 'the user "'.$info['name'].'"';
        }

        $user = getenv('USER');
        if ( $user !== false && $user !== '' )
            return 'the user "'.$user.'"';

        return 'the current user';
    }
}
