<?php

/**
 * Open Data Repository Data Publisher
 * Create Datatype Command
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This background process is used to clone an existing "Master Template" (really just a datatype without datarecords),
 * and all its associated themes and datafields into a new datatype.
 */

namespace ODR\AdminBundle\Command;

use Doctrine\ORM\EntityManager;
use drymek\PheanstalkBundle\Entity\Job;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;

// Services
use ODR\AdminBundle\Component\Service\ThemeService;
use ODR\AdminBundle\Component\Service\TrackedJobService;
// Symfony
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\Theme;

// Exceptions
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRNotFoundException;

use Symfony\Component\DependencyInjection\Container;


class CloneThemeCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_theme:clone')
            ->setDescription('Clones a theme/view for users to customize.');
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
                // Watch for a job
                /** @var Job $job */
                $job = $pheanstalk->watch('clone_theme')->ignore('default')->reserve();
                // Get the job data
                $data = json_decode($job->getData());
                /** @var Container $container */
                $container = $this->getApplication()->getKernel()->getContainer();

                $current_time = new \DateTime();
                $output->writeln( $current_time->format('Y-m-d H:i:s').' (UTC-5)' );
                $output->writeln('Beginning cloning process for datatype '.$data->datatype_id.', requested by user '.$data->user_id.'...');

                /** @var ThemeService $theme_service */
                $theme_service = $this->getApplication()->getKernel()->getContainer()
                    ->get('odr.theme_service');

                /** @var EntityManager $em */
                $em = $container->get('doctrine')->getEntityManager();
                /** @var DataType $datatype */
                $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($data->datatype_id);
                if ($datatype == null)
                    throw new ODRNotFoundException('Database not found.', true, 0x28378929);

                $output->writeln('Got datatype '.$data->datatype_id.', requested by user '.$data->user_id.'...');
                /** @var Theme $original_theme */
                $original_theme = $em->getRepository('ODRAdminBundle:Theme')->find($data->theme_id);
                if ($original_theme == null)
                    throw new ODRNotFoundException('View/theme was not found.', true , 0x88228929);

                $output->writeln('Got theme '.$data->theme_id.', requested by user '.$data->user_id.'...');
                $output->writeln('Tracking job '.$data->tracked_job_id.', requested by user '.$data->user_id.'...');

                // Set job status to

                /** @var Theme $theme */
                $theme = $theme_service->cloneTheme(
                    $datatype,
                    $original_theme,
                    $data->user_id,
                    $data->tracked_job_id
                );


                $output->writeln('Cloned theme '.$data->theme_id.', requested by user '.$data->user_id.'...');
                // Mark job complete

                $current_time = new \DateTime();
                $output->writeln( $current_time->format('Y-m-d H:i:s').' (UTC-5)' );
                $output->writeln('Cloning complete for datatype '.$data->datatype_id.' '.$theme->getId());

                // Dealt with the job
                $pheanstalk->delete($job);

                // Sleep for a bit 200ms
                usleep(200000);
            }
            catch (\Exception $e) {
                if ( $e->getMessage() == 'retry' ) {
                    $output->writeln( 'Could not resolve host, releasing job to try again' );
                    $logger->err('CreateDatatypeCommand.php: '.$e->getMessage());

                    // Release the job back into the ready queue to try again
                    $pheanstalk->release($job);

                    // Sleep for a bit
                    usleep(1000000);     // sleep for 1 second
                }
                else {
                    $output->writeln($e->getMessage());

                    $logger->err('CreateDatatypeCommand.php: '.$e->getMessage());

                    // Delete the job so the queue doesn't hang, in theory
                    $pheanstalk->delete($job);
                }
            }
        }
    }
}
