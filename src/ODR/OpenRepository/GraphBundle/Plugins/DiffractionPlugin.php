<?php 

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
class DiffractionPlugin
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

    public function execute($obj, $render_plugin)
    {

        try {
            $em = $this->em;
            // $repo_plugin = $em->getRepository('ODR\AdminBundle\Entity\RenderPlugin');
            $repo_render_plugin_instance = $em->getRepository('ODR\AdminBundle\Entity\RenderPluginInstance');
            $repo_render_plugin_map = $em->getRepository('ODR\AdminBundle\Entity\RenderPluginMap');
            $repo_render_plugin_fields = $em->getRepository('ODR\AdminBundle\Entity\RenderPluginFields');
    
            // $render_plugin = $obj->getRenderPlugin();
    
            $render_plugin_instance = $repo_render_plugin_instance->findOneBy( array('renderPlugin' => $render_plugin, 'dataType' => $obj->getDataType()) );
            $render_plugin_map = $repo_render_plugin_map->findBy( array('renderPluginInstance' => $render_plugin_instance, 'dataType' => $obj->getDataType()) );
            $render_plugin_fields = $repo_render_plugin_fields->findBy( array('renderPlugin' => $render_plugin) );


            // Map Fields
            $template_fields = array();
            foreach($render_plugin_fields as $rp_field) {
                foreach($render_plugin_map as $map) {
                    if($map->getRenderPluginFields()->getId() == $rp_field->getId()) {
                        foreach($obj->getDataRecordFields() as $field) {
                            if($map->getDataField()->getId() == $field->getDataField()->getId()) {
                                $template_fields[strtolower(preg_replace("/\s/","_",$rp_field->getFieldName()))] = $field;
                            }
                        }
                    }
                }
            } 


            $graph_data = array();
            // Read Graph File to String Array
            // Ignore lines with #
            // Convert scientific notation to decimal?? 
            // foreach(

            // Check Format
    
            // Get Options
    
            // Generate graph
    
            $price = $obj->getId() . " -- " . $render_plugin->getPluginName() . " -- " . $obj->getDataType()->getShortName();
            $price = '$$'.$price . " -- " . implode(array_keys($template_fields), ","); //  . $render_plugin_instance->getId();
            $chart_id = "Chart_" . rand(1000000,9999999);
    
            $output = $this->templating->render(
                'ODROpenRepositoryGraphBundle:Diffraction:diffraction.html.twig', 
                array(
                    'price' => $price,
                    'chart_id' => $chart_id
                )
            );
    

            return $output;

        }
        catch (Exception $e) {
            return "<h2>An Exception Occurred</h2><p>" . $e->getMessage() . "</p>";
        }
    }

}
