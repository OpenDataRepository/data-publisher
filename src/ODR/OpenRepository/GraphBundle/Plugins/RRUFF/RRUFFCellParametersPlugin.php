<?php

/**
 * Open Data Repository Data Publisher
 * RRUFF Cell Parameters Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This stuff is an implementation of crystallographic space groups, whihc has been a "solved"
 *  problem since the 1890's.  Roughly speaking, it's a hierarchy where space group implies a point
 *  group, which implies a crystal system.  You can specify a crystal system without the other two,
 *  and can also specify a point group without a space group (very rare)...but it doesn't work in
 *  the other direction.
 *
 * The trick is that there is usually more than one way to "denote" a space group, depending on which
 *  of the crystal's axes you choose for a/b/c, and by extension alpha/beta/gamma. For instance,
 *  "P1", "A1", "B1", "C1", "I1", and "F1" are all ways to denote space group #1...but "P2" is the
 *   only valid way to denote space group #3.
 *
 * Attempting to use ODR's tag system is incredibly bulky and irritating...RRUFF had a system where
 *  selecting a crystal system filtered out the invalid point/space groups, and selecting a point
 *  group also filtered out the invalid spce groups.  This made it very difficult to screw up when
 *  entering data, while also making it easier to find what you wanted.
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\RRUFF;

// ODR
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
// Symfony
use Symfony\Bridge\Monolog\Logger;


class RRUFFCellParametersPlugin implements DatatypePluginInterface
{

    /**
     * @var Logger
     */
    private $logger;


    /**
     * RRUFF Cell Parameters Plugin constructor
     *
     * @param Logger $logger
     */
    public function __construct(
        Logger $logger
    ) {
        $this->logger = $logger;
    }


    /**
     * Returns whether the plugin can be executed in the current context.
     *
     * @param array $render_plugin_instance
     * @param array $datatype
     * @param array $rendering_options
     *
     * @return bool
     */
    public function canExecutePlugin($render_plugin_instance, $datatype, $rendering_options)
    {
        // The render plugin overrides how the user enters the crystal system, point group, and
        //  space group values...it also attempts to suggest volume if it can...
        if ( isset($rendering_options['context']) ) {
            $context = $rendering_options['context'];

            if ( $context === 'fake_edit'
                || $context === 'display'
                || $context === 'edit'
            ) {
                // ...so execute the render plugin when called from these contexts
                return true;

                // TODO - need to actually implement this though...
            }
        }

        return false;
    }


    /**
     * Executes the RRUFF Cell Parameters Plugin on the provided datarecord
     *
     * @param array $datarecords
     * @param array $datatype
     * @param array $render_plugin_instance
     * @param array $theme_array
     * @param array $rendering_options
     * @param array $parent_datarecord
     * @param array $datatype_permissions
     * @param array $datafield_permissions
     * @param array $token_list
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datarecords, $datatype, $render_plugin_instance, $theme_array, $rendering_options, $parent_datarecord = array(), $datatype_permissions = array(), $datafield_permissions = array(), $token_list = array())
    {

        try {
            // ----------------------------------------
            // If no rendering context set, then return nothing so ODR's default templating will
            //  do its job
            if (!isset($rendering_options['context']))
                return '';

            return '';
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }
}
