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
use Doctrine\ORM\EntityManager;
use drymek\PheanstalkBundle\Entity\Job;
use ODR\AdminBundle\Entity\TrackedJob;



class CloneAndLinkDatatypeCommand extends ContainerAwareCommand
{

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_datatype:clone_and_link_datatype')
            ->setDescription('Clones a datatype from a pre-existing master template.');
    }


    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // TODO This function need anti-timeout handler
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
                $job = $pheanstalk->watch('clone_and_link_datatype')->ignore('default')->reserve();
                $data = json_decode($job->getData());

                // Dealt with the job
                // Just need to clear things....
                // $pheanstalk->delete($job);

                $current_time = new \DateTime();
                $output->writeln($current_time->format('Y-m-d H:i:s').' (UTC-5)' );
                $output->writeln('Beginning clone and link process for datatype '.$data->datatype_id.', requested by user '.$data->user_id.'...');

                /** @var CloneDatatypeService $clone_datatype_service */
                $clone_datatype_service = $this->getContainer()->get('odr.clone_master_datatype_service');
                $result = $clone_datatype_service->createDatatypeFromMaster($data->datatype_id, $data->user_id, $data->template_group);


                /** @var EntityManager $em */
                $em = $container->get('doctrine')->getEntityManager();

                // Complete job
                $repo_tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob');
                /** @var TrackedJob $tracked_job */
                $tracked_job = $repo_tracked_job->find($data->tracked_job_id);
                $tracked_job->setCompleted(new \DateTime());
                $em->persist($tracked_job);
                $em->flush();

                $current_time = new \DateTime();
                $output->writeln( $current_time->format('Y-m-d H:i:s').' (UTC-5)' );
                $output->writeln('Clone and link process for datatype '.$data->datatype_id.' '.$result);

                // Dealt with the job
                $pheanstalk->delete($job);

                // Exit to reset cache...
                exit();

                // Sleep for a bit 10ms
                // usleep(10000);
            }
            catch (\Throwable $e) {
                if ($e->getMessage() == 'retry') {
                    $output->writeln('Could not resolve host, releasing job to try again');
                    $logger->error('CloneAndLinkDatatypeCommand.php: ' . $e->getMessage());

                    // Release the job back into the ready queue to try again
                    $pheanstalk->release($job);

                    // Sleep for a bit
                    usleep(1000000);     // sleep for 1 second
                } else {
                    $output->writeln($e->getMessage());

                    $logger->error('CloneAndLinkDatatypeCommand.php: ' . $e->getMessage());

                    // Delete the job so the queue doesn't hang, in theory
                    $pheanstalk->delete($job);
                }
            }
            catch (\Exception $e) {
                if ( $e->getMessage() == 'retry' ) {
                    $output->writeln( 'Could not resolve host, releasing job to try again' );
                    $logger->error('CloneAndLinkDatatypeCommand.php: '.$e->getMessage());

                    // Release the job back into the ready queue to try again
                    $pheanstalk->release($job);

                    // Sleep for a bit
                    usleep(1000000);     // sleep for 1 second
                }
                else {
                    $output->writeln($e->getMessage());

                    $logger->error('CloneAndLinkDatatypeCommand.php: '.$e->getMessage());

                    // Delete the job so the queue doesn't hang, in theory
                    $pheanstalk->delete($job);
                }
            }
        }
    }
}
