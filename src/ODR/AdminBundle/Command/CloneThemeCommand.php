<?php

/**
 * Open Data Repository Data Publisher
 * Clone Theme Command
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This background process is used to clone an existing Theme...typically the "master" theme at
 * first, but any Theme can be copied.
 */

namespace ODR\AdminBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;

// Entities
use ODR\AdminBundle\Entity\Theme;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Services
use ODR\AdminBundle\Component\Service\CloneThemeService;
// Symfony
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
// Other
use Doctrine\ORM\EntityManager;
use drymek\PheanstalkBundle\Entity\Job;


class CloneThemeCommand extends ContainerAwareCommand
{

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_theme:clone')
            ->setDescription('Clones a theme/view for users to customize.');
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
                $job = $pheanstalk->watch('clone_theme')->ignore('default')->reserve();
                $data = json_decode($job->getData());

                $current_time = new \DateTime();
                $output->writeln( $current_time->format('Y-m-d H:i:s').' (UTC-5)' );
                $output->writeln('Cloning theme '.$data->source_theme_id.' into a new "'.$data->dest_theme_type.'" theme, requested by user '.$data->user_id.'...');

                /** @var EntityManager $em */
                $em = $container->get('doctrine')->getEntityManager();

                /** @var ODRUser $user */
                $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($data->user_id);
                /** @var Theme $source_theme */
                $source_theme = $em->getRepository('ODRAdminBundle:Theme')->find($data->source_theme_id);
                $dest_theme_type = $data->dest_theme_type;

                /** @var CloneThemeService $clone_theme_service */
                $clone_theme_service = $this->getContainer()->get('odr.clone_theme_service');
                $new_parent_theme = $clone_theme_service->cloneThemeFromParent($user, $source_theme, $dest_theme_type);

                $current_time = new \DateTime();
                $output->writeln(' -- successfully cloned new "'.$dest_theme_type.'" theme (id '.$new_parent_theme->getId().') for datatype '.$new_parent_theme->getDataType()->getId());
                $output->writeln( $current_time->format('Y-m-d H:i:s').' (UTC-5)' );

                // Dealt with the job
                $pheanstalk->delete($job);

                // Sleep for a bit 200ms
                usleep(200000);
            }
            catch (\Exception $e) {
                if ( $e->getMessage() == 'retry' ) {
                    $output->writeln( 'Could not resolve host, releasing job to try again' );
                    $logger->err('CloneThemeCommand.php: '.$e->getMessage());

                    // Release the job back into the ready queue to try again
                    $pheanstalk->release($job);

                    // Sleep for a bit
                    usleep(1000000);     // sleep for 1 second
                }
                else {
                    $output->writeln($e->getMessage());

                    $logger->err('CloneThemeCommand.php: '.$e->getMessage());

                    // Delete the job so the queue doesn't hang, in theory
                    $pheanstalk->delete($job);
                }
            }
        }
    }
}
