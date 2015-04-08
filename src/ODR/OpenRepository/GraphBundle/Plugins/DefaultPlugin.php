<?php 

/**
* Open Data Repository Data Publisher
* Default Plugin
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* TODO - why does this exist again?
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
class DefaultPlugin
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
    //public function __construct($entityManager, $templating) {
        //$this->em = $entityManager;
        //$this->templating = $templating;
    //}

    public function execute($obj, $render_plugin, $mytheme = "", $render_type = "default")
    {

        try {
            //$em = $this->em;
            //$repo_render_plugin_instance = $em->getRepository('ODRAdminBundle:RenderPluginInstance');
            //$repo_render_plugin_map = $em->getRepository('ODRAdminBundle:RenderPluginMap');
            //$repo_render_plugin_fields = $em->getRepository('ODRAdminBundle:RenderPluginFields');
            //$repo_render_plugin_options = $em->getRepository('ODR\AdminBundle\Entity\RenderPluginOptions');
    
            //$render_plugin_fields = $repo_render_plugin_fields->findBy( array('renderPlugin' => $render_plugin) );
            //$render_plugin_instance = $repo_render_plugin_instance->findOneBy( array('renderPlugin' => $render_plugin, 'dataField' => $obj->getDataField()) );
            //$render_plugin_map = $repo_render_plugin_map->findBy( array('renderPluginInstance' => $render_plugin_instance, 'dataField' => $obj->getDataField()) );
            //$render_plugin_options = $repo_render_plugin_options->findBy( array('renderPluginInstance' => $render_plugin_instance) );

            // Remap Options
            //$options = array(); 
            //foreach($render_plugin_options as $option) {
                //if($option->getActive()) {
                    //$options[$option->getOptionName()] = $option->getOptionValue();
                //}
            //}

            // if(!is_object($obj) || !method_exists($obj->getDataField())) {
            // Map Field
            switch($obj->getDataField()->getFieldType()->getTypeName()) {

                case 'Integer':
                    $str = $obj->getIntegerValue()->getValue();
                break;

                case 'Short Text':
                    $str = $obj->getShortVarChar()->getValue();
                break;

                case 'Long Text':
                    $str = $obj->getLongVarChar()->getValue();
                break;

                case 'Medium Text':
                    $str = $obj->getMediumVarChar()->getValue();
                break;

                case 'Paragraph Text':
                    $str = $obj->getLongText()->getValue();
                break;

                default:
                    $str = '';
                break;
            }

            $output = "";
            switch ($render_type) {

                case 'TextResults':
                    $output = $str;
                break;

                default:
                    $output = $this->templating->render(
                        'ODROpenRepositoryGraphBundle:Default:default_default.html.twig', 
                        array(
                            'mytheme' => $mytheme,
                            'field' => $obj->getDataField(),
                            'str' => $str,
                            'options' => $options
                        )
                    );
                break;
            }

            return $output;
        }
        catch (\Exception $e) {
            return "<h2>An Exception Occurred</h2><p>" . $e->getMessage() . "</p>";
        }
    }

}
