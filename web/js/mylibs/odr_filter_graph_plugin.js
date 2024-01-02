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

    var permitted_datarecords = [];
    $('#' + odr_chart_id + '_filter').find('.ODRGraphFilterPlugin_option_div').each(function(index,div) {
        // if ( $(div).parent().find('.ODRGraphFilterPlugin_active').is(':checked') ) {
            var df_id = $(div).attr('rel');
            permitted_datarecords[df_id] = [];

            $(div).find('option:selected').each(function (index,input) {
                var option_id = $(input).attr('id').split('_')[2];
                $.each(odr_chart_obj['filter_values'][df_id][option_id], function (index, dr_list) {
                    permitted_datarecords[df_id].push(dr_list);
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
    }

    // Also want to set visibility of the related data div depending on how many files remain...
    var data_div = $("#" + odr_chart_id + "_filter").parents('.ODRGraphSpacer').first().next();
    if ( file_count < 2 ) {
        // If there's one datarecord...
        if ( remaining_dr_id !== null ) {
            // ...then want to display it.  However, it's likely that it's a descendant of some other
            //  record, so it'll take some effort to guarantee it's visible...
            var record_ids = [remaining_dr_id];
            var fieldarea = $("#FieldArea_" + remaining_dr_id);
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

        // Regardless of whether there's a datarecord or not, show the data div now
        $(data_div).removeClass('ODRHidden');
    }
    else {
        // Otherwise, more than one file, so "no point" displaying the raw data...
        $(data_div).addClass('ODRHidden');
    }

    return files_to_graph;
}
