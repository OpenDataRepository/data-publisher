<?php

/**
* Open Data Repository Data Publisher
* ImportValidate Command
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* This Symfony console command takes beanstalk jobs from the
* validate_import tube and passes the parameters to XMLController,
* which will attempt to validate the XML file against an XSD schema
* prior to actually attempting to import it.
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

class ImportValidateCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_import:validate')
            ->setDescription('Attempts to validate an XML file scheduled for import...');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Only need to load these once...
        $container = $this->getContainer();
        $logger = $container->get('logger');
        $router = $container->get('router');
        $pheanstalk = $container->get('pheanstalk');

        // Run command until manually stopped
        while (true) {
            $job = null;
            try {
                // Wait for a job?
//                $job = $pheanstalk->watch($memcached_prefix.'_validate_import')->ignore('default')->reserve();
                $job = $pheanstalk->watch('validate_import')->ignore('default')->reserve();

                // Get Job Data
                $data = json_decode($job->getData());

                //
                $str = '';
                if ($data->datatype_id !== '')
                    $str = 'Attempting to validate "'.$data->xml_filename.'" for DataType '.$data->datatype_id.' from '.$data->memcached_prefix.'...';
                else if ($data->datafield_id !== '')
                    $str = 'Attempting to validate "'.$data->xml_filename.'" for DataField '.$data->datafield_id.' from '.$data->memcached_prefix.'...';
                else
                    throw new \Exception('Invalid job data');

                $current_time = new \DateTime();
                $output->writeln( $current_time->format('Y-m-d H:i:s').' (UTC-5)' );                
                $output->writeln($str);
                $logger->info('ImportValidateCommand.php: '.$str);

                // Need to use cURL to send a POST request...thanks symfony
                $ch = curl_init();

$output->writeln($data->url);

                // Create the required url and the parameters to send
                $parameters = array('datatype_id' => $data->datatype_id, 'datafield_id' => $data->datafield_id, 'api_key' => $data->api_key, 'user_id' => $data->user_id, 'xml_filename' => $data->xml_filename);

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
//$logger->debug('ImportWorkerCommand.php: curl results...'.print_r($result, true));

                // Done with this cURL object
                curl_close($ch);

                // Dealt with (or ignored) the job
                $pheanstalk->delete($job);

            }
            catch (\Exception $e) {
                $output->writeln($e->getMessage());
                $logger->err('ImportValidateCommand.php: '.$e->getMessage());

                // Delete the job so the queue doesn't hang, in theory
                $pheanstalk->delete($job);
            }
        }
    }

}