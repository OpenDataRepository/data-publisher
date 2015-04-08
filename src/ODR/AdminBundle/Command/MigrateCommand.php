<?php

/**
* Open Data Repository Data Publisher
* Migrate Command
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* This Symfony console command takes beanstalk jobs from the
* migrate_datafields tube and passes the parameters to WorkerController,
* which will perform the work of copying the contents of one
* storage-type entity (ShortVarchar, etc) into another compatible 
* storage-type entity.
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

class MigrateCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_record:migrate')
            ->setDescription('Migrates the data stored in entities from one fieldtype to another, and recreates required datarecord memached entries');
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
//                $job = $pheanstalk->watch($memcached_prefix.'_migrate_datafields')->ignore('default')->reserve(); 
                $job = $pheanstalk->watch('migrate_datafields')->ignore('default')->reserve(); 

                // Get Job Data
                $data = json_decode($job->getData()); 

                // 
                $logger->info('MigrateCommand.php: Migrate request for DataRecord '.$data->datarecord_id.', DataField '.$data->datafield_id.' from '.$data->memcached_prefix.'...');
                $current_time = new \DateTime();
                $output->writeln( $current_time->format('Y-m-d H:i:s').' (UTC-5)' );
                $output->writeln('Migrate request for DataRecord '.$data->datarecord_id.', DataField '.$data->datafield_id.' from '.$data->memcached_prefix.'...');

                // Need to use cURL to send a POST request...thanks symfony
                $ch = curl_init();

                // Create the required parameters to send
                $parameters = array(
                    'datarecord_id' => $data->datarecord_id,
                    'datafield_id' => $data->datafield_id,
                    'user_id' => $data->user_id,
                    'old_fieldtype_id' => $data->old_fieldtype_id,
                    'new_fieldtype_id' => $data->new_fieldtype_id,
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
                $logger->err('MigrateCommand.php: '.$e->getMessage());

                // Delete the job so the queue hopefully doesn't hang
                $pheanstalk->delete($job);
            }
        }
    }
}
