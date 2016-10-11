<?php

namespace ODR\OpenRepository\GraphBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Controllers/Classes
use ODR\AdminBundle\Controller\ODRCustomController;
// Entites
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\AdminBundle\Entity\Theme;
use ODR\OpenRepository\UserBundle\Entity\User;
// Forms
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Cookie;

class GraphController extends ODRCustomController
{
    public function staticAction($plugin_id, $datatype_id, $datarecord_id, Request $request)
    {
        // Get Datarecord

        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $redis = $this->container->get('snc_redis.default');;
        // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');
        $session = $request->getSession();

        /** @var DataRecord $datarecord */
        $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
        if ($datarecord == null)
            return parent::deletedEntityError('Datarecord');

        $datatype = $datarecord->getDataType();
        if ($datatype == null)
            return parent::deletedEntityError('Datatype');

        /** @var Theme $theme */
        $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
        if ($theme == null)
            return parent::deletedEntityError('Theme');

        // Save incase the user originally requested a child datarecord
        $original_datarecord = $datarecord;
        $original_datatype = $datatype;
        $original_theme = $theme;


        // ...want the grandparent datarecord and datatype for everything else, however
        $is_top_level = 1;
        if ( $datarecord->getId() !== $datarecord->getGrandparent()->getId() ) {
            $is_top_level = 0;
            $datarecord = $datarecord->getGrandparent();

            $datatype = $datarecord->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                return parent::deletedEntityError('Theme');
        }


        $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
        $datatype_permissions = array();
        $datafield_permissions = array();
        $has_view_permission = false;

        if ( $user === 'anon.' ) {
            if ( !$datatype->isPublic() ) {
                // non-public datatype and anonymous user, can't view
                return parent::permissionDeniedError('view');
            }
            else {
                // public datatype, anybody can view
            }
        }
        else {
            // Grab user's permissions
            $datatype_permissions = parent::getPermissionsArray($user->getId(), $request);
            $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);

            // If user has view permissions, show non-public sections of the datarecord
            if ( isset($datatype_permissions[ $original_datatype->getId() ]) && isset($datatype_permissions[ $original_datatype->getId() ][ 'view' ]) )
                $has_view_permission = true;

            // If datatype is not public and user doesn't have permissions to view anything other than public sections of the datarecord, then don't allow them to view
            if ( !$original_datatype->isPublic() && !$has_view_permission )
                return parent::permissionDeniedError('view');
        }
        // ----------------------------------------
        // Always bypass cache if in dev mode?
        $bypass_cache = false;
        if ($this->container->getParameter('kernel.environment') === 'dev')
            $bypass_cache = true;


        // Grab all datarecords "associated" with the desired datarecord...
        $associated_datarecords = parent::getRedisData(($redis->get($redis_prefix.'.associated_datarecords_for_'.$datarecord->getId())));
        if ($bypass_cache || $associated_datarecords == false) {
            $associated_datarecords = parent::getAssociatedDatarecords($em, array($datarecord->getId()));

//print '<pre>'.print_r($associated_datarecords, true).'</pre>';  exit();

            $redis->set($redis_prefix.'.associated_datarecords_for_'.$datarecord->getId(), gzcompress(serialize($associated_datarecords)));
        }


        // Grab the cached versions of all of the associated datarecords, and store them all at the same level in a single array
        $datarecord_array = array();
        foreach ($associated_datarecords as $num => $dr_id) {
            $datarecord_data = parent::getRedisData(($redis->get($redis_prefix.'.cached_datarecord_'.$dr_id)));
            if ($bypass_cache || $datarecord_data == false)
                $datarecord_data = parent::getDatarecordData($em, $dr_id, true);

            foreach ($datarecord_data as $dr_id => $data)
                $datarecord_array[$dr_id] = $data;
        }

//print '<pre>'.print_r($datarecord_array, true).'</pre>';  exit();

        // If this request isn't for a top-level datarecord, then the datarecord array needs to have entries removed so twig doesn't render more than it should...TODO - still leaves more than it should
        if ($is_top_level == 0) {
            $target_datarecord_parent_id = $datarecord_array[ $original_datarecord->getId() ]['parent']['id'];
            unset( $datarecord_array[$target_datarecord_parent_id] );

            foreach ($datarecord_array as $dr_id => $dr) {
                if ( $dr_id !== $original_datarecord->getId() && $dr['parent']['id'] == $target_datarecord_parent_id )
                    unset( $datarecord_array[$dr_id] );
            }
        }


        // ----------------------------------------
        //
        $datatree_array = parent::getDatatreeArray($em, $bypass_cache);

        // Grab all datatypes associated with the desired datarecord
        // NOTE - not using parent::getAssociatedDatatypes() here on purpose...don't care about child/linked datatypes if they aren't attached to this datarecord
        $associated_datatypes = array();
        foreach ($datarecord_array as $dr_id => $dr) {
            $dt_id = $dr['dataType']['id'];

            if ( !in_array($dt_id, $associated_datatypes) )
                $associated_datatypes[] = $dt_id;
        }


        // Grab the cached versions of all of the associated datatypes, and store them all at the same level in a single array
        $datatype_array = array();
        foreach ($associated_datatypes as $num => $dt_id) {
            // print $redis_prefix.'.cached_datatype_'.$dt_id;
            $datatype_data = parent::getRedisData(($redis->get($redis_prefix.'.cached_datatype_'.$dt_id)));
            if ($bypass_cache || $datatype_data == false)
                $datatype_data = parent::getDatatypeData($em, $datatree_array, $dt_id, $bypass_cache);

            foreach ($datatype_data as $dt_id => $data)
                $datatype_array[$dt_id] = $data;
        }

//print '<pre>'.print_r($datatype_array, true).'</pre>';  exit();

        // ----------------------------------------
        // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
        parent::filterByUserPermissions($datatype_array, $datarecord_array, $datatype_permissions, $datafield_permissions);



        // Call Render Plugin
        try {
            // Re-organize list of datarecords into
            // $datarecord_array = array();
            // foreach ($datarecords as $num => $dr)
                // $datarecord_array[ $dr['id'] ] = $dr;

            // Load and execute the render plugin
            $svc = $this->container->get($render_plugin['pluginClassName']);
            // Build Graph - Static Option
            // {% set rendering_options = {'is_top_level': is_top_level, 'is_link': is_link, 'display_type': display_type} %}
            return $svc->execute($datarecord_array, $datatype, $render_plugin, $theme, $rendering_options);
        }
        catch (\Exception $e) {
            return 'Error executing RenderPlugin "'.$render_plugin['pluginName'].'" on Datatype '.$datatype['id'].': '.$e->getMessage();
        }

        // Redirect to Graph URL
        return $this->redirect("https://www.google.com/images/branding/googlelogo/1x/googlelogo_color_272x92dp.png");
    }
}
