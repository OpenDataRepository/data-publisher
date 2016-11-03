<?php

namespace ODR\OpenRepository\GraphBundle\Controller;
// namespace ODR\AdminBundle\Controller;
// namespace ODR;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Controllers/Classes
use ODR\AdminBundle\Controller\ODRCustomController;
// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\Theme;
use ODR\OpenRepository\UserBundle\Entity\User;
// Forms
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class GraphController extends ODRCustomController
{
    public function staticAction($plugin_id, $datatype_id, $datarecord_id, Request $request)
    {
        try {
            $is_rollup = false;
            // Check if this is a rollup and filter datarecord_id
            if(preg_match('/rollup_/', $datarecord_id)) {
                $is_rollup = true;
                $datarecord_id = preg_replace("/rollup_/","",$datarecord_id);
            }

            // Get Datarecord
            // Load required objects
            /* * @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');
            $session = $request->getSession();

            /* **  @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new \Exception('{ "message": "Item Deleted", "detail": "Data record no longer exists."}');

            $datatype = $datarecord->getDataType();
            if ($datatype == null)
                throw new \Exception('{ "message": "Item Deleted", "detail": "Data type no longer exists."}');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy(array('dataType' => $datatype->getId(), 'themeType' => 'master'));
            if ($theme == null)
                throw new \Exception('{ "message": "Item Deleted", "detail": "Theme could not be found."}');

            // Save incase the user originally requested a child datarecord
            $original_datarecord = $datarecord;
            $original_datatype = $datatype;
            $original_theme = $theme;


            // ...want the grandparent datarecord and datatype for everything else, however
            $is_top_level = 1;
            if ($datarecord->getId() !== $datarecord->getGrandparent()->getId()) {
                $is_top_level = 0;
                $datarecord = $datarecord->getGrandparent();

                $datatype = $datarecord->getDataType();
                if ($datatype == null)
                    throw new \Exception('{ "message": "Item Deleted", "detail": "Data type no longer exists."}');

                /** @var Theme $theme */
                $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy(array('dataType' => $datatype->getId(), 'themeType' => 'master'));
                if ($theme == null)
                    throw new \Exception('{ "message": "Item Deleted", "detail": "Theme no longer exists."}');
            }


            // ----------------------------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = array();
            $datatype_permissions = array();
            $datafield_permissions = array();


            if ($user === 'anon.') {
                if ($datatype->isPublic() && $datarecord->isPublic()) {
                    /* anonymous users aren't restricted from a public datarecord that belongs to a public datatype */
                } else {
                    // ...if either the datatype is non-public or the datarecord is non-public, return false
                    throw new \Exception('{ "message": "Permission Denied", "detail": "Data type  or data record is non-public."}');
                }
            } else {
                // Grab user's permissions
                $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
                $datatype_permissions = $user_permissions['datatypes'];
                $datafield_permissions = $user_permissions['datafields'];

                $can_view_datatype = false;
                if (isset($datatype_permissions[$original_datatype->getId()]) && isset($datatype_permissions[$original_datatype->getId()]['dt_view']))
                    $can_view_datatype = true;

                $can_view_datarecord = false;
                if (isset($datatype_permissions[$original_datatype->getId()]) && isset($datatype_permissions[$original_datatype->getId()]['dr_view']))
                    $can_view_datarecord = true;

                // If either the datatype or the datarecord is not public, and the user doesn't have the correct permissions...then don't allow them to view the datarecord
                if (!($original_datatype->isPublic() || $can_view_datatype) || !($datarecord->isPublic() || $can_view_datarecord))
                    throw new \Exception('{ "message": "Permission Denied", "detail": "Insufficient permissions."}');
            }
            // ----------------------------------------


            // ----------------------------------------
            // Always bypass cache if in dev mode?
            $bypass_cache = false;
            if ($this->container->getParameter('kernel.environment') === 'dev')
                $bypass_cache = true;


            // Grab all datarecords "associated" with the desired datarecord...
            $associated_datarecords = parent::getRedisData(($redis->get($redis_prefix . '.associated_datarecords_for_' . $datarecord->getId())));
            if ($bypass_cache || $associated_datarecords == false) {
                $associated_datarecords = parent::getAssociatedDatarecords($em, array($datarecord->getId()));

                $redis->set($redis_prefix . '.associated_datarecords_for_' . $datarecord->getId(), gzcompress(serialize($associated_datarecords)));
            }

            // Grab the cached versions of all of the associated datarecords, and store them all at the same level in a single array
            $datarecord_array = array();
            foreach ($associated_datarecords as $num => $dr_id) {
                $datarecord_data = parent::getRedisData(($redis->get($redis_prefix . '.cached_datarecord_' . $dr_id)));
                if ($bypass_cache || $datarecord_data == false)
                    $datarecord_data = parent::getDatarecordData($em, $dr_id, true);

                foreach ($datarecord_data as $dr_id => $data)
                    $datarecord_array[$dr_id] = $data;
            }

            // If this request isn't for a top-level datarecord, then the datarecord array needs to have entries removed so twig doesn't render more than it should...TODO - still leaves more than it should...
            if ($is_top_level == 0 && !$is_rollup) {
                $target_datarecord_parent_id = $datarecord_array[$original_datarecord->getId()]['parent']['id'];
                unset($datarecord_array[$target_datarecord_parent_id]);

                foreach ($datarecord_array as $dr_id => $dr) {
                    if ($dr_id !== $original_datarecord->getId() && $dr['parent']['id'] == $target_datarecord_parent_id)
                        unset($datarecord_array[$dr_id]);
                }
            }


            // ----------------------------------------
            //
            $datatree_array = parent::getDatatreeArray($em, $bypass_cache);

            // Grab all datatypes associated with the desired datarecord
            // NOTE - not using parent::getAssociatedDatatypes() here on purpose...that would always return child/linked datatypes for the datatype even if this datarecord isn't making use of them
            $associated_datatypes = array();
            foreach ($datarecord_array as $dr_id => $dr) {
                $dt_id = $dr['dataType']['id'];

                if (!in_array($dt_id, $associated_datatypes))
                    $associated_datatypes[] = $dt_id;
            }


            // Grab the cached versions of all of the associated datatypes, and store them all at the same level in a single array
            $datatype_array = array();
            foreach ($associated_datatypes as $num => $dt_id) {
                // print $redis_prefix.'.cached_datatype_'.$dt_id;
                $datatype_data = parent::getRedisData(($redis->get($redis_prefix . '.cached_datatype_' . $dt_id)));
                if ($bypass_cache || $datatype_data == false)
                    $datatype_data = parent::getDatatypeData($em, $datatree_array, $dt_id, $bypass_cache);

                foreach ($datatype_data as $dt_id => $data)
                    $datatype_array[$dt_id] = $data;
            }

            // ----------------------------------------
            // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
            parent::filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);

            // throw new \Exception('{ ""message"": "Permission Denied", "detail": "Data type  or data record is non-public."}');
            // throw new \Exception("Data type  or data record is non-public.");
            // Call Render Plugin
            // Filter Data Records to only include desired datatype
            foreach ($datarecord_array as $dr_id => $dr) {
                if ($dr['dataType']['id'] != $datatype_id) {
                    unset($datarecord_array[$dr_id]);
                }
            }

            // Determine if this is a single or rollup graph.
            // If single only send the one datarecord

            // Load and execute the render plugin
            $datatype = $datatype_array[$datatype_id];
            $render_plugin = $datatype['dataTypeMeta']['renderPlugin'];
            $theme = $datatype['themes'][$original_theme->getId()];
            $svc = $this->container->get($render_plugin['pluginClassName']);
            // Build Graph - Static Option
            // {% set rendering_options = {'is_top_level': is_top_level, 'is_link': is_link, 'display_type': display_type} %}
            $rendering_options = array();
            $rendering_options['is_top_level'] = $is_top_level;
            // TODO Figure out where display_type comes from.  Is it deprecated?
            $rendering_options['display_type'] = 100000;
            $rendering_options['is_link'] = false;
            $rendering_options['build_graph'] = true;
            if ($is_rollup) {
                $rendering_options['datarecord_id'] = 'rollup';
            }
            else {
                $rendering_options['datarecord_id'] = $datarecord_id;
            }


            // Render the static graph
            $filename = $svc->execute($datarecord_array, $datatype, $render_plugin, $theme, $rendering_options);
            return $this->redirect("/uploads/files/graphs/" . $filename);
        }
        catch (\Exception $e) {
            $message = $e->getMessage();
            $message_data = json_decode($message);
            if($message_data) {
                $response = self::svgWarning($message_data->message, $message_data->detail);
            }
            else {
                $response = self::svgWarning($message);
            }
            $headers = array(
                'Content-Type' => 'image:svg+xml',
                'Content-Disposition' => 'inline;filename=error_message.svg'
            );
            return new Response($response, '200', $headers);
        }

    }

    public function svgWarning($message, $detail = "") {

        $templating = $this->get('templating');

        return $templating->render(
            'ODROpenRepositoryGraphBundle:Graph:graph_error.html.twig',
            array(
                'message' => $message,
                'detail' => $detail
            )
        );

    }
}
