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
 * Toggles the visibility of the related hidden fields to match the status of the checkbox.
 * @param {string} odr_chart_id
 */
function ODRFilterGraph_toggleHiddenFields(odr_chart_id) {
    var checkbox = $("#" + odr_chart_id + "_show_hidden_filters");
    if ( $(checkbox).is(':checked') ) {
        $("#" + odr_chart_id + "_filter").children(".ODRFilterGraphPlugin_HiddenFilterField").show();
    }
    else {
        var option_reselected = false;
        $("#" + odr_chart_id + "_filter").children(".ODRFilterGraphPlugin_HiddenFilterField").each(function(index,elem) {
            $(elem).hide();

            // If hiding fields, then ensure all of their options are
            //  selected so they don't affect the graph
            $(elem).find('.ODRFilterGraphPlugin_option').each(function(index2,option) {
                if ( !$(option).is(':selected') ) {
                    option_reselected = true;
                    $(option).prop('selected', true);
                }
            });
        });

        if ( option_reselected ) {
            // If an option got reselected, then redo the graph
            ODRGraph_triggerDynamicGraph(odr_chart_id);
            $("select.graph_columns").blur();
        }
    }

    // Don't want the settings div open anymore
    $(checkbox).closest(".ODRFilterGraphPlugin_settings").first().hide();
}

/**
 * Toggles the visibility of the related ODR data to match the status of the checkbox.
 * @param {string} odr_chart_id
 */
function ODRFilterGraph_toggleODRData(odr_chart_id) {
    // Just trigger a graph redraw
    ODRGraph_triggerDynamicGraph(odr_chart_id);

    // Don't want the settings div open anymore
    var checkbox = $("#" + odr_chart_id + "_show_odr_data");
    $(checkbox).closest(".ODRFilterGraphPlugin_settings").first().hide();
}

/**
 * Selects all options in the related field when clicked
 * @param {HTMLElement} button
 * @param {string} odr_chart_id
 */
function ODRFilterGraph_selectAllHandler(button, odr_chart_id) {
    $(button).parent().next().find('option').each(function(index,elem) {
        $(elem).prop('selected', true).removeClass('ODRFilterGraphPlugin_active_selection ODRFilterGraphPlugin_bad_selection ODRFilterGraphPlugin_fake_unselection');
    });

    // Since all options are selected, don't need to display this button
    $(button).addClass('ODRFilterGraphPlugin_select_all_faded');
    // Trigger a redraw to get the new set of files
    ODRGraph_triggerDynamicGraph(odr_chart_id);
}

/**
 * Handles changes to the multiple selects used by the filter graph plugin.
 * @param {HTMLElement} select
 * @param {string} odr_chart_id
 */
function ODRFilterGraph_selectionHandler(select, odr_chart_id) {
    // Need to determine whether the options are selected or not...
    var all_selected = true;
    $(select).find('option').each(function(index,option) {
        if ( !$(option).is(':selected') ) {
            all_selected = false;
            $(option).removeClass('ODRFilterGraphPlugin_active_selection');
        }
        else {
            $(option).addClass('ODRFilterGraphPlugin_active_selection');
        }
    });

    // If at least one option is deselected, then show the 'Select All' button
    var button = $(select).parent().parent().find('.ODRFilterGraphPlugin_select_all');
    if ( all_selected )
        $(button).addClass('ODRFilterGraphPlugin_select_all_faded');
    else
        $(button).removeClass('ODRFilterGraphPlugin_select_all_faded');

    // Trigger a redraw to get the new set of files
    ODRGraph_triggerDynamicGraph(odr_chart_id);
}

/**
 * Determines which files should be graphed based on the selected filter options, and shows/hides
 * the related data div if needed.
 * @param {odrFilterChartObj} odr_chart_obj
 * @return {array}
 */
