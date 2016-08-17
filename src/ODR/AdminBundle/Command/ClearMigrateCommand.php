<?php

/**
* Open Data Repository Data Publisher
* ClearMigrate Command
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* This Symfony console command deletes beanstalk jobs from the
* migrate_datafields tube.
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
class ClearMigrateCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_record:clear_migrate')
            ->setDescription('Deletes all jobs from the migrate_datafields tube')
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
                $job = $pheanstalk->watch($redis_prefix.'_migrate_datafields')->ignore('default')->reserve();
            else
                $job = $pheanstalk->watch('migrate_datafields')->ignore('default')->reserve(); 

            $data = json_decode($job->getData());
            $datafield_id = $data->datafield_id;
            $datarecord_id = $data->datarecord_id;
            $job_source = $data->redis_prefix;

            // Dealt with the job
            $pheanstalk->delete($job);

if ($input->getOption('old'))
    $output->writeln( date('H:i:s').'  deleted job for datarecord '.$datarecord_id.', datafield '.$datafield_id.' from '.$redis_prefix.'_migrate_datafields');
else
    $output->writeln( date('H:i:s').'  deleted job for datarecord '.$datarecord_id.', datafield '.$datafield_id.' ('.$job_source.') from migrate_datafields');

            // Sleep for a bit
            usleep(100000); // sleep for 0.1 seconds
        }

    }
}
