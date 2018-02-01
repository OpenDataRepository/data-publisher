<?php

/**
 * Open Data Repository Data Publisher
 * Datarecord Restriction Command
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Due to current limitations in the permissions system related to he datarecord restriction, ODR
 * will throw an error if the search results referenced by a datarecord restriction don't exist.
 * This command attempts to ensure that the search result is always cached, and will trigger a
 * search if it's not.
 */

namespace ODR\AdminBundle\Command;

use ODR\AdminBundle\Component\Service\CacheService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchCacheService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


class DatarecordRestrictionCommand extends ContainerAwareCommand
{

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_permissions:kludge')
            ->setDescription('');
    }


    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Only need to load these once...
        $container = $this->getContainer();
        $logger = $container->get('logger');
        $router = $container->get('router');

        /** @var CacheService $cache_service */
        $cache_service = $container->get('odr.cache_service');
        /** @var SearchCacheService $search_cache_service */
        $search_cache_service = $container->get('odr.search_cache_service');

        // Going to need all of these...
        $baseurl = $container->getParameter('site_baseurl');
        $url = $baseurl.$router->generate('odr_search_results');

        $datatype_id = 20;
        $search_params = array(
            'dt_id' => strval($datatype_id),
            '141' => 'LF'
        );
        $search_key = $search_cache_service->encodeSearchKey($search_params);
        $search_checksum = md5($search_key);


        while (true) {
            // Run command until manually stopped

            try {
                // Check whether the desired search key is still cached...
                $cached_searches = $cache_service->get('cached_search_results');
                if ($cached_searches == false
                    || !isset($cached_searches[$datatype_id])
                    || !isset($cached_searches[$datatype_id][$search_checksum])
                ) {
                    // ...it doesn't exist, send a request to redo the search
                    $current_time = new \DateTime();
                    $output->writeln($current_time->format('Y-m-d H:i:s').' (UTC-5)');
                    $output->writeln('cached search for "'.$search_key.'" is missing, sending a POST request to "'.$url.'" to refresh it');

                    // Need to use cURL to send a POST request...thanks symfony
                    $ch = curl_init();

                    // Set the options for the POST request
                    curl_setopt_array($ch, array(
                            CURLOPT_POST => 1,
                            CURLOPT_HEADER => 0,
                            CURLOPT_URL => $url,
                            CURLOPT_FRESH_CONNECT => 1,
                            CURLOPT_RETURNTRANSFER => 1,
                            CURLOPT_FORBID_REUSE => 1,
                            CURLOPT_TIMEOUT => 120,
                            CURLOPT_POSTFIELDS => http_build_query($search_params)
                        )
                    );

                    // Send the request
                    if( ! $ret = curl_exec($ch)) {
                        if (curl_errno($ch) == 6) {
                            // Could not resolve host
                            throw new \Exception('retry');
                        }
                        else {
                            throw new \Exception( curl_error($ch) );
                        }
                    }
                    else {
                        // Do things with the response returned by the controller?
                        $result = json_decode($ret);
                        if ( isset($result->r) && isset($result->d) ) {
                            if ( $result->r == 0 ) {
//                                $output->writeln( $result->d );
                            }
                            else if ( $result->r == 1 ) {
                                throw new \Exception( $result->d );
                            }
                            else if ( $result->r == 2 ) {
//                                $output->writeln( $result->d );
                            }
                        }
                        else if ( isset($result->error) ) {
                            $error = $result->error;
                            $message = $error->code.' '.$error->status_text.' ('.$error->exception_source.'): '.$error->message;

                            $output->writeln( $message );
                        }
                        else {
                            // Should always be a json return...
                            throw new \Exception( print_r($ret, true) );
                        }
//$logger->debug('DatarecordRestrictionCommand.php: curl results...'.print_r($result, true));

                        // Done with this cURL object
                        curl_close($ch);
                    }
                }

                // Wait for a bit before checking again
                sleep(60);      // sleep for 1 minute
            }
            catch (\Exception $e) {
                $output->writeln($e->getMessage());
                $logger->err('DatarecordRestrictionCommand.php: '.$e->getMessage());

                sleep(600);      // sleep for 1 hour?  if an error does show up, it's unlikely to go away without intervention...
            }
        }
    }
}
