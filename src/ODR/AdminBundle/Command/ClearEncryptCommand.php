<?php

/**
* Open Data Repository Data Publisher
* CacheFlush Command
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* This Symfony console command deletes encryption/decryption jobs
* given to beanstalk over the mass_encrypt tube.
*
*/

namespace ODR\AdminBundle\Command;

//use Symfony\Component\Console\Command\Command;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;


use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

// dunno if needed
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;

//class RefreshCommand extends Command
class ClearEncryptCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_encrypt:clear_encrypt')
            ->setDescription('Deletes all jobs from the mass_encrypt tube')
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
                $job = $pheanstalk->watch($memcached_prefix.'_mass_encrypt')->ignore('default')->reserve(); 
            else
                $job = $pheanstalk->watch('mass_encrypt')->ignore('default')->reserve(); 

            $data = json_decode($job->getData());
            $object_id = $data->object_id;
            $object_type = $data->object_type;
            $job_source = $data->memcached_prefix;

            // Dealt with the job
            $pheanstalk->delete($job);

if ($input->getOption('old'))
    $output->writeln( date('H:i:s').'  deleted job for '.$object_type.' '.$object_id.' from '.$memcached_prefix.'_mass_encrypt');
else
    $output->writeln( date('H:i:s').'  deleted job for '.$object_type.' '.$object_id.' ('.$job_source.') from mass_encrypt');

            // Sleep for a bit
            usleep(100000); // sleep for 0.1 seconds
        }

    }
}
