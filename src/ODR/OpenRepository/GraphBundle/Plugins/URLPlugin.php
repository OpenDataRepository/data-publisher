<?php 

/**
* Open Data Repository Data Publisher
* URL Plugin
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The url plugin is designed to append the contents of a datafield
* to a "base" URL provided by the datatype designer...the generated
* HTML will look like
* 
* <a target="_blank" href="{{ baseurl }}{{ encoded datafield value }}">{{ datafield value }}</a>
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
class URLPlugin
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
            $repo_render_plugin_options = $em->getRepository('ODRAdminBundle:RenderPluginOptions');
    
            $render_plugin_instance = $repo_render_plugin_instance->findOneBy( array('renderPlugin' => $render_plugin, 'dataField' => $obj->getDataField()) );
            $render_plugin_options = $repo_render_plugin_options->findBy( array('renderPluginInstance' => $render_plugin_instance) );

            // Remap Options
            $options = array(); 
            foreach($render_plugin_options as $option) {
                if($option->getActive()) {
                    $options[$option->getOptionName()] = $option->getOptionValue();
                }
            }

            // Map Field
            $value = '';
            switch($obj->getDataField()->getFieldType()->getTypeName()) {
                case 'Short Text':
                case 'Long Text':
                case 'Medium Text':
                case 'Paragraph Text':
                    $value = $obj->getAssociatedEntity()->getValue();
                break;

                default:
                    $value = '';
                break;
            }

            // No point running regexp if there's nothing in the string
            $value = trim($value);
            if ( strlen($value) == 0 )
                return $value;

            // Grab baseurl for the link
//            $baseurl = $this->container->getParameter('site_baseurl');
            if ( isset($options['base_url']) && $options['base_url'] !== 'auto' )
                $baseurl = $options['base_url'];
            else
                throw new \Exception();

            // Escape the datafield's value 
            $encoded_value = urlencode($value);
            $str = '<a target="_blank" href="'.$baseurl.$encoded_value.'">'.$value.'</a>';

            $output = "";
            switch ($render_type) {
                case 'TextResults':
                    $output = $str;
                break;

                default:
                    $output = $this->templating->render(
                        'ODROpenRepositoryGraphBundle:URL:url_default.html.twig', 
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
