<?php

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeElementField;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Form\DatafieldsForm;
use ODR\AdminBundle\Form\DatatypeForm;
use ODR\AdminBundle\Form\UpdateDataFieldsForm;
use ODR\AdminBundle\Form\UpdateDataTypeForm;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

// YAML Parsing
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

class PluginsController extends ODRCustomController
{

    /**
     * TODO: short description.
     * 
     * @return TODO
     */
    public function plugintestAction() {

        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";


        try {

            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            // ...
            $is_admin = 1;

            // Get Plugin List
            $plugins = $this->container->getParameter('odr_plugins');

            // Get Templating Object
            $templating = $this->get('templating');

            // $em = $this->getDoctrine()->getManager();
            // Build the list of datatypes
            $html =  $templating->render(
                'ODRAdminBundle:Plugins:plugintest_ajax.html.twig',
                array(
                    'the_extension' => "\ODR\OpenRepository\GraphBundle\Plugins\GraphPlugin",
                    'the_filter' => 'execute',
                    'user' => $user
                )
            );

            $return['d'] = array(
                'html' => $html
            );

            // Get Default Theme
        }
        catch(Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x2939185899 ' . $e->getMessage();
        }


        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }

    /**
     * Defines the plugin directory path.
     * 
     * @var mixed  Defaults to __DIR__ . "/../Plugins". 
     */
    // private $plugin_path = __DIR__ . "/../Plugins";

