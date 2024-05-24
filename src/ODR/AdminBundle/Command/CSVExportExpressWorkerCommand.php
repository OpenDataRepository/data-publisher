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

// Entities
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
// Services
use ODR\AdminBundle\Component\Service\CSVExportHelperService;
// Symfony
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


class CSVExportExpressWorkerCommand extends ContainerAwareCommand
{

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_csv_export:worker_express')
            ->setDescription('Does the work of writing lines of CSV data to file');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln( 'CSV Express Export Start' );
        // Only need to load these once...
        $container = $this->getContainer();
        $logger = $container->get('logger');
        $pheanstalk = $container->get('pheanstalk');

        // Run command until manually stopped
        while (true) {
            $job = null;
            try {
                // Wait for a job?
                $job = $pheanstalk->watch('csv_export_worker_express')
                    ->ignore('default')->reserve();

                // Get Job Data
                $data = json_decode($job->getData());

                // Display info about job
                $str = 'CSVExportWorker request for DataRecords';
                $current_time = new \DateTime();
                $output->writeln( $current_time->format('Y-m-d H:i:s').' (UTC-5)' );
                $output->writeln($str);
                $output->writeln($data->url);


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

                    'job_order' => $data->job_order,
                    'api_key' => $data->api_key,
                    'redis_prefix' => $data->redis_prefix,
                );

                if ( !isset($parameters['tracked_job_id'])
                    || !isset($parameters['user_id'])
                    || !isset($parameters['delimiter'])

                    || !isset($parameters['datatype_id'])
                    || !isset($parameters['datarecord_id'])
                    || !isset($parameters['complete_datarecord_list'])
                    || !isset($parameters['datafields'])

                    || !isset($parameters['job_order'])
                    || !isset($parameters['api_key'])
                    || !isset($parameters['redis_prefix'])
                ) {
                    $output->writeln('Invalid list of parameters passed to command');
                    $output->writeln( print_r($parameters, true) );
                    throw new ODRBadRequestException();
                }

                /** @var CSVExportHelperService $csv_export_helper_service */
                $csv_export_helper_service = $container->get('odr.csv_export_helper_service');
                $csv_export_helper_service->execute($parameters);

                // Dealt with (or ignored) the job
                $pheanstalk->delete($job);
            }
            catch (\Exception $e) {
                if ( $e->getMessage() == 'retry' ) {
                    $output->writeln( 'Could not resolve host, releasing job to try again' );
                    $logger->err('CSVExportExpressWorkerCommand.php: '.$e->getMessage());

                    // Release the job back into the ready queue to try again
                    $pheanstalk->release($job);

                    // Sleep for a bit
                    usleep(1000000);     // sleep for 1 second
                }
                else {
                    $output->writeln('ERROR: ' . $e->getMessage());

                    $logger->err('CSVExportExpressWorkerCommand.php: '.$e->getMessage());

                    // Delete the job so the queue doesn't hang, in theory
                    $pheanstalk->delete($job);
                }
            }
        }
    }

}
