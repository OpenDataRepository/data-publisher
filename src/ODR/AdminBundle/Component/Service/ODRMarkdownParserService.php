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

use League\CommonMark\CommonMarkConverter;
use Twig\Extra\Markdown\MarkdownInterface;


class ODRMarkdownParserService extends CommonMarkConverter implements MarkdownInterface
{

    /**
     * ODRMarkdownParserService constructor.
     *
     * @see http://commonmark.thephpleague.com/configuration/
     */
    public function __construct()
    {
        // These are the currently available configuration options
        $config = [
            'renderer' => [
                'block_separator' => "\n",
                'inner_separator' => "\n",
                'soft_break'      => "\n",
            ],
            'enable_em' => true,        // controls whether italic stuff is rendered
            'enable_strong' => true,    // controls whether bold stuff is rendered
            'use_asterisk' => true,
            'use_underscore' => true,
            'html_input' => 'strip',     // defaults to 'escape'
            'allow_unsafe_links' => false,
        ];

        // Not needed now, but look at https://github.com/thephpleague/commonmark-extras for
        //  examples on how to add extensions to this

        // Pass the configuration options and environment changes to the CommonMark Parser
        parent::__construct($config);
    }


    /**
     * Converts markdown text to html. This is the Twig\Extra\Markdown\MarkdownInterface method that
     * the twig/markdown-extra "markdown_to_html" filter calls (replaces the old KnpMarkdownBundle
     * transformMarkdown() entry point).
     *
     * @param string $body plain text
     * @return string rendered html
     */
    public function convert(string $body): string
    {
        // Coerce null/non-string to '' -- CommonMarkConverter::convertToHtml() type-hints a string,
        // and on PHP 8 passing null (e.g. an empty markdown field) throws a TypeError instead of
        // silently rendering nothing as it did on PHP 7.
        return parent::convertToHtml((string) $body);
    }

    /**
     * Backwards-compatible alias for the former KnpMarkdownBundle entry point.
     *
     * @param string $text plain text
     * @return string rendered html
     */
    public function transformMarkdown($text)
    {
        return $this->convert((string) $text);
    }
}
