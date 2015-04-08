<?php

/**
* Open Data Repository Data Publisher
* ClearType Command
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* This Symfony console command deletes beanstalk jobs that are
* created to rebuild memcached entries for all DataRecords of
* a DataType.
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
class ClearTypeCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_cache:clear_type')
            ->setDescription('Deletes all jobs from the recache_type tube')
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
                $job = $pheanstalk->watch($memcached_prefix.'_recache_type')->ignore('default')->reserve(); 
            else
                $job = $pheanstalk->watch('recache_type')->ignore('default')->reserve(); 

            $data = json_decode($job->getData());
            $datarecord_id = $data->datarecord_id;
            $job_source = $data->memcached_prefix;

            // Dealt with the job
            $pheanstalk->delete($job);

if ($input->getOption('old'))
    $output->writeln( date('H:i:s').'  deleted recache_all job for datarecord '.$datarecord_id.' from '.$memcached_prefix.'_recache_type');
else
    $output->writeln( date('H:i:s').'  deleted recache_all job for datarecord '.$datarecord_id.' ('.$job_source.') from recache_type');

            // Sleep for a bit
            usleep(50000); // sleep for 0.05 seconds
        }

    }
}
