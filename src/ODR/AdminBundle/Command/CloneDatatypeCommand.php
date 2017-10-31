<?php

/**
 * Open Data Repository Data Publisher
 * Clone Datatype Command
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This background process is used to clone an existing "Master Template" (really just a datatype
 * without datarecords), and all its associated themes and datafields into a new datatype.
 */

namespace ODR\AdminBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;

// Services
use ODR\AdminBundle\Component\Service\CloneDatatypeService;
// Symfony
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
// Other
use drymek\PheanstalkBundle\Entity\Job;


class CloneDatatypeCommand extends ContainerAwareCommand
{

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_datatype:clone')
            ->setDescription('Clones a datatype from a pre-existing master template.');
    }


    /**
     * {@inheritdoc}
     */
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
                // Watch for a job
                /** @var Job $job */
                $job = $pheanstalk->watch('create_datatype')->ignore('default')->reserve();
                $data = json_decode($job->getData());

                $current_time = new \DateTime();
                $output->writeln( $current_time->format('Y-m-d H:i:s').' (UTC-5)' );
                $output->writeln('Beginning cloning process for datatype '.$data->datatype_id.', requested by user '.$data->user_id.'...');

                /** @var CloneDatatypeService $clone_datatype_service */
                $clone_datatype_service = $this->getContainer()->get('odr.clone_datatype_service');
                $result = $clone_datatype_service->createDatatypeFromMaster($data->datatype_id, $data->user_id);

                $current_time = new \DateTime();
                $output->writeln( $current_time->format('Y-m-d H:i:s').' (UTC-5)' );
                $output->writeln('Cloning process for datatype '.$data->datatype_id.' '.$result);

/*
                // Do things with the response returned by the controller?
                $result = json_decode($ret);
                if ( isset($result->r) && isset($result->d) ) {
                    if ( $result->r == 0 && $data->crypto_type == 'encrypt' )
                        $output->writeln( $result->d );
                    else
                        throw new \Exception( $result->d );
                }
                else {
                    // Should always be a json return...
                    throw new \Exception( print_r($ret, true) );
                }
*/

                // Dealt with the job
                $pheanstalk->delete($job);

                // Sleep for a bit 200ms
                usleep(200000);
            }
            catch (\Exception $e) {
                if ( $e->getMessage() == 'retry' ) {
                    $output->writeln( 'Could not resolve host, releasing job to try again' );
                    $logger->err('CloneDatatypeCommand.php: '.$e->getMessage());

                    // Release the job back into the ready queue to try again
                    $pheanstalk->release($job);

                    // Sleep for a bit
                    usleep(1000000);     // sleep for 1 second
                }
                else {
                    $output->writeln($e->getMessage());

                    $logger->err('CloneDatatypeCommand.php: '.$e->getMessage());

                    // Delete the job so the queue doesn't hang, in theory
                    $pheanstalk->delete($job);
                }
            }
        }
    }
}
