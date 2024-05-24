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

use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
            ->setName('odr_csv_export:express_finalize')
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
                $job = $pheanstalk->watch('csv_export_express_finalize')
                    ->ignore('default')->reserve();

                // Get Job Data
                $data = json_decode($job->getData());

                // 
                $str = 'CSVExportFinalize request from '.$data->redis_prefix.'...';

                $current_time = new \DateTime();
                $output->writeln( $current_time->format('Y-m-d H:i:s').' (UTC-5)' );                
                $output->writeln($str);

                $parameters = array(
                    'tracked_job_id' => $data->tracked_job_id,
                    'final_filename' => $data->final_filename,
                    'random_keys' => $data->random_keys,
                    'user_id' => $data->user_id,
                    'api_key' => $data->api_key,
                );


                if ( !isset($parameters['tracked_job_id'])
                    || !isset($parameters['final_filename'])
                    || !isset($parameters['random_keys'])
                    || !isset($parameters['user_id'])
                    || !isset($parameters['api_key'])
                ) {
                    throw new ODRBadRequestException();
                }

                // Pull data from the post
                $tracked_job_id = intval($parameters['tracked_job_id']);
                $user_id = $parameters['user_id'];
                $final_filename = $parameters['final_filename'];
                $random_keys = $parameters['random_keys'];
                $api_key = $parameters['api_key'];

                // Load symfony objects
                $beanstalk_api_key = $container->getParameter('beanstalk_api_key');

                if ($api_key !== $beanstalk_api_key)
                    throw new ODRBadRequestException();

                /** @var \Doctrine\ORM\EntityManager $em */
                $em = $container->get('doctrine')->getManager();

                // -----------------------------------------
                // Append the contents of one of the temporary files to the final file
                $csv_export_path = $container->getParameter('odr_tmp_directory').'/user_'.$user_id.'/csv_export/';
                $final_file = fopen($csv_export_path.$final_filename, 'a');
                if (!$final_file)
                    throw new ODRException('Unable to open csv export finalize file');

                // Go through and append the contents of each of the temporary files to the "final" file
                $tracked_csv_export_id = null;
                foreach ($random_keys as $tracked_csv_export_id => $random_key) {
                    $tmp_filename = 'f_'.$random_key.'.csv';
                    $output->writeln('Appending file: ' . $tmp_filename);
                    $str = file_get_contents($csv_export_path.$tmp_filename);

                    if ( fwrite($final_file, $str) === false )
                        $output->writeln('could not write to "'.$csv_export_path.$final_filename.'"'."\n");

                    // Done with this intermediate file, get rid of it
                    if ( unlink($csv_export_path.$tmp_filename) === false )
                        $output->writeln('could not unlink "'.$csv_export_path.$tmp_filename.'"'."\n");

                    $tracked_csv_export = $em->getRepository('ODRAdminBundle:TrackedCSVExport')
                        ->find($tracked_csv_export_id);
                    $em->remove($tracked_csv_export);
                    $em->flush();
                }
                fclose($final_file);


                // Close the connection to prevent stale handles
                $em->getConnection()->close();

                // Dealt with (or ignored) the job
                $pheanstalk->delete($job);

            }
            catch (\Exception $e) {
                $output->writeln($e->getMessage());
                $logger->err('CSVExportExpressFinalizeCommand.php: '.$e->getMessage());

                // Delete the job so the queue doesn't hang, in theory
                $pheanstalk->delete($job);
            }
        }
    }
}
