<?php

/**
 * Open Data Repository Data Publisher
 * ODR Markdown Parser Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service exists to get the KnpMarkdownBundle to use the apparently more standardized
 * CommonMark PHP Markdown engine instead of the default Markdown parser that comes with the bundle.
 *
 * @see http://commonmark.thephpleague.com/
 * @see https://github.com/thephpleague/commonmark
 */

namespace ODR\AdminBundle\Component\Service;

use Knp\Bundle\MarkdownBundle\MarkdownParserInterface;
use League\CommonMark\CommonMarkConverter;


class ODRMarkdownParserService extends CommonMarkConverter implements MarkdownParserInterface
{

    /**
     * ODRMarkdownParserService constructor.
     *
     * @see http://commonmark.thephpleague.com/configuration/
     */
    public function __construct()
    {
        // These are the currently available configuration options
        $config = array(
            'renderer' => array(
                'block_separator' => "\n",
                'inner_separator' => "\n",
                'soft_break'      => "\n",
            ),
            'enable_em' => true,        // controls whether italic stuff is rendered
            'enable_strong' => true,    // controls whether bold stuff is rendered
            'use_asterisk' => true,
            'use_underscore' => true,
            'html_input' => 'strip',     // defaults to 'escape'
            'allow_unsafe_links' => false,
        );

        // Not needed now, but look at https://github.com/thephpleague/commonmark-extras for
        //  examples on how to add extensions to this

        // Pass the configuration options and environment changes to the CommonMark Parser
        parent::__construct($config);
    }


    /**
     * Converts text to html using markdown rules
     *
     * @param string $text plain text
     *
     * @return string rendered html
     */
    function transformMarkdown($text)
    {
        return parent::convertToHtml($text);
    }
}
