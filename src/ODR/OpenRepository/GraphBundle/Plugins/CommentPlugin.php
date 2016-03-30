<?php 

/**
* Open Data Repository Data Publisher
* Comments Plugin
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The comments plugin takes a specially designed child datatypes
* and collapses multiple datarecords of this child datatype into
* an html table, sorted by the date each child datarecord was
* created.
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
class CommentPlugin
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
    private $session;

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
    public function __construct($entityManager, $templating, $session) {
        $this->em = $entityManager;
        $this->templating = $templating;
        $this->session = $session;
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
            $repo_render_plugin_instance = $em->getRepository('ODRAdminBundle:RenderPluginInstance');
            $repo_render_plugin_map = $em->getRepository('ODRAdminBundle:RenderPluginMap');
            $repo_render_plugin_fields = $em->getRepository('ODRAdminBundle:RenderPluginFields');
            $repo_render_plugin_options = $em->getRepository('ODRAdminBundle:RenderPluginOptions');

            $render_plugin_fields = $repo_render_plugin_fields->findBy( array('renderPlugin' => $render_plugin) );

            // If an array is passed, use the first element.
            // This is a "child override" plugin instance
            $drc_group = array();
            if(is_array($obj)) {
                $drc_group = $obj;
                $obj = $obj[0];
            }

            $render_plugin_instance = $repo_render_plugin_instance->findOneBy( array('renderPlugin' => $render_plugin, 'dataType' => $obj->getDataType()) );
            $render_plugin_map = $repo_render_plugin_map->findBy( array('renderPluginInstance' => $render_plugin_instance, 'dataType' => $obj->getDataType()) );
            $render_plugin_options = $repo_render_plugin_options->findBy( array('renderPluginInstance' => $render_plugin_instance) );

            // Remap Options
            $plugin_options = array();
            foreach($render_plugin_options as $option) {
                if($option->getActive()) {
                    $plugin_options[$option->getOptionName()] = $option->getOptionValue();
                }
            }

            // ...more 'child override' stuff...
            $childtype_id = "";
            if(count($drc_group) == 0) {
                array_push($drc_group, $obj);
            }

            // Map Fields
            $count = 0;
            $comments = array();
            $datatype = null;
            foreach ($drc_group as $datarecordchild) {
                if ($datatype == null)
                    $datatype = $datarecordchild->getDataType();
                // 
                foreach ($render_plugin_map as $rpm) {
                    // Locate the correct DataRecordField entities for each of the required RenderPluginField entries
                    foreach ($datarecordchild->getDataRecordFields() as $drf) {
                        if ($drf->getDataField()->getId() == $rpm->getDataField()->getId()) {
                            // Use the date the entity was last modified as a sorting key
                            $count++;
                            $date = $drf->getAssociatedEntity()->getCreated()->format('Y-m-d');
                            $comments[$date.'_'.$count] = $drf;
                        }
                    }
                }
            }

            // Sort by date, most recent to least recent
            if ( count($comments) > 1 )
                krsort($comments);

            // Attempt to get whether somebody is logged in...
            $public_only = false;

            $user_permissions = $this->session->get('permissions');
            if ( $user_permissions == '' )  // when user not logged in, getting permissions results in an empty string it seems
                $public_only = true;

            $output = $this->templating->render(
                'ODROpenRepositoryGraphBundle:Comments:comments.html.twig', 
                array(
                    'shortname' => $datatype->getShortName(),
                    'comments' => $comments,
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
