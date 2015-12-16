<?php

/**
* Open Data Repository Data Publisher
* RecacheType Command
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* This Symfony console command takes beanstalk jobs from the
* recache_type tube and passes the parameters to WorkerController,
* which will rebuild all memcached entries for all DataRecords
* of a DataType.
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

class RecacheTypeCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_cache:recache_type')
            ->setDescription('Refreshes all memached entries for a given datatype');
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
                $job = $pheanstalk->watch('recache_type')->ignore('default')->reserve();

                // Get Job Data
                $data = json_decode($job->getData());

                // 
                $logger->info('RecacheTypeCommand.php: Recache (all) request for DataRecord '.$data->datarecord_id.' from '.$data->memcached_prefix.'...');
                $current_time = new \DateTime();
                $output->writeln( $current_time->format('Y-m-d H:i:s').' (UTC-5)' );
                $output->writeln('Recache (all) request for DataRecord '.$data->datarecord_id.' from '.$data->memcached_prefix.'...');

                // Need to use cURL to send a POST request to the server...thanks symfony
                $ch = curl_init();

$output->writeln($data->url);

                // Create the required parameters to send
                $parameters = array(
                    'tracked_job_id' => $data->tracked_job_id,
                    'datarecord_id' => $data->datarecord_id,
                    'api_key' => $data->api_key,
                    'scheduled_at' => $data->scheduled_at
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
                $delete_job = true;
                if( ! $ret = curl_exec($ch)) {
                    if ( curl_errno($ch) == 28 ) {
$output->writeln('timeout detected, deleting job and sleeping for 5 minutes...');
$logger->err('RecacheTypeCommand.php: timeout detected, deleting job and sleeping for 5 minutes...');
                        $pheanstalk->delete($job);
                        curl_close($ch);

                        usleep(300000000);  // sleep for 5 minutes?
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
                            $output->writeln( $result->d );
                        }
                        else if ( $result->r == 1 ) {
                            throw new \Exception( $result->d );
                        }
                        else if ( $result->r == 2 ) {
                            $output->writeln( $result->d );
                            $delete_job = false;
                        }
                    }
                    else {
                        // Should always be a json return...
                        throw new \Exception( print_r($ret, true) );
                    }
//$logger->debug('RecacheTypeCommand.php: curl results...'.print_r($result, true));

                    // Done with this cURL object
                    curl_close($ch);
                    // Dealt with the job
                    if ($delete_job)
                        $pheanstalk->delete($job);
                    else
                        $pheanstalk->release($job, 2048, 10);   // release job back into queue as lower priority, on a 10 second delay

                }

                // Sleep for a bit
                usleep(200000);     // sleep for 0.2 seconds

            }
            catch (\Exception $e) {
$output->writeln('RecacheTypeCommand.php: '.$e->getMessage());
                $logger->err('RecacheTypeCommand.php: '.$e->getMessage());

                // Delete the job so the queue doesn't hang, in theory
                $pheanstalk->delete($job);
            }
        }
    }
}
