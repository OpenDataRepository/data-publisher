<?php

/**
* Open Data Repository Data Publisher
* MassEdit Command
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* This Symfony console command takes beanstalk jobs from the
* mass_edit tube and passes the parameters to WorkerController,
* which will make a given edit to multiple DataRecord entities
* at once.
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

class MassEditCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_record:mass_edit')
            ->setDescription('Deals with requests to update multiple datarecords and datafields at once');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Only need to load these once...
        $container = $this->getContainer();
        $router = $container->get('router');
        $logger = $container->get('logger');
        $pheanstalk = $container->get('pheanstalk');

        while (true) {
            // Run command until manually stopped
            $job = null;
            try {
                // Wait for a job?
                $job = $pheanstalk->watch('mass_edit')->ignore('default')->reserve(); 
            }
            catch (\Exception $e) {
$output->writeln($e->getMessage());
                $logger->err('MassEditCommand.php: '.$e->getMessage());

                // Delete the job so the queue hopefully doesn't hang
                $pheanstalk->delete($job);
            }

            // Check to see if there's any datafields that need migrating
            while (true) {
                try {
                    $pheanstalk->useTube('migrate_datafields');
                    $migrate_job = $pheanstalk->peekReady('migrate_datafields');

                    // Don't care what job is in the tube, just that there is one
                    $output->writeln('waiting for datafield migration to finish...');
                    usleep(5000000);     // sleep for 5 seconds
                }
                catch (\Exception $e) {
                    // peekReady() throws a bona-fide exception when the tube is empty instead of returning NULL
                    // Since tube is empty, no datafields need migrating, so continue recaching
                    break;
                }
            }

            try {
                // Get Job Data
                $data = json_decode($job->getData()); 

                // 
                $logger->info('MassEditCommand.php: Request for DataRecordField '.$data->datarecordfield_id.' from '.$data->memcached_prefix.'...');
                $current_time = new \DateTime();
                $output->writeln( $current_time->format('Y-m-d H:i:s').' (UTC-5)' );
                $output->writeln('MassEdit request for DataRecordField '.$data->datarecordfield_id.' from '.$data->memcached_prefix.'...');

                // Need to use cURL to send a POST request...thanks symfony
                $ch = curl_init();

                // Create the required parameters to send
                $parameters = array(
                    'datarecordfield_id' => $data->datarecordfield_id,
                    'user_id' => $data->user_id,
                    'value' => $data->value,
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
                    throw new \Exception( curl_error($ch) );
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
//$logger->debug('MigrateCommand.php: curl results...'.print_r($result, true));

                // Done with this cURL object
                curl_close($ch);

                // Dealt with the job
                $pheanstalk->delete($job);

                // Sleep for a bit
                usleep(200000);

            }
            catch (\Exception $e) {
$output->writeln($e->getMessage());
                $logger->err('MassEditCommand.php: '.$e->getMessage());

                // Delete the job so the queue hopefully doesn't hang
                $pheanstalk->delete($job);
            }
        }
    }
}
