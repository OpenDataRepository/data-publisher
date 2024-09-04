/**
 * Open Data Repository Data Publisher
 * odr_filter_graph_plugin.js
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This file contains the plotly/graphing functions used by the Filter Graph Plugin.
 */

/**
 * @typedef {Object} odrFilterChartObj
 * @type {object}
 * @property {string} chart_id - The ID of this chart object
 * @property {array} file_data - All files that could be graphed by the plugin
 * @property {array} sort_order - TODO
 * @property {array} filter_values - TODO
 */

/**
 * Determines which files should be graphed based on the selected filter options, and shows/hides
 * the related data div if needed.
 * @param {odrFilterChartObj} odr_chart_obj
 * @return {array}
 */
function ODRFilterGraph_updateGraphedFiles(odr_chart_obj) {
    var odr_chart_id = odr_chart_obj['chart_id'];
    // console.log(odr_chart_obj['filter_values']);

    // While each datarecord should only match a single option per datafield, bad data could break
    //  this rule...therefore, use the Map object to ensure there are no duplicate datarecord ids
    var permitted_datarecords = new Map();
    $('#' + odr_chart_id + '_filter').find('.ODRFilterGraphPlugin_select_div').each(function(index,div) {
        // if ( $(div).parent().find('.ODRFilterGraphPlugin_active').is(':checked') ) {
            var df_id = $(div).attr('rel');
            // console.log( $(div), df_id );

            var tmp = new Map();
            $(div).find('option:selected').each(function (index,input) {
                var option_id = $(input).attr('rel');
                $.each(odr_chart_obj['filter_values'][df_id][option_id], function (index, dr_id) {
                    tmp.set(dr_id, 1);
                });
            });

            permitted_datarecords.set(df_id, Array.from(tmp.keys()));
            // console.log('permitted_datarecords[' + df_id + ']: ', permitted_datarecords.get(df_id));
        // }

        // Sorting this probably doesn't help
        // permitted_datarecords[df_id] = permitted_datarecords[df_id].sort();
    });
    // console.log('permitted_datarecords', permitted_datarecords);

    // Going to need a list of all datarecords which could be visible, and the list of datarecords
    //  which should be visible
    var final_dr_list = null;
    var full_dr_list = new Map();

    // Apparently, using .forEach() on a Map object returns (value, key) pairs, instead of (key, value) pairs
    permitted_datarecords.forEach((dr_list, df_id) => {
        // console.log('dr_list: ', dr_list);
        if ( final_dr_list === null )
            final_dr_list = dr_list;
        else
            final_dr_list = final_dr_list.filter((dr_id) => dr_list.includes(dr_id));

        dr_list.forEach((dr_id) => {
            full_dr_list.set(dr_id, 1);
        });
    });
    // Convert the full datarecord list back into an array
    full_dr_list = Array.from(full_dr_list.keys());
    // console.log('full_dr_list', full_dr_list);
    // console.log('final_dr_list', final_dr_list);

    // Going to return a list of files for the plugin to graph...
    var files_to_graph = [];

    // Have to manually count files, since array.length doesn't work on sparse arrays...
    var remaining_dr_id = null;
    var file_count = 0;
    if  ( final_dr_list !== null ) {
        final_dr_list.forEach((dr_id) => {
            var sort_order = odr_chart_obj['sort_order'][dr_id];
            if ( sort_order !== undefined ) {
                files_to_graph[sort_order] = odr_chart_obj['file_data'][dr_id];
                file_count++;

                // Save the datarecord id in case the data section of the graph needs to be shown
                remaining_dr_id = dr_id;
            }
        });

        $('#' + odr_chart_id + '_filter').find('.ODRFilterGraphPlugin_select_div').each(function(index,div) {
            // if ( $(div).parent().find('.ODRFilterGraphPlugin_active').is(':checked') ) {
            var df_id = $(div).attr('rel');

            $(div).find('option').each(function (index,input) {
                var option_id = $(input).attr('rel');

                var included = false;
                $.each(odr_chart_obj['filter_values'][df_id][option_id], function (index, dr_id) {
                    if ( final_dr_list.includes(dr_id) )
                        included = true;
                });

                // Can't disable or hide the option...selecting one option in a field will immediately
                //  disable/hide the others in the same field
                if ( !included )
                    $(input).addClass('ODRFilterGraphPlugin_fake_unselected');
                else
                    $(input).removeClass('ODRFilterGraphPlugin_fake_unselected');



                // TODO - need a "third" class here...those that "could be selected without returning zero results"
                // TODO - in theory, should be able to get this by taking "all the options the user selected", the computing the intersection of what's allowed for each of them
            });
        });
    }


    // Want to set visibility of the related data divs depending on which files are graphed...
    var data_div = $("#" + odr_chart_id + "_filter").parents('.ODRGraphSpacer').first().next();

    // In order to pull this off, we need three maps...one of all fieldareas the rest of this
    //  function could show/hide...
    var all_fieldareas = new Map();
    $(data_div).find('.ODRFieldArea').each(function(index,elem) {
        var dr_id = parseInt( $(elem).attr('id').split('_')[1] );
        all_fieldareas.set(dr_id, elem);
    });
    // console.log('all_fieldareas', all_fieldareas);

    // ...another map to quickly determine the parent of any given fieldarea in the data div...
    var parent_div_lookup = new Map();
    // ...and a third to determine whether any of a parent div's children are visible
    var parent_div_visible_counts = new Map();

    // For each fieldarea div inside the data div...
    all_fieldareas.forEach((fieldarea, dr_id) => {
        // ...get that fieldarea's parent id
        var parent_dr = $(fieldarea).parents('.ODRFieldArea').first();
        if ( $(parent_dr).length > 0 ) {
            var parent_dr_id = parseInt( $(parent_dr).attr('id').split('_')[1] );

            // If the parent fieldarea is still a child of the data div, and the parent doesn't have any
            //  files itself...
            if ( all_fieldareas.has(parent_dr_id) && full_dr_list.indexOf(parent_dr_id) === -1 ) {
                // ...then save the lookup data for the parent div so it can have its visibility set
                //  alongside its child(ren) divs later on
                parent_div_lookup.set(dr_id, parent_dr_id);
                parent_div_visible_counts.set(parent_dr_id, 0);
            }
        }
    });
    // console.log('parent_div_lookup', parent_div_lookup);
    // console.log('parent_div_visible_counts', parent_div_visible_counts);

    // Then, for each fieldarea that has a file...
    var visible_fieldareas = new Map();
    full_dr_list.forEach((dr_id) => {
        var fieldarea = all_fieldareas.get(dr_id);

        if ( final_dr_list.indexOf(dr_id) === -1 ) {
            // ...the filter options selected by the user decree this fieldarea should be hidden
            ODRFilterGraph_updateFieldarea(fieldarea, dr_id, 'hide');
        }
        else {
            // ...the filter options selected by the user decree this fieldarea should be visible
            ODRFilterGraph_updateFieldarea(fieldarea, dr_id, 'show');

            // Save that this fieldarea is visible...
            visible_fieldareas.set(dr_id, fieldarea);
            if ( parent_div_lookup.has(dr_id) ) {
                // ...and also save that its parent has at least one visible child fieldarea
                var parent_div_id = parent_div_lookup.get(dr_id);
                parent_div_visible_counts.set(parent_div_id, parent_div_visible_counts.get(parent_div_id) + 1);
            }
        }
    });
    // console.log('visible_fieldareas', visible_fieldareas);
    // console.log('parent_div_visible_counts', parent_div_visible_counts);

    // Now that all the fieldareas with files have been shown/hidden...
    if ( parent_div_visible_counts.size > 0 ) {
        // ...need to also ensure their parents are properly visible
        parent_div_visible_counts.forEach((num, dr_id) => {
            var fieldarea = all_fieldareas.get(dr_id);

            // A parent fieldarea div is shown when it has at least one visible child fieldarea
            if (num > 0)
                ODRFilterGraph_updateFieldarea(fieldarea, dr_id, 'show');
            else
                ODRFilterGraph_updateFieldarea(fieldarea, dr_id, 'hide');
        });
    }


    // Once the visibility of the individual data divs is correctly set...
    if ( visible_fieldareas.size > 0 ) {
        // ...at least one data div is visible, so ensure the data portion is also visible
        $(data_div).show();
        // Extract the id of the first div in the array...
        var dr_id = ( Array.from(visible_fieldareas.keys()).slice(0,1) )[0];
        // ...so it can be guaranteed to be visible
        selectRecordFieldArea(dr_id);
    }
    else {
        // ...no data divs are visible, ensure the data portion is hidden to avoid empty HTML elements
        $(data_div).hide();
    }


    // If the user selected options which result in no files...
    if ( file_count == 0 ) {
        // ...then change some of the highlights to clearly indicate they made a bad selection
        $('#' + odr_chart_id + '_filter').find('.ODRFilterGraphPlugin_select').each(function(index,elem) {
            if ( !$(elem).parent().parent().find('.ODRFilterGraphPlugin_select_all').hasClass('ODRFilterGraphPlugin_select_all_faded') )
                $(elem).find('option:selected').addClass('ODRFilterGraphPlugin_bad_selection');
        });
    }
    else {
        // ...otherwise, this is a valid selection
        $('#' + odr_chart_id + '_filter').find('.ODRFilterGraphPlugin_option').removeClass('ODRFilterGraphPlugin_bad_selection');
    }

    return files_to_graph;
}

