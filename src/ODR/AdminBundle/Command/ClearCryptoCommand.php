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
            ->addOption('old', null, InputOption::VALUE_NONE, 'If set, prepends the memcached_prefix to the tube name for deleting jobs');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Only need to load these once...
        $container = $this->getContainer();
        $pheanstalk = $container->get('pheanstalk');

        $memcached_prefix = $container->getParameter('memcached_key_prefix');

        while (true) {
            // Wait for a job?
            if ($input->getOption('old'))
                $job = $pheanstalk->watch($memcached_prefix.'_crypto_requests')->ignore('default')->reserve();
            else
                $job = $pheanstalk->watch('crypto_requests')->ignore('default')->reserve();

            $data = json_decode($job->getData());

if ($input->getOption('old'))
    $output->writeln( date('H:i:s').'  deleted '.$data->crypto_type.' job for '.$data->object_type.' '.$data->object_id.' from '.$data->memcached_prefix.'_crypto_requests');
else
    $output->writeln( date('H:i:s').'  deleted '.$data->crypto_type.' job for '.$data->object_type.' '.$data->object_id.' ('.$data->memcached_prefix.') from crypto_requests');

            // Dealt with the job
            $pheanstalk->delete($job);

            // Sleep for a bit
            usleep(100000); // sleep for 0.1 seconds
        }

    }
}
