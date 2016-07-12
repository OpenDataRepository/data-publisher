<?php

/**
* Open Data Repository Data Publisher
* ClearXMLImportWorker Command
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* This Symfony console command deletes beanstalk jobs made to
* import the contents of XML files into a DataRecord entity on
* the server.
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


class ClearXMLImportWorkerCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_xml_import:clear_worker')
            ->setDescription('Deletes all jobs from the import_datarecord tube')
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
                $job = $pheanstalk->watch($redis_prefix.'_import_datarecord')->ignore('default')->reserve();
            else
                $job = $pheanstalk->watch('import_datarecord')->ignore('default')->reserve(); 

            $data = json_decode($job->getData());
            $datatype_id = $data->datatype_id;

            // Dealt with the job
            $pheanstalk->delete($job);

if ($input->getOption('old'))
    $output->writeln( date('H:i:s').'  deleted job for xml file "'.$data->xml_filename.'" of datatype '.$datatype_id.' from '.$redis_prefix.'_import_datarecord');
else
    $output->writeln( date('H:i:s').'  deleted job for xml file "'.$data->xml_filename.'" of datatype '.$datatype_id.' from import_datarecord');

            // Sleep for a bit
            usleep(100000); // sleep for 0.1 seconds
        }

    }
}
