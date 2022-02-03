<?php

/**
 * Open Data Repository Data Publisher
 * CSVExportWorker Command
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This Symfony console command takes beanstalk jobs from the
 * csv_export_worker tube and passes the parameters to CSVExportController.
 *
 */

namespace ODR\AdminBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


class CSVExportWorkerCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_csv_export:worker')
            ->setDescription('Does the work of writing lines of CSV data to file');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Only need to load these once...
        $container = $this->getContainer();
        $logger = $container->get('logger');
        $pheanstalk = $container->get('pheanstalk');

        // TODO - generate a random number to use for identifying a file
        $tokenGenerator = $container->get('fos_user.util.token_generator');
        $random_id = substr($tokenGenerator->generateToken(), 0, 8);

        // Run command until manually stopped
        while (true) {
            $job = null;
            try {
                // Wait for a job?
                $job = $pheanstalk->watch('csv_export_worker')->ignore('default')->reserve();

                // Get Job Data
                $data = json_decode($job->getData());

                // 
                $str = 'CSVExportWorker request for DataRecord '.$data->datarecord_id.'...';

                $current_time = new \DateTime();
                $output->writeln( $current_time->format('Y-m-d H:i:s').' (UTC-5)' );                
                $output->writeln($str);
                $output->writeln($data->url);

                // Need to use cURL to send a POST request...thanks symfony
                $ch = curl_init();

                // TODO - determine filename
                $random_key = $random_id.'_'.$data->datatype_id.'_'.$data->tracked_job_id;

                // Create the required url and the parameters to send
                $parameters = array(
                    'tracked_job_id' => $data->tracked_job_id,
                    'user_id' => $data->user_id,

                    'delimiter' => $data->delimiter,
                    'file_image_delimiter' => $data->file_image_delimiter,
                    'radio_delimiter' => $data->radio_delimiter,
                    'tag_delimiter' => $data->tag_delimiter,
                    'tag_hierarchy_delimiter' => $data->tag_hierarchy_delimiter,

                    'datatype_id' => $data->datatype_id,
                    'datarecord_id' => $data->datarecord_id,
                    'complete_datarecord_list' => $data->complete_datarecord_list,
                    'datafields' => $data->datafields,

                    'api_key' => $data->api_key,
                    'random_key' => $random_key,
                );

                // Set the options for the POST request
                curl_setopt_array($ch,
                    array(
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
                    $output->writeln( $result->d );
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
//$logger->debug('CSVExportWorkerCommand.php: curl results...'.print_r($result, true));

                // Done with this cURL object
                curl_close($ch);

                // Dealt with (or ignored) the job
                $pheanstalk->delete($job);
            }
            catch (\Exception $e) {
                if ( $e->getMessage() == 'retry' ) {
                    $output->writeln( 'Could not resolve host, releasing job to try again' );
                    $logger->err('CSVExportWorkerCommand.php: '.$e->getMessage());

                    // Release the job back into the ready queue to try again
                    $pheanstalk->release($job);

                    // Sleep for a bit
                    usleep(1000000);     // sleep for 1 second
                }
                else {
                    $output->writeln($e->getMessage());

                    $logger->err('CSVExportWorkerCommand.php: '.$e->getMessage());

                    // Delete the job so the queue doesn't hang, in theory
                    $pheanstalk->delete($job);
                }
            }
        }
    }
}
