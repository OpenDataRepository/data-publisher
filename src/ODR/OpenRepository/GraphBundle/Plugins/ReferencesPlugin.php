<?php 

/**
* Open Data Repository Data Publisher
* References Plugin
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The references plugin renders data describing an academic
* reference in a single line, instead of scattered across a
* number of datafields.
*
*/

//  ODR/AdminBundle/Twig/GraphExtension.php;
namespace ODR\OpenRepository\GraphBundle\Plugins;

// use Symfony\Component\DependencyInjection\ContainerInterface as Container;
use Doctrine\ORM\EntityManger;
use Symfony\Component\Templating\EngineInterface;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeElementField;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\RenderPluginMap;
use ODR\AdminBundle\Entity\RenderPluginOptions;
use ODR\AdminBundle\Form\DatafieldsForm;
use ODR\AdminBundle\Form\DatatypeForm;
use ODR\AdminBundle\Form\UpdateDataFieldsForm;
use ODR\AdminBundle\Form\UpdateDataTypeForm;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TODO: short description.
 * 
 * TODO: long description.
 * 
 */
class ReferencesPlugin
{
    /**
     * TODO: description.
     * 
     * @var mixed
     */
    private $container;

    /**
     * TODO: description.
     * 
     * @var mixed
     */
    private $entityManager;

    /**
     * TODO: short description.
     * 
     * @param Container $container 
     * 
     * @return TODO
     */
    public function __construct($entityManager, $templating) {
        $this->em = $entityManager;
        $this->templating = $templating;
    }

    /**
     * TODO - 
     *
     * @param mixed $obj
     * @param RenderPlugin $render_plugin
     * @param boolean $public_only If true, don't render non-public items...if false, render everything
     *
     */
    public function execute($obj, $render_plugin, $public_only = false)
    {

        try {
            $em = $this->em;
            // $repo_plugin = $em->getRepository('ODR\AdminBundle\Entity\RenderPlugin');
            $repo_render_plugin_instance = $em->getRepository('ODRAdminBundle:RenderPluginInstance');
            $repo_render_plugin_map = $em->getRepository('ODRAdminBundle:RenderPluginMap');
            $repo_render_plugin_fields = $em->getRepository('ODRAdminBundle:RenderPluginFields');
//            $repo_render_plugin_options = $em->getRepository('ODRAdminBundle:RenderPluginOptions');
    
            $render_plugin_fields = $repo_render_plugin_fields->findBy( array('renderPlugin' => $render_plugin) );

            $render_plugin_instance = $repo_render_plugin_instance->findOneBy( array('renderPlugin' => $render_plugin, 'dataType' => $obj->getDataType()) );
            $render_plugin_map = $repo_render_plugin_map->findBy( array('renderPluginInstance' => $render_plugin_instance, 'dataType' => $obj->getDataType()) );

/*
            // Remap Options
            $plugin_options = array(); 
            foreach($render_plugin_options as $option) {
                if($option->getActive()) {
                    $plugin_options[$option->getOptionName()] = $option->getOptionValue();
                }
            }
*/

            // Map Fields
            $reference = array();
            foreach ($render_plugin_map as $rpm) {
                // Grab the fieldname specified in the plugin's config file to use as an array key
                $key = strtolower( str_replace(' ', '_', $rpm->getRenderPluginFields()->getFieldName()) );

                // Locate the correct DataRecordField entities for each of the required RenderPluginField entries
                foreach ($obj->getDataRecordFields() as $drf) {
                    if ($drf->getDataField()->getId() == $rpm->getDataField()->getId()) {
                        $reference[$key] = $drf;
                    }
                }
            }

            $output = $this->templating->render(
                'ODROpenRepositoryGraphBundle:References:references.html.twig', 
                array(
                    'ref' => $reference,
                    'public_only' => $public_only,
                )
            );

            return $output;
        }
        catch (\Exception $e) {
            return "<h2>An Exception Occurred</h2><p>" . $e->getMessage() . "</p>";
        }
    }

}
