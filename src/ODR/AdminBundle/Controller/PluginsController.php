<?php

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
// Forms
// Symphony
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

}