/**
 * Due to needing to set both the visibility of the fieldarea and the element that selects it at the
 * same time, it's easier to use a separate function...
 *
 * @param {HTMLElement} fieldarea
 * @param {number} dr_id
 * @param {string} action
 */
function ODRFilterGraph_updateFieldarea(fieldarea, dr_id, action) {
    // Setting the visibility of the data div is the easier part...
    // console.log('ODRFilterGraph_updateFieldarea()', dr_id);

    // Intentionally using show/hide() here...the existing descendant selection already uses the
    //  ODRHidden class, and it's harder to figure out whether the div is hidden because the selector
    //  wants it to be, or because the filter graph wants it to be
    if ( action === 'hide' )
        $(fieldarea).hide();
    else
        $(fieldarea).show();

    // ...the harder part is locating the element used to select this fieldarea
    var parent = $(fieldarea).parent();
    if ( $(parent).hasClass('ODRTabAccordion') ) {
        // Tab display type
        $(parent).find('.ODRTabButton').each(function(index,elem) {
            if ( $(elem).attr('rel') == dr_id ) {
                if ( action === 'hide' )
                    $(elem).hide();
                else
                    $(elem).show();
            }
        })
    }
    else if ( $(parent).hasClass('ODRDropdownAccordion') ) {
        // Dropdown display type
        var dropdown = $(parent).children('h3').find('.ODRSelect');
        var option = $(dropdown).children('option[value="' + dr_id + '"]');
        if ( $(option).length > 0 ) {
            if ( action === 'hide' )
                $(option).hide();
            else
                $(option).show();
        }
    }
    else if ( $(parent).hasClass('ODRFormAccordion') ) {
        // Accordion display type
        if ( action === 'hide' )
            $(fieldarea).prev().hide();
        else
            $(fieldarea).prev().show();
    }
    else {
        // List display type...no headers to select or hide, so nothing else to do
    }
}