    public function manageAction() {

//        $plugin_path = __DIR__ . "/../Plugins";
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";
        
        try {
            // Get available plugins
            $plugin_list = $this->container->getParameter('odr_plugins');
/*
            // Get available plugins
            $plugin_list = scandir($plugin_path);        
            
            $plugins = array();
            foreach($plugin_list as $plugin) {
                if(preg_match("/\.php$/", $plugin)) {
                    array_push($plugins, preg_replace("/\.php$/", "", $plugin));
                }
            }
*/

            // Parse plugin options
            $plugin_options = array();
            foreach($plugin_list as $plugin) {
                $plugin_name = $plugin['filename'];
                $file = $plugin_name . ".yml";
                try {
                    $yaml = Yaml::parse(file_get_contents('../src' . $plugin['bundle_path'] . '/Plugins/' . $file));
                    $plugin_options[$plugin_name] = $yaml;
                } catch (ParseException $e) {
                    throw new Exception("Unable to parse the YAML string: %s", $e->getMessage());
                }
            }

/*
            // Parse plugin options
            $plugin_options = array();
            foreach($plugins as $plugin) {
                $file = $plugin . ".yml";
                try {
                    $yaml = Yaml::parse(file_get_contents($plugin_path . "/" . $file));
                    $plugin_options[$plugin] = $yaml;
                } catch (ParseException $e) {
                    throw new Exception("Unable to parse the YAML string: %s", $e->getMessage());
                }
            }
*/
            $em = $this->getDoctrine()->getManager();
            $repo_plugin = $em->getRepository('ODR\AdminBundle\Entity\RenderPlugin');

            $plugin_instances = $repo_plugin->findAll();

            $templating = $this->get('templating');
            $html =  $templating->render(
                'ODRAdminBundle:Plugins:plugin_list.html.twig',
                array(
                    'plugin_list' => $plugin_list,
                    'plugin_options' => $plugin_options,
                    'plugins' => $plugin_instances,
                    'metadata' => array(),
                )
            );

            $return['d'] = array(
                'html' => $html
            );

            $em->flush();
        }
        catch(Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x923828188 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }

    public function addAction($plugin_name) {

        $plugin_path = __DIR__ . "/../Plugins";
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";
        
        try {
            // Get available plugins (from Parameters)
            $plugin_list = scandir($plugin_path);        
            
            $found = false;
            $the_plugin = '';
            foreach($plugin_list as $plugin) {
                if(preg_match("/\.php$/", $plugin)) {
                    $plugin = preg_replace("/\.php$/", "", $plugin);
                    if(strtolower($plugin) == strtolower($plugin_name)) {
                        $the_plugin = $plugin;
                        $found = true;
                    }
                }
            }
    
            if($found) {
                throw new Exception("The requested plugin type was not found.");
            }

            // Parse plugin options
            $plugin_options = array();
            $file = $the_plugin . ".yml";
            try {
                $yaml = Yaml::parse(file_get_contents($plugin_path . "/" . $file));
                $plugin_options = $yaml;
            } catch (ParseException $e) {
                throw new Exception("Unable to parse the YAML string: %s", $e->getMessage());
            }
 
            // $return['d'] = "<pre>" . var_export($plugin_options) . "</pre>";
    
            // $em = $this->getDoctrine()->getManager();
            // $repo_plugin_fields = $em->getRepository('ODR\AdminBundle\Entity\RenderPluginFields');
            // $repo_plugin_map = $em->getRepository('ODR\AdminBundle\Entity\RenderPluginMap');

            // Load New Plugin Form with Claass Name
            $new_plugin = new RenderPlugin();
            $new_plugin->setPluginClass($the_plugin);

            $form = $this->createForm(new RenderPluginForm(), $new_plugin);

            $templating = $this->get('templating');
            $html =  $templating->render(
                'ODRAdminBundle:Plugins:plugin_form.html.twig',
                array(
                    'plugin' => $the_plugin,
                    'metadata' => array(),
                )
            );

            $return['d'] = array(
                'html' => $html
            );

            $em->flush();
        }
        catch(Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x1239202098 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }

    public function mapAction($plugin_id) {

//        $plugin_path = __DIR__ . "/../Plugins";
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";
        
        try {
            // Get available plugins
            $plugin_list = $this->container->getParameter('odr_plugins');
/*
            // Get available plugins
            $plugin_list = scandir($plugin_path);        
            
            $plugins = array();
            foreach($plugin_list as $plugin) {
                if(preg_match("/\.php$/", $plugin)) {
                    array_push($plugins, preg_replace("/\.php$/", "", $plugin));
                }
            }
*/

            // Parse plugin options
            $plugin_options = array();
            foreach($plugin_list as $plugin) {
                $plugin_name = $plugin['filename'];
                $file = $plugin_name . ".yml";
                try {
                    $yaml = Yaml::parse(file_get_contents('../src' . $plugin['bundle_path'] . '/Plugins/' . $file));
                    $plugin_options[$plugin_name] = $yaml;
                } catch (ParseException $e) {
                    throw new Exception("Unable to parse the YAML string: %s", $e->getMessage());
                }
            }

/*
            // Parse plugin options
            $plugin_options = array();
            foreach($plugins as $plugin) {
                $file = $plugin . ".yml";
                try {
                    $yaml = Yaml::parse(file_get_contents($plugin_path . "/" . $file));
                    $plugin_options[$plugin] = $yaml;
                } catch (ParseException $e) {
                    throw new Exception("Unable to parse the YAML string: %s", $e->getMessage());
                }
            }
*/
 
            // $return['d'] = "<pre>" . var_export($plugin_options) . "</pre>";
    
            $em = $this->getDoctrine()->getManager();
            $repo_plugin = $em->getRepository('ODR\AdminBundle\Entity\RenderPlugin');
            // $repo_plugin_fields = $em->getRepository('ODR\AdminBundle\Entity\RenderPluginFields');
            // $repo_plugin_map = $em->getRepository('ODR\AdminBundle\Entity\RenderPluginMap');

            $plugin_instances = $repo_plugin->findAll();

            // $em->remove($datafields);
            // $em->remove($theme_element);

            $templating = $this->get('templating');
            $html =  $templating->render(
                'ODRAdminBundle:Plugins:plugin_list.html.twig',
                array(
                    'plugins' => $plugin_instances,
                    'metadata' => array(),
                )
            );

            $return['d'] = array(
                'html' => $html
            );

            $em->flush();
        }
        catch(Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x1239202098 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }


    public function optionsAction($plugin_id) {

//        $plugin_path = __DIR__ . "/../Plugins";
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";
        
        try {
            // Get available plugins
            $plugin_list = $this->container->getParameter('odr_plugins');
/*
            // Get available plugins
            $plugin_list = scandir($plugin_path);        
            
            $plugins = array();
            foreach($plugin_list as $plugin) {
                if(preg_match("/\.php$/", $plugin)) {
                    array_push($plugins, preg_replace("/\.php$/", "", $plugin));
                }
            }
*/

            // Parse plugin options
            $plugin_options = array();
            foreach($plugin_list as $plugin) {
                $plugin_name = $plugin['filename'];
                $file = $plugin_name . ".yml";
                try {
                    $yaml = Yaml::parse(file_get_contents('../src' . $plugin['bundle_path'] . '/Plugins/' . $file));
                    $plugin_options[$plugin_name] = $yaml;
                } catch (ParseException $e) {
                    throw new Exception("Unable to parse the YAML string: %s", $e->getMessage());
                }
            }

/*
            // Parse plugin options
            $plugin_options = array();
            foreach($plugins as $plugin) {
                $file = $plugin . ".yml";
                try {
                    $yaml = Yaml::parse(file_get_contents($plugin_path . "/" . $file));
                    $plugin_options[$plugin] = $yaml;
                } catch (ParseException $e) {
                    throw new Exception("Unable to parse the YAML string: %s", $e->getMessage());
                }
            }
*/
 
    
            $em = $this->getDoctrine()->getManager();
            $repo_plugin = $em->getRepository('ODR\AdminBundle\Entity\RenderPlugin');
            // $repo_plugin_fields = $em->getRepository('ODR\AdminBundle\Entity\RenderPluginFields');
            // $repo_plugin_map = $em->getRepository('ODR\AdminBundle\Entity\RenderPluginMap');

            $plugin_instances = $repo_plugin->findAll();

            $templating = $this->get('templating');
            $html =  $templating->render(
                'ODRAdminBundle:Plugins:plugin_list.html.twig',
                array(
                    'plugins' => $plugin_instances,
                    'metadata' => array(),
                )
            );

            $return['d'] = "<pre>" . var_export($plugin_options) . "</pre>";

            $return['d'] = array(
                'html' => $html
            );

            $em->flush();
        }
        catch(Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x1823839928 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }

/*
    public function testsdAction() {

        $em = $this->getDoctrine()->getManager();
        $em->getEventManager()->addEventSubscriber(new \Gedmo\SoftDeleteable\SoftDeleteableListener());
        $em->getFilters()->enable('softdeleteable');
        $article = new DataType();
        $article->setShortName('my title test 99softdelete');
        $article->setLongName("New Child");
        $article->setDescription("New Child Type");
        $article->setMultipleRecordsPerParent('1');
        $article->setPublicDate(new \DateTime('1980-01-01 00:00:00'));
        $em->persist($article);
        $em->flush();

        $em->remove($article);
        $em->flush();

        $repo = $em->getRepository('ODR\AdminBundle\Entity\DataType');
        $art = $repo->findOneBy(array('shortName' => 'my title test 99softdelete'));

        // It should NOT return the article now
        if(null == $art) {
            print "NULL Article";
        }
        else {
            print "Article found";
        }


        // But if we disable the filter, the article should appear now
        $em->getFilters()->disable('softdeleteable');

        $art = $repo->findOneBy(array('shortName' => 'my title test 99softdelete'));

        if(null != $art) {
            print "Article found";
        }
        else {
            print "NULL Article";
        }

    }
*/
/*
    public function testlogAction() {

        $em = $this->getDoctrine()->getManager();
        $article = new DataType();
        $article->setShortName('my title test');
        $article->setLongName("New Child");
        $article->setDescription("New Child Type");
        $article->setMultipleRecordsPerParent('1');
        $article->setPublicDate(new \DateTime('1980-01-01 00:00:00'));
        $em->persist($article);
        $em->flush();
        
        $article = $em->find('ODR\AdminBundle\Entity\DataType', 8 );
        $article->setShortName('my new title');
        $em->persist($article);
        $em->flush();
        
        $repo = $em->getRepository('Gedmo\Loggable\Entity\LogEntry'); // we use default log entry class
        $article = $em->find('ODR\AdminBundle\Entity\DataType', 8 );
        $logs = $repo->getLogEntries($article);
        /* $logs contains 2 logEntries 
        echo count($logs) . "<br />";
        // lets revert to first version
        $repo->revert($article, 1 );
        // notice article is not persisted yet, you need to persist and flush it
        echo $article->getShortName(); // prints "my title"
        $em->persist($article);
        $em->flush();
    }
*/

    public function deletefieldAction($data_fields_id)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            $em = $this->getDoctrine()->getManager();
            $repo = $em->getRepository('ODR\AdminBundle\Entity\DataFields');
            $repo_theme_elements = $em->getRepository('ODR\AdminBundle\Entity\ThemeElementField');

            $datafields = $repo->find($data_fields_id);
            $theme_element = $repo_theme_elements->findOneBy( array('dataFields' => $datafields) );

            $em->remove($datafields);
            $em->remove($theme_element);

            $em->flush(); 
        }
        catch(Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x18392883 ' . $e->getMessage();
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;  

    }

    public function deleteoptionAction($radio_option_id)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            $em = $this->getDoctrine()->getManager();
            $radio_option = $em->getRepository('ODR\AdminBundle\Entity\RadioOptions')->find( $radio_option_id );

            $em->remove($radio_option);

            $em->flush();

            // Invalidate the cache
            $memcached = $this->get('memcached');
            $memcached->flush();
        }
        catch(Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x18392884444 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }


    public function deletetypeAction($data_type_id)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            //
            $em = $this->getDoctrine()->getManager();
            $user = $this->container->get('security.context')->getToken()->getUser();

            // First, delete the actual datatype itself
            $repo = $em->getRepository('ODR\AdminBundle\Entity\DataType');
            $datatype = $repo->find($data_type_id);
            $em->remove($datatype);

            // Second, remove the child/parent links from the datatree
            $childtree = $this->getDoctrine()
                ->getRepository('ODRAdminBundle:DataTree')
                ->findByAncestor($datatype);

            // Need to make this recurse
            foreach($childtree as $branch) {
                $em->remove($branch);
            }
            $parenttree = $this->getDoctrine()
                ->getRepository('ODRAdminBundle:DataTree')
                ->findByDescendant($datatype);

            // Need to make this recurse
            foreach($parenttree as $branch) {
                $em->remove($branch);
            }

            // Third, remove the theme element so it won't show up in Results/Records anymore
            $repo_theme_data_type = $em->getRepository('ODR\AdminBundle\Entity\ThemeDataType');
            $theme_data_type = $repo_theme_data_type->findOneBy( array("dataType" => $datatype->getId(), "theme" => 1) );
            $em->remove($theme_data_type);

            // Fourth, also remove the theme element field, so the templating doesn't attempt to access the deleted datatype
            $repo_theme_element_field = $em->getRepository('ODR\AdminBundle\Entity\ThemeElementField');
            $theme_element_fields = $repo_theme_element_field->findBy( array("dataType" => $datatype->getId()) );
            foreach ($theme_element_fields as $theme_element_field)
                $em->remove($theme_element_field);

            // Commit Deletes
            $em->flush(); 

            // Flush memcached stuff
            $memcached = $this->get('memcached');
            $memcached->flush();
        }
        catch(Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x1883778 ' . $e->getMessage();
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;  

    }

    public function getchildAction($data_type_id)
    {

        // Get Default Theme
        $theme = $this->getDoctrine()
            ->getRepository('ODRAdminBundle:Theme')
            ->findOneBy(array("isDefault" => "1"));

        // Doctrine\ORM\Query::HYDRATE_ARRAY

        $datatype = $this->getDoctrine()
            ->getRepository('ODRAdminBundle:DataType')
            ->find($data_type_id);

        print self::GetDisplayData($datatype, 'child');
        exit();
        // return $output;

    }

    public function designAction($data_type_id)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";


        try {

            // Get Default Theme
            $theme = $this->getDoctrine()
                ->getRepository('ODRAdminBundle:Theme')
                ->findOneBy(array("isDefault" => "1"));
    
            // Doctrine\ORM\Query::HYDRATE_ARRAY
    
            $datatype = $this->getDoctrine()
                ->getRepository('ODRAdminBundle:DataType')
                ->find($data_type_id);
    
            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'html' => self::GetDisplayData($datatype)
            );
        }
        catch(Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x38288399 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }

    private function checkDataTypeTheme($datatype, $theme) {
        $em = $this->getDoctrine()->getManager();
        $themes = $datatype->getThemeDataType();
        if(count($themes) < 1) {
            self::addDataTypeThemeEntry($em, $datatype, $theme);
        }
        else {
            $found = false;
            foreach($themes as $mytheme) {
                if($mytheme->getTheme()->getId() == $theme->getId()) {
                    $found = true;
                    break;
                } 
            }
            if(!$found) {
                self::addDataTypeThemeEntry($em, $datatype, $theme);
            }
        }
        $em->flush();
    }

    private function getChildren($childtype, $child_type_array, $found_ancestors) {
        // Need to make this recurse
        if(!in_array($childtype->getAncestor()->getId() . "_" . $childtype->getDescendant()->getId(), $found_ancestors)) {
            $child_type_array[$childtype->getAncestor()->getId() . "_" . $childtype->getDescendant()->getId()] = $childtype->getDescendant();
            $my_childtypes = $this->getDoctrine()
                ->getRepository('ODRAdminBundle:DataTree')
                ->findByAncestor($childtype->getDescendant());
            foreach($my_childtypes as $my_childtype) {
                $child_type_array = self::getChildren($my_childtype, $child_type_array, $found_ancestors);
            }
        }
        return $child_type_array;
    }

