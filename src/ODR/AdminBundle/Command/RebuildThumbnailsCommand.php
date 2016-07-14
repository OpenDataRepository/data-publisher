<?php

/**
* Open Data Repository Data Publisher
* RebuildThumbnails Command
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* This Symfony console command takes beanstalk jobs from the
* rebuild_thumbnails tube and passes the parameters to WorkerController,
* which will forcibly recreate all Image thumbnails on the server.
*
*/

namespace ODR\AdminBundle\Command;

//use Symfony\Component\Console\Command\Command;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;


use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

// dunno if needed
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;

class RebuildThumbnailsCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_record:rebuild_thumbnails')
            ->setDescription('Rebuilds thumbnails for ALL images uploaded to the database.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Only need to load these once...
        $container = $this->getContainer();
        $logger = $container->get('logger');
        $pheanstalk = $container->get('pheanstalk');

        while (true) {
            // Run command until manually stopped
            $job = null;
            try {
                // Wait for a job?
                $job = $pheanstalk->watch('rebuild_thumbnails')->ignore('default')->reserve();

                // Get Job Data
                $data = json_decode($job->getData()); 

                // 
                $logger->info('RebuildThumbnailsCommand.php: Rebuild request for '.$data->object_type.' '.$data->object_id.' from '.$data->redis_prefix.'...');
                $current_time = new \DateTime();
                $output->writeln( $current_time->format('Y-m-d H:i:s').' (UTC-5)' );
                $output->writeln('Rebuild request for '.$data->object_type.' '.$data->object_id.' from '.$data->redis_prefix.'...');

                // Need to use cURL to send a POST request...thanks symfony
                $ch = curl_init();

                // Create the required parameters to send
                $parameters = array(
                    'tracked_job_id' => $data->tracked_job_id,
                    'object_type' => $data->object_type,
                    'object_id' => $data->object_id,
                    'api_key' => $data->api_key
                );

                // Set the options for the POST request
                curl_setopt_array($ch, array(
                        CURLOPT_POST => 1,
                        CURLOPT_HEADER => 0,
                        CURLOPT_URL => $data->url,
                        CURLOPT_FRESH_CONNECT => 1,
                        CURLOPT_RETURNTRANSFER => 1,
                        CURLOPT_FORBID_REUSE => 1,
                        CURLOPT_TIMEOUT => 120, 
                        CURLOPT_POSTFIELDS => http_build_query($parameters)
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

                // Do things with the response returned by the controller?
                $result = json_decode($ret);
                if ( isset($result->r) && isset($result->d) ) {
                    if ( $result->r == 0 )
                        $output->writeln( $result->d );
                    else
                        throw new \Exception( $result->d );
                }
                else {
                    // Should always be a json return...
                    throw new \Exception( print_r($ret, true) );
                }
//$logger->debug('RebuildThumbnailsCommand.php: curl results...'.print_r($result, true));

                // Done with this cURL object
                curl_close($ch);

                // Dealt with the job
                $pheanstalk->delete($job);

                // Sleep for a bit
                usleep(200000);

            }
            catch (\Exception $e) {
                if ( $e->getMessage() == 'retry' ) {
                    $output->writeln( 'Could not resolve host, releasing job to try again' );
                    $logger->err('RebuildThumbnailsCommand.php: '.$e->getMessage());

                    // Release the job back into the ready queue to try again
                    $pheanstalk->release($job);

                    // Sleep for a bit
                    usleep(1000000);     // sleep for 1 second
                }
                else {
                    $output->writeln($e->getMessage());

                    $logger->err('RebuildThumbnailsCommand.php: '.$e->getMessage());

                    // Delete the job so the queue doesn't hang, in theory
                    $pheanstalk->delete($job);
                }
            }
        }
    }
}
