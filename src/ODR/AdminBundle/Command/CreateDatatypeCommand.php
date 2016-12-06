<?php

/**
* Open Data Repository Data Publisher
* Crypto Command
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* This Symfony console command takes beanstalk jobs from the
* crypto_requests tube and passes the parameters to WorkerController
* to either encrypt a file/image entity, or decrypt a file entity.
*
*/

namespace ODR\AdminBundle\Command;

//use Symfony\Component\Console\Command\Command;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;


use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


class CreatedatatypeCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_datatype:create')
            ->setDescription('Creates a datatype from a pre-existing master template.');
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
                $job = $pheanstalk->watch('create_datatype')->ignore('default')->reserve();
                // Get the job data
                $data = json_decode($job->getData());

                // Get the Create Datatype Service
                $create_datatype_service = $this->getApplication()->getKernel()->getContainer()->get('odr.create_datatype_service');
                // $logger->info('CryptoCommand.php: '.$data->crypto_type.' request for '.$data->object_type.' '.$data->object_id.' from '.$data->redis_prefix.'...');


                $result = $create_datatype_service->createDatatypeFromMaster($data->datatype_id, $data->user_id);
                print $data->datatype_id . " ==> " . $result . "\n";
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
                    $logger->err('CryptoCommand.php: '.$e->getMessage());

                    // Release the job back into the ready queue to try again
                    $pheanstalk->release($job);

                    // Sleep for a bit
                    usleep(1000000);     // sleep for 1 second
                }
                else {
                    $output->writeln($e->getMessage());

                    $logger->err('CryptoCommand.php: '.$e->getMessage());

                    // Delete the job so the queue doesn't hang, in theory
                    $pheanstalk->delete($job);
                }
            }
        }
    }
}
