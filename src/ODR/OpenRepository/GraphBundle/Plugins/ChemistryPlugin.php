<?php 

/**
* Open Data Repository Data Publisher
* Chemistry Plugin
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The chemistry plugin is designed to substitute certain
* characters in a datafield for html <sub> and <sup> tags,
* which allow the string to more closely resemble a chemical
* formula.
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
class ChemistryPlugin
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
     * TODO: short description.
     * 
     * @param DataRecordFields $obj
     * @param RenderPlugin $render_plugin
     * @param string? $mytheme
     * @param string $render_type
     * 
     * @return TODO
     */
    public function execute($obj, $render_plugin, $mytheme = "", $render_type = "default")
    {

        try {
            $em = $this->em;
            $repo_render_plugin_instance = $em->getRepository('ODRAdminBundle:RenderPluginInstance');
//            $repo_render_plugin_map = $em->getRepository('ODRAdminBundle:RenderPluginMap');
//            $repo_render_plugin_fields = $em->getRepository('ODRAdminBundle:RenderPluginFields');
            $repo_render_plugin_options = $em->getRepository('ODRAdminBundle:RenderPluginOptions');
    
//            $render_plugin_fields = $repo_render_plugin_fields->findBy( array('renderPlugin' => $render_plugin) );
            $render_plugin_instance = $repo_render_plugin_instance->findOneBy( array('renderPlugin' => $render_plugin, 'dataField' => $obj->getDataField()) );
//            $render_plugin_map = $repo_render_plugin_map->findBy( array('renderPluginInstance' => $render_plugin_instance, 'dataField' => $obj->getDataField()) );
            $render_plugin_options = $repo_render_plugin_options->findBy( array('renderPluginInstance' => $render_plugin_instance) );

            // Remap Options
            $options = array(); 
            foreach($render_plugin_options as $option) {
                if($option->getActive()) {
                    $options[$option->getOptionName()] = $option->getOptionValue();
                }
            }

            // Map Field
            switch($obj->getDataField()->getFieldType()->getTypeName()) {

                case 'Short Text':
                case 'Long Text':
                case 'Medium Text':
                case 'Paragraph Text':
                    $str = $obj->getAssociatedEntity()->getValue();
                break;

                default:
                    $str = '';
                break;
            }

            // No point running regexp if there's nothing in the string
            if (strcmp($str, ' ') == 0)
                return $str;

            $sub = "_";
            $super = "^";
            if (isset($options['subscript_delimiter']) && $options['subscript_delimiter'] != '') {
                $sub = $options['subscript_delimiter'];
            }
            else {
                $sub = "_";
            }
            if (isset($options['superscript_delimiter']) && $options['superscript_delimiter'] != '') {
                $super = $options['superscript_delimiter'];
            }
            else {
                $super = "^";
            }
            $sub = preg_quote($sub);
            $super = preg_quote($super);
            // Apply the superscripts...
            $str = preg_replace('/' . $sub . '([^' . $sub . ']+)' . $sub . '/', '<sub>$1</sub>', $str);
            
            // Apply the superscripts...
            $str = preg_replace('/' . $super . '([^' . $super . ']+)' . $super . '/', '<sup>$1</sup>', $str);
            
            // Redo the boxes...
            $str = preg_replace('/\[box\]/', '<span style="border: 1px solid #333; font-size:7px;">&nbsp;&nbsp;&nbsp;</span>', $str);


            $output = "";
            switch ($render_type) {
                case 'DisplayTemplate':
                break;

                case 'TextResults':
                    $output = $str;
                break;

                default:
                    $output = $this->templating->render(
                        'ODROpenRepositoryGraphBundle:Chemistry:chemistry_default.html.twig', 
                        array(
                            'field' => $obj->getDataField(),
                            'str' => $str,
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