    private function checkFieldTheme($datafields, $theme) {
        $em = $this->getDoctrine()->getManager();
        foreach($datafields as $df) {
            $themes = $df->getThemeDataField();
            if(count($themes) < 1) {
                self::addFieldThemeEntry($em, $df, $theme);
            }
            else {
                $found = false;
                foreach($themes as $mytheme) {
                    if($mytheme->getTheme()->getId() == $theme->getId()) {
                        $found = true;
                        break;
                    } 
                }
                if(!$found) {
                    self::addFieldThemeEntry($em, $df, $theme);
                }
            }
        }
        $em->flush();
    }

    private function addFieldThemeEntry($em, $field, $theme) {
        // Create theme entry
        $mytheme = new ThemeDataField();
        $mytheme->setDataFields($field);
        $mytheme->setTheme($theme);
        $mytheme->setTemplateType('form');
        $mytheme->setXpos('0');
        $mytheme->setYpos('0');
        $mytheme->setZpos('0');
        $mytheme->setWidth('200');
        $mytheme->setHeight('0');
        $mytheme->setCSS('');
        $em->persist($mytheme);
    }

    private function addDataTypeThemeEntry($em, $datatype, $theme) {
        // Create theme entry
        $mytheme = new ThemeDataType();
        $mytheme->setDataType($datatype);
        $mytheme->setTheme($theme);
        $mytheme->setTemplateType('form');
        $mytheme->setXpos('0');
        $mytheme->setYpos('0');
        $mytheme->setZpos('0');
        $mytheme->setWidth('600');
        $mytheme->setHeight('300');
        $mytheme->setCSS('');
        $em->persist($mytheme);
    }

    public function themeelementorderAction($theme_element_ids)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            $em = $this->getDoctrine()->getManager();
            $theme_element_ids = preg_split("/,/", $theme_element_ids);
            for($i=0;$i<count($theme_element_ids);$i++) {
                $themeelement = $this->getDoctrine()
                    ->getRepository('ODRAdminBundle:ThemeElement')
                    ->findOneBy(array("id" => $theme_element_ids[$i]));
    
                $themeelement->setDisplayOrder($i);
    
                $em->persist($themeelement);
            }
            $em->flush();

        }
        catch(Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x8283002 ' . $e->getMessage();
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;  
    }
    public function themeelementAction($theme_element_id, $width, $height, $xpos, $ypos, $zpos)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        // Clean Data
        if($zpos == "auto") $zpos = 0;

        try {
            $themeelement = $this->getDoctrine()
                ->getRepository('ODRAdminBundle:ThemeElement')
                ->findOneBy(array("id" => $theme_element_id));

            $themeelement->setWidth($width);
            $themeelement->setHeight($height);
            $themeelement->setXpos($xpos);
            $themeelement->setYpos($ypos);
            $themeelement->setZpos($zpos);

            $em = $this->getDoctrine()->getManager();
            $em->persist($themeelement);
            $em->flush();

        }
        catch(Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x77389299 ' . $e->getMessage();
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;  
    }

    public function deletethemeelementAction($theme_element_id)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Current User
            $user = $this->container->get('security.context')->getToken()->getUser();

            // Determine if the theme element holds anything
            $theme_element_fields = $this->getDoctrine()->getRepository('ODRAdminBundle:ThemeElementField')->findBy( array("themeElement" => $theme_element_id) );
            if ( count($theme_element_fields) == 0 ) {
                // Grab the theme element from the repository
                $themeelement = $this->getDoctrine()
                    ->getRepository('ODRAdminBundle:ThemeElement')
                    ->findOneBy(array("id" => $theme_element_id));

                $em = $this->getDoctrine()->getManager();
                $em->remove($themeelement);
                $em->flush();
            }
            else {
                // Notify of inability to remove this theme element
                $return['r'] = 1;
                $return['t'] = 'error';
                $return['d'] = "This ThemeElement still has children, so it can't be removed!";
            }
        }
        catch(Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x77392699 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

    public function themedatatypeAction($theme_id, $width, $height, $xpos, $ypos, $zpos)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        // Clean Data
        if($zpos == "auto") $zpos = 0;

        try {
            $themefield = $this->getDoctrine()
                ->getRepository('ODRAdminBundle:ThemeDataType')
                ->findOneBy(array("id" => $theme_id));

            $themefield->setWidth($width);
            $themefield->setHeight($height);
            $themefield->setXpos($xpos);
            $themefield->setYpos($ypos);
            $themefield->setZpos($zpos);

            $em = $this->getDoctrine()->getManager();
            $em->persist($themefield);
            $em->flush();

        }
        catch(Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x77389299 ' . $e->getMessage();
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;  
    }

    public function themefieldAction($theme_id, $width, $height, $xpos, $ypos, $zpos, $field_width, $field_height, $label_width)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        // Clean Data
        if($zpos == "auto") $zpos = 0;

        try {
            $themefield = $this->getDoctrine()
                ->getRepository('ODRAdminBundle:ThemeDataField')
                ->findOneBy(array("id" => $theme_id));

            $themefield->setWidth($width);
            $themefield->setHeight($height);
            $themefield->setXpos($xpos);
            $themefield->setYpos($ypos);
            $themefield->setZpos($zpos);
            $themefield->setFieldWidth($field_width);
            $themefield->setFieldHeight($field_height);
            $themefield->setLabelWidth($label_width);

            $em = $this->getDoctrine()->getManager();
            $em->persist($themefield);
            $em->flush();

            // Since a field got changed, invalidate the cache?
            $memcached = $this->get('memcached');
            $memcached->flush();

        }
        catch(Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x82399100 ' . $e->getMessage();
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;  
    }

    public function navAction($data_type_id)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            $datatype = $this->getDoctrine()
                ->getRepository('ODRAdminBundle:DataType')
                ->find($data_type_id);

            $datafields = $this->getDoctrine()
                ->getRepository('ODRAdminBundle:DataFields')
                ->findByDataType($data_type_id);

            $templating = $this->get('templating');
            $return['t'] = "html";
            $return['d'] = $templating->render(
                'ODRAdminBundle:Displaytemplate:nav_ajax.html.twig', 
                array(
                    'datatype' => $datatype,
                    'datafields' => $datafields,
                )
            );
        }
        catch(Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x82394557 ' . $e->getMessage();
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;  
    }

