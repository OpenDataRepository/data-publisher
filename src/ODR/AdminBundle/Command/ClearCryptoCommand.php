<?php

/**
* Open Data Repository Data Publisher
* Clear Crypto Command
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* This Symfony console command deletes encryption/decryption jobs
* given to beanstalk over the crypto_requests tube.
*
*/

namespace ODR\AdminBundle\Command;

//use Symfony\Component\Console\Command\Command;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;


use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


class ClearCryptoCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_crypto:clear_worker')
            ->setDescription('Deletes all jobs from the crypto_requests tube')
            ->addOption('old', null, InputOption::VALUE_NONE, 'If set, prepends the redis_prefix to the tube name for deleting jobs');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Only need to load these once...
        $container = $this->getContainer();
        $pheanstalk = $container->get('pheanstalk');

        $redis_prefix = $container->getParameter('memcached_key_prefix');

        while (true) {
            // Wait for a job?
            if ($input->getOption('old'))
                $job = $pheanstalk->watch($redis_prefix.'_crypto_requests')->ignore('default')->reserve();
            else
                $job = $pheanstalk->watch('crypto_requests')->ignore('default')->reserve();

            $data = json_decode($job->getData());

            if ( $data->crypto_type !== 'encrypt' ) {
                if ($input->getOption('old'))
                    $output->writeln( date('H:i:s').'  deleted '.$data->crypto_type.' job for '.$data->object_type.' '.$data->object_id.' from '.$data->redis_prefix.'_crypto_requests');
                else
                    $output->writeln( date('H:i:s').'  deleted '.$data->crypto_type.' job for '.$data->object_type.' '.$data->object_id.' ('.$data->redis_prefix.') from crypto_requests');

                // Dealt with the job
                $pheanstalk->delete($job);

                // Sleep for a bit
                usleep(10000); // sleep for 0.01 seconds
            }
            else {
                $output->writeln( 'Encountered encrypt job, releasing to try again' );

                // Release the job back into the ready queue to try again
                $pheanstalk->release($job);

                // Sleep for a bit
                usleep(5000000);     // sleep for 5 seconds
            }
        }

    }
}
