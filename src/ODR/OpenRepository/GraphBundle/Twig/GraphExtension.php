<?php 

//  ODR/AdminBundle/Twig/GraphExtension.php;
namespace ODR\OpenRepository\GraphBundle\Twig;

/**
 * TODO: short description.
 * 
 * TODO: long description.
 * 
 */
class GraphExtension extends \Twig_Extension
{
    /**
     * TODO: short description.
     * 
     * @return TODO
     */
    public function getFilters()
    {
        return array(
            new \Twig_SimpleFilter('graph', array($this, 'graphFilter')),
        );
    }

    /**
     * TODO: short description.
     * 
     * @param mixed  $number       
     * @param double $decimals     Optional, defaults to 0. 
     * @param double $decPoint     Optional, defaults to '.'. 
     * @param mixed  $thousandsSep Optional, defaults to '. 
     * @param mixed                
     * 
     * @return TODO
     */
    // public function graphFilter($childtype, $pluginmap)
    public function graphFilter($number, $decimals = 0, $decPoint = '.', $thousandsSep = ',')
    {

        // Find XY File

        // Check if cached using this file
        // Filename will be file_id + type

        // Check Format

        // Get Options

        // Generate graph

        $price = number_format($number, $decimals, $decPoint, $thousandsSep);
        $price = '$'.$price;

        return $price;
    }

    /**
     * TODO: short description.
     * 
     * @return TODO
     */
    public function getName()
    {
        return 'graph_extension';
    }
}
