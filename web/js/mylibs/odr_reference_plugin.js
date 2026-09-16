/**
 * Open Data Repository Data Publisher
 * odr_reference_plugin.js
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This file contains functions to use Crossref's API (https://www.crossref.org)
 */

/**
 * Attempts to query the Crossref DOI store for a given DOI, and then attempts to fill in the
 * fields on ODR's FakeEdit page if it actually works.
 * @param {string} datarecord_id
 */
function ODRReferencePlugin_queryCrossref(datarecord_id) {
    // console.log( 'current_datarecord', datarecord_id, ODRReferencePlugin_id_lookup );

    // If the lookup data doesn't exist, then don't continue
    let has_lookup_data = true;
    if ( ODRReferencePlugin_id_lookup === undefined || ODRReferencePlugin_id_lookup === null )
        has_lookup_data = false;
    if ( has_lookup_data && !ODRReferencePlugin_id_lookup.has(datarecord_id) )
        has_lookup_data = false;

    if ( !has_lookup_data ) {
        alert('asdf');
        return;
    }

    let doi_str = $("#ODRReferencePlugin_DOIInput_" + datarecord_id).val().trim();
    // console.log('original doi_str:', doi_str);
    if (doi_str == '')
        return;

    // Because people are lazy, probably need to modify the value to just have a DOI
    if ( doi_str.match(/https?:/) ) {
        let pieces = doi_str.split('/');
        let doi_pieces = pieces.slice(-2);
        doi_str = doi_pieces.join('/');
    }
    else if ( doi_str.match(/(dx.)?doi.org/) ) {
        let pieces = doi_str.split('/');
        let doi_pieces = pieces.slice(-2);
        doi_str = doi_pieces.join('/');
    }
    else if ( doi_str.match(/doi:/i) ) {
        doi_str = doi_str.replace(/doi:/i, '').trim();
    }

    // Crossreff apparently tries to keep DOIs following this schema...slightly modified to add
    //  lowercase letters
    // https://www.crossref.org/blog/dois-and-matching-regular-expressions/
    if ( !doi_str.match(/^10.\d{4,9}\/[-._;()/:a-zA-Z0-9]+$/i) ) {
        alert('"' + doi_str + '" is an illegal DOI, aborting');
        return;
    }

    // Final step is to make the DOI url-safe
    // console.log('fixed doi_str:', doi_str);  return;
    doi_str = doi_str.replaceAll('/', '%2F');

    // Ensure none of the fields on the page have a value, in case this is looking up a second
    //  DOI without saving the first one
    let rpf_element_map = ODRReferencePlugin_id_lookup.get(datarecord_id);
    // console.log('rpf_element_map:', rpf_element_map);
    rpf_element_map.forEach((value, key) => {
        let id = '#' + value;
        // console.log('resetting:', $(id));

        if ( $(id).is('input') )
            $(id).val('');
        else
            $(id).text('');
    });

    $.ajax({
        // cache: false,
        type: 'GET',
        url: "https://api.crossref.org/works/" + doi_str,
        dataType: 'json',
        success: function(data, textStatus, jqXHR) {
            // console.log(data);
            if ( Object.hasOwn(data, 'message-type') && data['message-type'] == 'work' ) {
                let values = ODRRRUFFReferencePlugin_parseCrossref(data.message);
                // console.log(values);

                $.each(values, function(plugin_key, doi_value) {
                    // Insert the value from into the matching element on the page
                    let id = '#' + rpf_element_map.get(plugin_key);
                    if ( $(id).is('input') )
                        $(id).val(doi_value);
                    else
                        $(id).text(doi_value);

                    // Also need to trigger the jquery validate plugin on the relevant form
                    let pieces = id.split(/_/);
                    pieces[0] = 'EditForm';
                    let form_id = '#' + pieces.join('_');
                    // console.log('form_id', $(form_id));
                    $(form_id).submit();
                });
            }
            else
                alert('DOI does not refer to a single item, aborting');
        },
        error: function(jqXHR, textStatus, errorThrown) {
            // ODR's default error handler is going to eat the error by default, need to give
            //  some form of notification
            if (jqXHR.status > 400)
                alert('DOI lookup returned "' + jqXHR.status + ' ' + jqXHR.responseText.replaceAll('.', '') + '", unable to continue');
        },
        complete: function(jqXHR, textStatus) {
            // Get the xdebugToken from response headers
            var xdebugToken = jqXHR.getResponseHeader('X-Debug-Token');

            // If the Sfjs object exists
            if (typeof Sfjs !== "undefined") {
                // Grab the toolbar element
                var currentElement = $('.sf-toolbar')[0];

                // Load the data of the given xdebug token into the current toolbar wrapper
                Sfjs.load(currentElement.id, '/app_dev.php/_wdt/'+ xdebugToken);
            }
        }
    });
}