/*
    public function listcontentAction(Request $request)
    {
        $datafields = $this->getDoctrine()
            ->getRepository('ODRAdminBundle:DataFields')
            ->findAll();

        // Poplulate new DataFields form
        $datafield = new DataFields();
        $user = $this->container->get('security.context')->getToken()->getUser();
        $datafield->setCreatedBy($user);
        $datafield->setUpdatedBy($user);

        return $this->render(
            'ODRAdminBundle:Datafields:type_list_content.html.twig', 
            array(
                'datafields' => $datafields
            )
        );
    }
*/
/*
    public function searchAction()
    {
        return $this->render('ODRAdminBundle:Default:index.html.twig', array());
    }
*/

    public function addgroupboxAction($data_type_id, Request $request)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            // Get Current User
            $user = $this->container->get('security.context')->getToken()->getUser();

            $em = $this->getDoctrine()->getManager();
            $repo = $em->getRepository('ODR\AdminBundle\Entity\DataType');
            $datatype = $repo->find($data_type_id);

            $theme = $this->getDoctrine()
            ->getRepository('ODRAdminBundle:Theme')
            ->findOneBy(array("isDefault" => "1"));

            $themeelement = new ThemeElement();
            $themeelement->setDataType($datatype);
            $themeelement->setCreatedBy($user);
            $themeelement->setUpdatedBy($user);
            $themeelement->setTemplateType('form');
            $themeelement->setElementType('div');
            $themeelement->setXpos(0);
            $themeelement->setYpos(0);
            $themeelement->setZpos(0);
            $themeelement->setWidth(800);
            $themeelement->setHeight(300);
            $themeelement->setFieldWidth(0);
            $themeelement->setFieldHeight(0);
            $themeelement->setDisplayOrder(-1);
            $themeelement->setTheme($theme);

            $em->persist($themeelement);

            $em->flush();

            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'fieldarea_html' => self::GetDisplayData($datatype, 'field_area')
            );
        }
        catch(Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x88320029 ' . $e->getMessage();
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;  
    }


    public function addradiooptionAction($data_type_id, $data_field_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            // Get current user
            $user = $this->container->get('security.context')->getToken()->getUser();

            $em = $this->getDoctrine()->getManager();
            $repo = $em->getRepository('ODR\AdminBundle\Entity\DataFields');
            $datafield = $repo->find($data_field_id);

            $repo = $em->getRepository('ODR\AdminBundle\Entity\DataType');
            $datatype = $repo->find($data_type_id);

            $repo = $em->getRepository('ODR\AdminBundle\Entity\FieldType');
            $fieldtype = $repo->find('8');  // TODO - probably need to delete this, doesn't matter to a radio option

            $repo = $em->getRepository('ODR\AdminBundle\Entity\RenderPlugin');
            $renderplugin = $repo->find('1');

            // Poplulate new DataFields form
            $radio_option = new RadioOptions();
            $radio_option->setValue(0);
            $radio_option->setOptionName("Option");
            $radio_option->setDisplayOrder(0);
            $radio_option->setDataFields($datafield);
//            $radio_option->setFieldType($fieldtype);
            $radio_option->setCreatedBy($user);
            $radio_option->setUpdatedBy($user);

            $em->persist($radio_option);

/*
            // Tie Field to ThemeElement
            $themeelement = $this->getDoctrine()
                ->getRepository('ODRAdminBundle:ThemeElement')
                ->findOneBy(array("id" => $theme_element_id));

            $themeelementfield = new ThemeElementField();
            $themeelementfield->setCreatedBy($user);
            $themeelementfield->setUpdatedBy($user);
            $themeelementfield->setDataFields($datafield);
            $themeelementfield->setThemeElement($themeelement);

            $em->persist($themeelementfield);
*/

            $em->flush();

            // Invalidate the cache
            $memcached = $this->get('memcached');
            $memcached->flush(); 

            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'fieldarea_html' => self::GetDisplayData($datatype, 'field_area')
            );
        }
        catch(Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x88320029 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
/*
    // Add Child Data Type
    public function editchildtypeAction($data_type_id, Request $request)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {

            $parent_datatype = $this->getDoctrine()
                ->getRepository('ODRAdminBundle:DataType')
                ->find($data_type_id);

            // Poplulate new DataFields form
            $datatype = new DataType();
            $user = $this->container->get('security.context')->getToken()->getUser();
            $datatype->setCreatedBy($user);
            $datatype->setUpdatedBy($user);
            $form = $this->createForm(new DatatypeForm(), $datatype);
    
            $templating = $this->get('templating');
            if ($request->getMethod() == 'POST') {
                $form->bind($request, $datatype);
                $return['t'] = "html";
                if ($form->isValid()) {
                    $datafield->setDataType($parent_datatype);
                    $em = $this->getDoctrine()->getManager();
                    $em->persist($datatype);
                    $em->flush();

                    $return['d'] = array(
                        'datatype_id' => $parent_datatype->getId(),
                        'fieldarea_html' => self::GetDisplayData($parent_datatype, 'field_area')
                    );
                }
                else {
                    $return['d'] = $templating->render(
                        'ODRAdminBundle:Displaytemplate:add_childtype_form.html.twig', 
                        array(
                            'datatype' => $datatype,
                            'datafield' => $datafield,
                            'form' => $form->createView()
                        )
                    );
                }
            }
            else {
                $return['d'] = $templating->render(
                    'ODRAdminBundle:Datafields:add_field_dialog_design_form.html.twig', 
                    array(
                        'datatype' => $datatype,
                        'datafield' => $datafield,
                        'form' => $form->createView()
                    )
                );
            }
        }
        catch(Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x88320029 ' . $e->getMessage();
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;  
    }
*/
    // Add Child Data Type
    public function addchildtypeAction($data_type_id, $theme_element_id, Request $request)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            $user = $this->container->get('security.context')->getToken()->getUser();

            $em = $this->getDoctrine()->getManager();
            $repo = $em->getRepository('ODR\AdminBundle\Entity\DataType');

            $parent_datatype = $repo->find($data_type_id);

            // Poplulate new DataFields form
            $datatype = new DataType();
            $datatype->setShortName("New Child");
            $datatype->setLongName("New Child");
            $datatype->setDescription("New Child Type");
            $datatype->setMultipleRecordsPerParent('1');
            $datatype->setPublicDate(new \DateTime('1980-01-01 00:00:00'));
            $datatype->setCreatedBy($user);
            $datatype->setUpdatedBy($user);
            $em->persist($datatype);

            $datatree = new DataTree();
            $datatree->setAncestor($parent_datatype);
            $datatree->setDescendant($datatype);
            $datatree->setCreatedBy($user); 
            $datatree->setUpdatedBy($user);
            $datatree->setIsUnique(0);
            $em->persist($datatree);

            // Tie Field to ThemeElement
            $themeelement = $this->getDoctrine()
                ->getRepository('ODRAdminBundle:ThemeElement')
                ->findOneBy(array("id" => $theme_element_id));

            $themeelementfield = new ThemeElementField();
            $themeelementfield->setCreatedBy($user);
            $themeelementfield->setUpdatedBy($user);
            $themeelementfield->setDataType($datatype); 
            $themeelementfield->setThemeElement($themeelement);
            $em->persist($themeelementfield);

            $em->flush();

            $return['d'] = array(
                'datatype_id' => $parent_datatype->getId(),
                'fieldarea_html' => self::GetDisplayData($datatype, 'field_area')
            );
        }
        catch(Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x832819234 ' . $e->getMessage();
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;  
    }

    public function getlinktypesAction($data_type_id, $theme_element_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "html";
        $return['d'] = "";

        try {
            // Set up repositories
            $repo_datatype = $this->getDoctrine()->getRepository('ODRAdminBundle:DataType');
            $repo_datatree = $this->getDoctrine()->getRepository('ODRAdminBundle:DataTree');
            $repo_theme_element = $this->getDoctrine()->getRepository('ODRAdminBundle:ThemeElement');
            $repo_theme_element_field = $this->getDoctrine()->getRepository('ODRAdminBundle:ThemeElementField');

            // Locate basic stuff
            $local_datatype = $repo_datatype->findOneBy(array("id" => $data_type_id));
            $theme_element = $repo_theme_element->findOneBy(array("id" => $theme_element_id));
            $theme_element_fields = $repo_theme_element_field->findBy(array("themeElement" => $theme_element_id));

            // Locate the previously linked datatype if it exists
            $previous_remote_datatype = NULL;
            foreach ($theme_element_fields as $tef) {
                if ($tef->getDataType() !== NULL)
                    $previous_remote_datatype = $tef->getDataType();
            }

            // Locate the parent of this datatype if it exists
            $datatree = $repo_datatree->findOneBy(array('descendant' => $data_type_id));
            $parent_datatype_id = NULL;
            if ($datatree !== NULL)
                $parent_datatype_id = $datatree->getAncestor()->getId();

            // Grab all datatypes and all datatree entries...need to locate the datatypes which can be linked to
            $linkable_datatypes = array();
            $datatype_entries = $repo_datatype->findBy(array("deletedAt" => NULL));
            $datatree_entries = $repo_datatree->findBy(array("deletedAt" => NULL));

            // Iterate through all the datatypes...
            foreach ($datatype_entries as $datatype_entry) {
                $block = false;
                foreach ($datatree_entries as $datatree_entry) {
                    // If the datatype is a non-linked descendant of another datatype, block it from being linked to
                    if (($datatree_entry->getDescendant()->getId() === $datatype_entry->getId()) && ($datatree_entry->getIsLink() == false)) {
                        $block = true;
                        break;
                    }
                    // If the datatype is the ancestor of this datatype, block it from being linked to...don't want rendering recursion
                    if ($parent_datatype_id === $datatype_entry->getId()) {
                        $block = true;
                        break;
                    }
                }

                // If the datatype passes all the tests, and isn't the datatype that originally called this action, add it to the array
                if (!$block && $local_datatype->getId() !== $datatype_entry->getId())
                    $linkable_datatypes[] = $datatype_entry;
            }

            // Get Templating Object
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Displaytemplate:link_type_dialog_form.html.twig',
                    array(
                        'local_datatype' => $local_datatype,
                        'remote_datatype' => $previous_remote_datatype,
                        'theme_element' => $theme_element,
                        'linkable_datatypes' => $linkable_datatypes
                    )
                )
            );

        }
        catch(Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x838179235 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }

    // Link Child Data Type
    public function linktypeAction(Request $request)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = "";

        try {
            // Grab the data from the POST request 
            $post = $_POST;
            $local_datatype_id = $post['local_datatype_id'];
            $remote_datatype_id = $post['selected_datatype'];
            $previous_remote_datatype_id = $post['previous_remote_datatype'];
            $theme_element_id = $post['theme_element_id'];

            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $user = $this->container->get('security.context')->getToken()->getUser();

            $repo_datatype = $em->getRepository('ODR\AdminBundle\Entity\DataType');
            $repo_datatree = $em->getRepository('ODR\AdminBundle\Entity\DataTree');
            $repo_theme_element = $em->getRepository('ODR\AdminBundle\Entity\ThemeElement');
            $repo_theme_element_field = $em->getRepository('ODR\AdminBundle\Entity\ThemeElementField');

            if ($remote_datatype_id !== '') {
                // Create a link between the two datatypes
                $local_datatype = $repo_datatype->find($local_datatype_id);
                $remote_datatype = $repo_datatype->find($remote_datatype_id);

                $datatree = new DataTree();
                $datatree->setAncestor($local_datatype);
                $datatree->setDescendant($remote_datatype);
                $datatree->setCreatedBy($user);
                $datatree->setUpdatedBy($user);
                $datatree->setIsLink(1);
                $em->persist($datatree);

                // Tie Field to ThemeElement
                $theme_element = $repo_theme_element->findOneBy(array("id" => $theme_element_id));

                $theme_element_field = new ThemeElementField();
                $theme_element_field->setCreatedBy($user);
                $theme_element_field->setUpdatedBy($user);
                $theme_element_field->setDataType($remote_datatype);
                $theme_element_field->setThemeElement($theme_element);
                $em->persist($theme_element_field);

                // Remove the previous link if necessary
                if ($previous_remote_datatype_id !== '') {
                    // Remove the datatree entry
                    $datatree = $repo_datatree->findOneBy( array("ancestor" => $local_datatype_id, "descendant" => $previous_remote_datatype_id) );
                    if ($datatree !== null)
                        $em->remove($datatree);

                    // Remove the theme_element_field entry
                    $theme_element_field = $repo_theme_element_field->findOneBy( array("themeElement" => $theme_element_id, "dataType" => $previous_remote_datatype_id) );
                    if ($theme_element_field !== null)
                        $em->remove($theme_element_field);
                }
            }
            else {
                // Remove the datatree entry
                $datatree = $repo_datatree->findOneBy( array("ancestor" => $local_datatype_id, "descendant" => $previous_remote_datatype_id) );
                if ($datatree !== null)
                    $em->remove($datatree);

                // Remove the theme_element_field entry
                $theme_element_field = $repo_theme_element_field->findOneBy( array("themeElement" => $theme_element_id, "dataType" => $previous_remote_datatype_id) );
                if ($theme_element_field !== null)
                    $em->remove($theme_element_field);
            }

            $em->flush();

            $return['d'] = array(
                'element_id' => $theme_element->getId(),
                'fieldarea_html' => self::GetDisplayData($remote_datatype, 'child')
            );
        }
        catch(Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x832819235 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

    /**
     * TODO: short description.
     * 
     * @param double $datatype      
     * @param mixed  $template_name 
     * 
     * @return TODO
     */
    private function GetDisplayData($datatype, $template_name = 'default') {

        // Get Templating Object
        $templating = $this->get('templating');

        // Get Related Child Types and fields 
        // Return Field Area
        $theme = $this->getDoctrine()
            ->getRepository('ODRAdminBundle:Theme')
            ->findOneBy(array("isDefault" => "1"));

        // Doctrine\ORM\Query::HYDRATE_ARRAY
        // Ensure DataType/Theme entries exist
        self::checkDataTypeTheme($datatype, $theme);
 
        // Get Fields for Parent Type
        $datafields = $datatype->getDataFields();

        // Ensure Field/Theme entries exist
        self::checkFieldTheme($datafields, $theme);

        // Set Child Type Array
        $child_type_array = array();
        // Get Child Types
        $childtypes = $this->getDoctrine()
            ->getRepository('ODRAdminBundle:DataTree')
            ->findByAncestor($datatype);

        // Recursively Get Children 
        // Need to set these to eager load fields
        $found_ancestors = array();
        foreach($childtypes as $childtype) {
            $child_type_array = self::getChildren($childtype, $child_type_array, $found_ancestors);
        }

        foreach($child_type_array as $ancestor_id => $childtype) {
            // Ensure DataType/Theme entries exist
            self::checkDataTypeTheme($childtype, $theme);

            // Get Fields for Parent Type
            $childdatafields = $childtype->getDataFields();
    
            // Ensure Field/Theme entries exist
            self::checkFieldTheme($childdatafields, $theme);
        }

        // Get Forms for Adding Children + Fields
        // Poplulate new DataFields form
        $datafield = new DataFields();
        $user = $this->container->get('security.context')->getToken()->getUser();
        $datafield->setCreatedBy($user);
        $datafield->setUpdatedBy($user);
        $datafieldsform = $this->createForm(new DatafieldsForm(), $datafield);

        switch($template_name) {
            case 'field_area': 
                return  $templating->render(
                    'ODRAdminBundle:Displaytemplate:design_area_fieldarea.html.twig', 
                    array(
                        'datafieldsform' => $datafieldsform->createView(),
                        'theme' => $theme,
                        'childtype' => $datatype,
                        // 'datafields' => $datafields,
                        'childtypearray' => $child_type_array,
//                        'childtypes' => $childtypes,
                        'datatree' => $childtypes,
                    )
                );
            break;

            case 'child':
                // Full Display Template
                return $templating->render(
                    'ODRAdminBundle:Displaytemplate:design_area_child_load.html.twig', 
                    array(
                        'datafieldsform' => $datafieldsform->createView(),
                        'theme' => $theme,
                        'datatype' => $datatype,
                        'datafields' => $datafields,
                        'childtypearray' => $child_type_array,
//                        'childtypes' => $childtypes,
                        'datatree' => $childtypes,
                    )
                );
            break;
            
            case 'default':
            default:
                // Full Display Template

                return $templating->render(
                    'ODRAdminBundle:Displaytemplate:design_ajax.html.twig', 
                    array(
                        'datafieldsform' => $datafieldsform->createView(),
                        'theme' => $theme,
                        'datatype' => $datatype,
                        'datafields' => $datafields,
                        'childtypearray' => $child_type_array,
//                        'childtypes' => $childtypes,
                        'datatree' => $childtypes,
                    )
                );
            break;
        }
    }

    public function otherAction($something)
    {
        return $this->render('ODRAdminBundle:Default:index.html.twig', array('something' => $something));
    }

    public function typepropertiesAction($data_type_id, Request $request)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {

            $user = $this->container->get('security.context')->getToken()->getUser();
            $em = $this->getDoctrine()->getManager();
            $repo = $em->getRepository('ODR\AdminBundle\Entity\DataType');
            $datatype = $repo->find($data_type_id);

            // Get Default Theme
            $theme = $this->getDoctrine()
                ->getRepository('ODRAdminBundle:Theme')
                ->findOneBy(array("isDefault" => "1"));

            $themes = $datatype->getThemeDataType();
            $type_theme = "";
            foreach($themes as $mytheme) {
                if($mytheme->getTheme()->getId() == $theme->getId()) {
                    $type_theme = $mytheme;
                }
            }

            // Poplulate new DataFields form
            $form = $this->createForm(new UpdateDataTypeForm(), $datatype);

            if ($request->getMethod() == 'POST') {
                $form->bind($request, $datatype);
                $return['t'] = "html";
                if ($form->isValid()) {
                    $datatype->setUpdatedBy($user);
                    $em->persist($datatype);
                    $em->flush();
                }
            }
                    
            $templating = $this->get('templating');
            $return['d'] = $templating->render(
                'ODRAdminBundle:Displaytemplate:type_properties_form.html.twig', 
                array(
                    'form' => $form->createView(),
                    'datatype' => $datatype,
                    'typetheme' => $type_theme
                )
            );
        }
        catch(Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x2838920 ' . $e->getMessage();
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;  
    }

    public function savefieldAction($data_field_id, Request $request)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {

            $em = $this->getDoctrine()->getManager();
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($data_field_id);
            $user = $this->container->get('security.context')->getToken()->getUser();

            // Get Default Theme
            $theme = $this->getDoctrine()
                ->getRepository('ODRAdminBundle:Theme')
                ->findOneBy(array("isDefault" => "1"));
            $themes = $datafield->getThemeDataField();
            $field_theme = "";
            foreach($themes as $mytheme) {
                if($mytheme->getTheme()->getId() == $theme->getId()) {
                    $field_theme = $mytheme;
                }
            }
                    
            // Poplulate new DataFields form
            $form = $this->createForm(new UpdateDatafieldsForm(), $datafield);
    
            $templating = $this->get('templating');
            if ($request->getMethod() == 'POST') {
                // Deal with option names out here, if any
                $option_array = $request->request->get('DataFieldsForm_option');
                if ($option_array != null) {
                    foreach ($option_array as $option_id => $option_name) {
                        $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->find($option_id);
                        if ($radio_option->getOptionName() != $option_name) {
                            $radio_option->setOptionName($option_name);
                            $radio_option->setUpdatedBy($user);
                            $em->persist($radio_option);
                        }
                    }
                }

                // Deal with the uniqueness checkbox
                $uniqueness_failure = false;
                $uniqueness_failure_value = '';
                $form_fields = $request->request->get('DatafieldsForm');
                if ( isset($form_fields['is_unique']) ) {
                    // Check to see if the values of that datafield across all datarecords are unique
                    $failed_values = parent::verifyUniqueness($datafield);

                    if ( count($failed_values) > 0 ) {
                        // Notify of failure
                        $uniqueness_failure = true;
                        foreach ($failed_values as $failed_value)
                            $uniqueness_failure_value = $failed_value;
                    }
                }
                
                // Deal with the rest of the form
                $form->bind($request, $datafield);
                $return['t'] = "html";
                if ($form->isValid()) {
                    // $datafield->setDataType($datatype);
                    $em = $this->getDoctrine()->getManager();
                    $em->persist($datafield);
                    $em->flush();

                    // If the verifications for the uniqueness checkbox failed...
                    if ($uniqueness_failure) {
                        // Set to not unique
                        // $datafield->setIsUnique(0);
                        $em->persist($datafield);
                        $em->flush();

                        $return['r'] = 2;
                        $return['t'] = "";
                        $return['d'] = "Can't set the \"".$datafield->getFieldName()."\" DataField to be unique!\nAt least two DataRecords have duplicate values of \"".$uniqueness_failure_value."\".";
                    }
                    else {
                        $return['d'] = $templating->render(
                            // 'ODRAdminBundle:Datafields:add_field_dialog_success.html.twig', 
                            // Return Corresponding Field Block
                            'ODRAdminBundle:Displaytemplate:design_area_datafield.html.twig',
                            array(
                                'field' => $datafield,
                                'mytheme' => $field_theme
                            )
                        );
                    }
                }
                else {
                    // Get Sizing Options for Sizing Block
                    // Get Radio Option Form if Radio Type
                    $return['d'] = $templating->render(
                        'ODRAdminBundle:Displaytemplate:save_field_properties_form.html.twig', 
                        array(
                            'datafield' => $datafield,
                            'fieldtheme' => $field_theme,
                            'form' => $form->createView()
                        )
                    );
                }
            }
            else {
                $return['d'] = $templating->render(
                    'ODRAdminBundle:Displaytemplate:save_field_properties_form.html.twig', 
                    array(
                        'datafield' => $datafield,
                        'fieldtheme' => $field_theme,
                        'form' => $form->createView()
                    )
                );
            }

            // Invalidate thh cache
            $memcached = $this->get('memcached');
            $memcached->flush();
        }
        catch(Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x82377020 ' . $e->getMessage();
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;  
    }

}
