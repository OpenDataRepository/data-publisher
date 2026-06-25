<?php

/**
 * Open Data Repository Data Publisher
 * ContainerAwareCommand (base)
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Symfony 5 removed Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand. ODR's ~46
 * console commands relied on it for $this->getContainer(). This thin base restores that: it extends
 * the Console Command and receives the service container via setContainer() (wired in the command
 * service registrations), so the existing getContainer()->get('...') calls keep working unchanged.
 */

namespace ODR\AdminBundle\Command;

use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;

abstract class ContainerAwareCommand extends Command
{
    /** @var ContainerInterface|null */
    protected $container;

    public function setContainer(?ContainerInterface $container = null): void
    {
        $this->container = $container;
    }

    /**
     * @return ContainerInterface
     */
    protected function getContainer()
    {
        return $this->container;
    }
}
