<?php

/**
 * Open Data Repository Data Publisher
 * CSVExportFinalize Command
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This Symfony console command takes beanstalk jobs from the
 * csv_export_finalize tube and passes the parameters to CSVExportController.
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


class CSVExportExpressFinalizeCommand extends ContainerAwareCommand
{

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_csv_export:finalize_express')
            ->setDescription('Finishes up a CSV export file...');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln( 'CSV Express Export Finalize Start' );

        // Only need to load these once...
        $container = $this->getContainer();
        $logger = $container->get('logger');
        $pheanstalk = $container->get('pheanstalk');

        // Run command until manually stopped
        while (true) {
            $job = null;
            try {
                // Wait for a job?
                $job = $pheanstalk->watch('csv_export_finalize_express')
                    ->ignore('default')->reserve();

                // Get Job Data
                $data = json_decode($job->getData());

                $parameters = array(
                    'tracked_job_id' => $data->tracked_job_id,
                    'user_id' => $data->user_id,

                    'delimiter' => $data->delimiter,
                    'datatype_id' => $data->datatype_id,
                    'datafields' => $data->datafields,

                    'api_key' => $data->api_key,
                );

                if ( !isset($parameters['tracked_job_id'])
                    || !isset($parameters['user_id'])
                    || !isset($parameters['delimiter'])
                    || !isset($parameters['datatype_id'])
                    || !isset($parameters['datafields'])
                    || !isset($parameters['api_key'])
                ) {
                    $output->writeln('Invalid list of parameters passed to CSVExportExpressFinalizeCommand');
                    $output->writeln( print_r($parameters, true) );
                    throw new ODRBadRequestException();
                }

                // Don't really want to spam the log every half second, but meh...
                $current_time = new \DateTime();
                $output->writeln( $current_time->format('Y-m-d H:i:s').' (UTC-5)' );
                $output->writeln('CSVExportExpressFinalizeCommand: request from '.$data->redis_prefix.' for tracked job '.$parameters['tracked_job_id'].'...');

                /** @var CSVExportHelperService $csv_export_helper_service */
                $csv_export_helper_service = $container->get('odr.csv_export_helper_service');
                $ret = $csv_export_helper_service->finalize($parameters);

                if ( $ret === 'success' ) {
                    $tracked_job_id = intval($parameters['tracked_job_id']);
                    $user_id = intval($parameters['user_id']);
                    $final_filename = 'export_'.$user_id.'_'.$tracked_job_id.'.csv';

                    $output->writeln('CSVExportExpressFinalizeCommand: finished processing tracked_job: '.$tracked_job_id.', final_filename: '.$final_filename);

                    // Dealt with the job
                    $pheanstalk->delete($job);
                }
                else if ( $ret === 'ignore' ) {
                    // Ignoring the job
                    $output->writeln('CSVExportExpressFinalizeCommand: ignoring tracked_job: '.$tracked_job_id.', final_filename: '.$final_filename);
                    $pheanstalk->delete($job);
                }
                else {
                    // Release the job back into the ready queue to try again
                    $pheanstalk->release($job);

                    // Sleep for a bit
                    usleep(1000000);     // sleep for 1 second
                }

            }
            catch (\Exception $e) {
                if ( $e->getMessage() == 'retry' ) {
                    $output->writeln( 'Could not resolve host, releasing job to try again' );
                    $logger->err('CSVExportExpressFinalizeCommand.php: '.$e->getMessage());

                    // Release the job back into the ready queue to try again
                    $pheanstalk->release($job);

                    // Sleep for a bit
                    usleep(1000000);     // sleep for 1 second
                }
                else {
                    $output->writeln('ERROR: ' . $e->getMessage());

                    $logger->err('CSVExportExpressFinalizeCommand.php: '.$e->getMessage());

                    // Delete the job so the queue doesn't hang, in theory
                    $pheanstalk->delete($job);
                }
            }
        }
    }
}