function ODRFilterGraph_updateGraphedFiles(odr_chart_obj) {
    var odr_chart_id = odr_chart_obj['chart_id'];
    // console.log(odr_chart_obj['filter_values']);

    var permitted_datarecords = [];
    $('#' + odr_chart_id + '_filter').find('.ODRFilterGraphPlugin_select_div').each(function(index,div) {
        // if ( $(div).parent().find('.ODRFilterGraphPlugin_active').is(':checked') ) {
            var df_id = $(div).attr('rel');
            permitted_datarecords[df_id] = [];

            $(div).find('option:selected').each(function (index,input) {
                var option_id = $(input).attr('rel');
                $.each(odr_chart_obj['filter_values'][df_id][option_id], function (index, dr_id) {
                    permitted_datarecords[df_id].push(dr_id);
                });
            });
        // }

        // Sorting this probably doesn't help
        // permitted_datarecords[df_id] = permitted_datarecords[df_id].sort();
    });
    // console.log('permitted_datarecords', permitted_datarecords);

    // Using .forEach() instead of jquery $.each(), because the latter does not like sparse arrays
    var final_dr_list = null;
    permitted_datarecords.forEach((dr_list) => {
        if ( final_dr_list === null )
            final_dr_list = dr_list;
        else
            final_dr_list = final_dr_list.filter(dr_id => dr_list.includes(dr_id));
    });
    // console.log('final_dr_list', final_dr_list);

    // Going to return a list of files for the plugin to graph...
    var files_to_graph = [];

    // Have to manually count files, since array.length doesn't work on sparse arrays...
    var remaining_dr_id = null;
    var file_count = 0;
    if  ( final_dr_list !== null ) {
        final_dr_list.forEach((dr_id) => {
            var sort_order = odr_chart_obj['sort_order'][dr_id];
            files_to_graph[sort_order] = odr_chart_obj['file_data'][dr_id];
            file_count++;

            // Save the datarecord id in case the data section of the graph needs to be shown
            remaining_dr_id = dr_id;
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

                // Want to provide hints to the user about what they're looking at...
                // 'ODRFilterGraphPlugin_possible_selection' is applied to options that will further filter down the list of files
                // 'ODRFilterGraphPlugin_fake_unselection' is applied to options that will result in zero files displayed
                if ( !included )
                    $(input).addClass('ODRFilterGraphPlugin_fake_unselection').removeClass('ODRFilterGraphPlugin_possible_selection');
                else
                    $(input).removeClass('ODRFilterGraphPlugin_fake_unselection').addClass('ODRFilterGraphPlugin_possible_selection');
            });
        });
    }



    // Want to set visibility of the related data div depending on how many files remain...
    var data_div = $("#" + odr_chart_id + "_filter").parents('.ODRGraphSpacer').first().next();
    // ...but also need an override to always show the data
    var show_data = false;
    if ( $("#" + odr_chart_id + "_show_odr_data").is(':checked') )
        show_data = true;

    if ( file_count < 2 || show_data ) {
        // If there's one datarecord...
        if ( remaining_dr_id !== null || show_data ) {
            // ...then want to display it.  However, it's likely that it's a descendant of some other
            //  record, so it'll take some effort to guarantee it's visible...
            var record_ids = [remaining_dr_id];
            var fieldarea = $("#FieldArea_" + remaining_dr_id);
            if ( $(fieldarea).length > 0 ) {
                while ( !$(fieldarea).parent().parent().hasClass('ODRGraphSpacer') ) {
                    // Need to traverse up the HTML to get each parent of the remaining datarecord
                    fieldarea = $(fieldarea).parents('.ODRFieldArea').first();
                    record_ids.push( $(fieldarea).attr('id').split(/_/)[1] );
                }

                // This list of ids needs to be reversed, so that the parent accordion/tab/dropdown
                //  elements can be selected before the children
                record_ids.reverse();
                // console.log( 'record ids', record_ids );

                record_ids.forEach((dr_id) => {
                    selectRecordFieldArea(dr_id);
                });
            }
        }

        // Regardless of whether there's a datarecord or not, show the data div now
        $(data_div).removeClass('ODRHidden');
    }
    else {
        // Otherwise, more than one file, so "no point" displaying the raw data...
        $(data_div).addClass('ODRHidden');
    }

    if ( file_count == 0 ) {
        // If the currently selected options don't result in any files to display...
        $('#' + odr_chart_id + '_filter').find('.ODRFilterGraphPlugin_select').each(function(index,elem) {
            // ...then locate all options the user has selected and mark them as 'bad'...this lets
            //  the user quickly know which ones they need to start removing to get back to a state
            //  where files are displayed
            if ( !$(elem).parent().parent().find('.ODRFilterGraphPlugin_select_all').hasClass('ODRFilterGraphPlugin_select_all_faded') )
                $(elem).find('option:selected').addClass('ODRFilterGraphPlugin_bad_selection');
        });
    }
    else {
        // Otherwise, if there's at least one file displayed, then remove all occurrences of the
        //  'bad selection' css class
        $('#' + odr_chart_id + '_filter').find('.ODRFilterGraphPlugin_option').removeClass('ODRFilterGraphPlugin_bad_selection');
    }

    return files_to_graph;
}
