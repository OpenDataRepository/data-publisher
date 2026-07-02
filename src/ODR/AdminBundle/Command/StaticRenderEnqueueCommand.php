<?php

/**
 * Open Data Repository Data Publisher
 * StaticRenderEnqueue Command
 * (C) 2026 by Nathan Stone (nate.stone@opendatarepository.org)
 * Released under the GPLv2
 *
 * Enqueues static-render jobs for every public top-level record under a
 * given datatype. Workers are run separately via
 * background_services/static_render_daemon.js.
 *
 * Usage:
 *   php app/console odr_static_render:enqueue --datatype_id=738
 *   php app/console odr_static_render:enqueue --datatype_uuid=ddc5e9b...
 */

namespace ODR\AdminBundle\Command;

use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class StaticRenderEnqueueCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_static_render:enqueue')
            ->setDescription('Enqueues static-render jobs for every public top-level record of a datatype.')
            ->addOption('datatype_id', null, InputOption::VALUE_REQUIRED, 'Numeric datatype id')
            ->addOption('datatype_uuid', null, InputOption::VALUE_REQUIRED, 'Datatype UUID (alternative to --datatype_id)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Cap how many records to enqueue (default: all). Useful for smoke tests.', 0)
            ->addOption('purge-schemas', null, InputOption::VALUE_NONE, "Delete this datatype's cached schema.json before enqueueing so the daemon regenerates it.");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $container = $this->getContainer();
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $container->get('doctrine.orm.entity_manager');
        $static_render_service = $container->get('odr.static_render_service');

        $datatype_id = $input->getOption('datatype_id');
        $datatype_uuid = $input->getOption('datatype_uuid');

        if (empty($datatype_id) && empty($datatype_uuid)) {
            $output->writeln('<error>Must supply --datatype_id or --datatype_uuid.</error>');
            return 1;
        }

        $repo = $em->getRepository('ODR\AdminBundle\Entity\DataType');
        /** @var DataType $datatype */
        $datatype = null;
        if (!empty($datatype_id))
            $datatype = $repo->find((int)$datatype_id);
        if ($datatype === null && !empty($datatype_uuid))
            $datatype = $repo->findOneBy(array('unique_id' => $datatype_uuid));

        if ($datatype === null || $datatype->getDeletedAt() !== null) {
            $output->writeln('<error>Datatype not found.</error>');
            return 1;
        }

        $limit = (int)$input->getOption('limit');
        if ($limit < 0)
            $limit = 0;

        // Optionally drop the cached schema so the daemon re-fetches it.
        // The daemon skips the schema fetch when schema.json already
        // exists, so without this a schema change would never propagate.
        if ($input->getOption('purge-schemas')) {
            $purged = $static_render_service->purgeSchema($datatype->getUniqueId());
            $output->writeln($purged
                ? sprintf('Purged cached schema for datatype %s.', $datatype->getUniqueId())
                : sprintf('No cached schema to purge for datatype %s.', $datatype->getUniqueId())
            );
        }

        $output->writeln(sprintf(
            'Enqueueing public records for datatype %d (%s)%s...',
            $datatype->getId(),
            $datatype->getUniqueId(),
            $limit > 0 ? sprintf(' (limit=%d)', $limit) : ''
        ));

        $count = $static_render_service->enqueueDatatype($datatype, $limit);

        $output->writeln(sprintf('Queued %d record(s) on tube "%s".', $count, \ODR\AdminBundle\Component\Service\StaticRenderService::TUBE_NAME));
        return 0;
    }
}