/**
 * Attempts to parse the DOI data acquired from a Crossref API call, returning just the parts
 * that ODR cares about if successful.
 *
 * Authors (and two specific journals) are modified to match naming schema already in ODR...
 *
 * @param {array} crossref_data
 * @returns {array}
 */
function ODRRRUFFReferencePlugin_parseCrossref(crossref_data) {
    let values = {};

    // authors...
    values['Authors'] = '';
    if ( Object.hasOwn(crossref_data, 'author') ) {
        let authors = [];
        // First letter, followed by rest of given name, followed by an optional second/middle/hyphenated name piece
        let given_regexp = new RegExp(/^(.)([^\s\-]+)([\s\-].)?.*$/);
        for (const author of crossref_data['author']) {
            if ( Object.hasOwn(author, 'given') && Object.hasOwn(author, 'family') ) {
                let given = author['given'];
                // for (i = 0; i < given.length; i++)
                //     console.log( given.charAt(i) );
                let bob_given = given.replace(given_regexp, "$1$3");
                // console.log('"' + given + '"', ' => ', '"' + bob_given + '"');
                let family = author['family'];
                let bob_author = family + ' ' + bob_given;
                authors.push( bob_author.replaceAll(' ,', ',').replaceAll('.', '').replaceAll('-', ' ') );
            }
        }
        values['Authors'] = authors.join(', ');
    }

    // Acta Crystallographica spams up Crossref a bit...
    let acta_cryst_letter = '';

    // article/book title, journal, and publisher...
    values['Article Title'] = '';
    values['Journal'] = '';
    values['Book Title'] = '';
    values['Publisher'] = '';
    if ( Object.hasOwn(crossref_data, 'title') ) {
        let title = crossref_data['title'][0];
        title = title.replaceAll(/\s+/g, ' ').replaceAll('&lt;', '<').replaceAll('&gt;', '>');
        values['Article Title'] = title;

        let long_title = '';
        if ( Object.hasOwn(crossref_data, 'container-title') )
            long_title = crossref_data['container-title'][0];
        else if ( Object.hasOwn(crossref_data, 'short-container-title') )
            long_title = crossref_data['short-container-title'][0];

        if ( long_title.indexOf('Acta Crystallographica Section') == 0 ) {
            let pieces = long_title.split(' ');
            acta_cryst_letter = pieces[3];
            long_title = 'Acta Crystallographica';
        }
        else if ( long_title.indexOf('Zapiski RMO') == 0 ) {
            // ...bob has been using a different name for this journal
            long_title = 'Zapiski Vserossijskogo Mineralogicheskogo Obshchestva';
        }

        if ( !Object.hasOwn(crossref_data, 'type') || crossref_data['type'] != 'book-chapter' ) {
            // ODR is going to be storing journals most of the time...
            values['Journal'] = long_title;
        }
        else {
            // ...but stuff could be listed as a book
            values['Book Title'] = long_title;
            // ...in which case it has a publisher as well
            if ( Object.hasOwn(crossref_data, 'publisher') )
                values['Publisher'] = crossref_data['publisher'];
        }
    }

    // volume...
    values['Volume'] = '';
    if ( Object.hasOwn(crossref_data, 'volume') ) {
        values['Volume'] = crossref_data['volume'];

        if ( acta_cryst_letter !== '' )
            values['Volume'] = acta_cryst_letter + values['Volume'];
    }

    // issue...
    values['Issue'] = '';
    if ( Object.hasOwn(crossref_data, 'issue') )
        values['Issue'] = crossref_data['issue'];

    // year...
    values['Year'] = '';
    if ( Object.hasOwn(crossref_data, 'published-online') )
        values['Year'] = crossref_data['published-online']['date-parts'][0][0];
    else if ( Object.hasOwn(crossref_data, 'published-print') )
        values['Year'] = crossref_data['published-print']['date-parts'][0][0];

    // ...not going to do month

    // pages...
    values['Pages'] = '';
    if ( Object.hasOwn(crossref_data, 'page') )
        values['Pages'] = crossref_data['page'];
    else if ( Object.hasOwn(crossref_data, 'article-number') )
        values['Pages'] = crossref_data['article-number'];

    // url...
    values['URL'] = '';
    if ( Object.hasOwn(crossref_data, 'URL') )
        values['URL'] = crossref_data['URL'];

    return values;
}
