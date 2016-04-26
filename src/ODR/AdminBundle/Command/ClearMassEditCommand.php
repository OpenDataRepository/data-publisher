<?php

/**
* Open Data Repository Data Publisher
* ClearMassEdit Command
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* This Symfony console command deletes beanstalk jobs from the
* mass_edit tube.
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
class ClearMassEditCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_record:clear_mass_edit')
            ->setDescription('Deletes all jobs from the mass_edit tube')
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
                $job = $pheanstalk->watch($memcached_prefix.'_mass_edit')->ignore('default')->reserve(); 
            else
                $job = $pheanstalk->watch('mass_edit')->ignore('default')->reserve(); 

            $data = json_decode($job->getData());
            $job_source = $data->memcached_prefix;

            $str = '';
            if ($data->job_type == 'public_status_change')
                $str = 'deleted public status change job for datarecord '.$job->datarecord_id;
            else if ($data->job_type == 'value_change')
                $str = 'deleted value change for datarecordfield '.$job->datarecordfield_id;

            // Dealt with the job
            $pheanstalk->delete($job);

if ($input->getOption('old'))
    $output->writeln( date('H:i:s').'  '.$str.' from '.$memcached_prefix.'_mass_edit');
else
    $output->writeln( date('H:i:s').'  '.$str.' ('.$job_source.') from mass_edit');

            // Sleep for a bit
            usleep(100000); // sleep for 0.1 seconds
        }

    }
}
