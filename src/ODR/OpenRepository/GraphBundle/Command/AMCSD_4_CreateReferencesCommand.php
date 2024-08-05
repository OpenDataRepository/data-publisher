<?php

/**
* Open Data Repository Data Publisher
* AMCSD Create References Command
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* TODO
*
*/

namespace ODR\OpenRepository\GraphBundle\Command;

// Services
use ODR\OpenRepository\GraphBundle\Component\Service\AMCSDUpdateService;
// Symfony
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


class AMCSD_4_CreateReferencesCommand extends ContainerAwareCommand
{

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_amcsd_update:4_references')
            ->setDescription('TODO');
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
        $beanstalk_api_key = $container->getParameter('beanstalk_api_key');

        while (true) {
            // Run command until manually stopped
            $job = null;
            try {
                // Wait for a job?
                $job = $pheanstalk->watch('amcsd_4_references')->ignore('default')->reserve();

                // Get Job Data
                $data = json_decode($job->getData(), true);
                $user_id = $data['user_id'];
                $redis_prefix = $data['redis_prefix'];
                $api_key = $data['api_key'];

                if ( $beanstalk_api_key !== $api_key )
                    throw new \Exception('Invalid API Key');

                //
                $current_time = new \DateTime();
                $output->writeln('AMCSD_4_CreateReferencesCommand (user '.$user_id.') ('.$redis_prefix.'): '.$current_time->format('Y-m-d H:i:s').' (UTC-5)');
                $logger->info('AMCSD_4_CreateReferencesCommand.php (user '.$user_id.') ('.$redis_prefix.'): creating references...');

                /** @var AMCSDUpdateService $amcsd_update_service */
                $amcsd_update_service = $container->get('odr.amcsd_update_service');
                $ret = $amcsd_update_service->createReferences($user_id, $output);

                if ( $ret === 'continue' ) {
                    // The service hasn't verified that all the references were created...release
                    //  the job so it can continue trying
                    $pheanstalk->release($job);
                }
                else if ( $ret === 'finished' ) {
                    // Dealt with the job
                    $output->writeln('done');
                    $logger->info('done');
                    $pheanstalk->delete($job);

                    // Insert an entry for the next job in the update chain
                    $priority = 1024;
                    $payload = json_encode($data);
                    $delay = 1;
                    $pheanstalk->useTube('amcsd_5_update')->put($payload, $priority, $delay);
                }

                // Sleep for a bit
                usleep(200000);
            }
            catch (\Exception $e) {
                if ( $e->getMessage() == 'retry' ) {
                    $output->writeln( 'Could not resolve host, releasing job to try again' );
                    $logger->err('AMCSD_4_CreateReferencesCommand.php: '.$e->getMessage());

                    // Release the job back into the ready queue to try again
                    $pheanstalk->release($job);

                    // Sleep for a bit
                    usleep(1000000);     // sleep for 1 second
                }
                else {
                    $output->writeln($e->getMessage());

                    $logger->err('AMCSD_4_CreateReferencesCommand.php: '.$e->getMessage());

                    // Delete the job so the queue doesn't hang, in theory
                    $pheanstalk->delete($job);
                }
            }
        }
    }
}
