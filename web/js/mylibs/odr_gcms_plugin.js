//  https://chart-studio.plotly.com/create/#/

//  https://plotly.com/javascript/plotlyjs-events/
//  https://plotly.com/javascript/configuration-options

//  https://plotly.com/javascript/click-events/
//      - looks like the second example should work, so long as it doesn't use self.layout.annotations
//      - going to have to screw with the positioning too

//  https://plotly.com/javascript/text-and-annotations/
//  https://plotly.com/javascript/reference/layout/annotations/#layout-annotations

/**
 * Open Data Repository Data Publisher
 * odr_gcms_plugin.js
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This file contains the plotly/graphing functions used by the Gas Chromatography Mass Spectrometry
 * (GCMS) plugin.
 */

// !!! IMPORTANT: you MUST use 'var' instead of 'let' in this file...phantomJS will break !!!
// !!! IMPORTANT: you CAN'T use optional function arguments either...nothing like function foo(bar, baz = "") !!!

/**
 * Modifies the columns in the given file to make sense for GCMS data
 * @param {odrDataFile} file
 * @param {odrChartObj} chart_obj
 * @return {object}
 */
function ODRGraph_GCMSModifyFile(file, chart_obj) {
    var columns = file.columns;
    if ( columns['gcms_primary'] === undefined ) {
        // GCMS data needs to be in two different formats, compiled from 3 columns
        var time_column = Number(chart_obj.time_column) - 1;
        var amu_column = Number(chart_obj.amu_column) - 1;
        var counts_column = Number(chart_obj.counts_column) - 1;

        // If any of these columns don't exist, then the data can't be plotted
        var error_messages = [];
        if ( time_column === '' || !Number.isInteger( Number(time_column) ) )
            error_messages.push("The file for \"" + file.legend + "\" can't identify a column with the string \"" + time_column + "\"");
        else if ( columns[time_column] === undefined )
            error_messages.push("The file for \"" + file.legend + "\" does not have data for column " + time_column);

        if ( amu_column === '' || !Number.isInteger( Number(amu_column) ) )
            error_messages.push("The file for \"" + file.legend + "\" can't identify a column with the string \"" + amu_column + "\"");
        else if ( columns[amu_column] === undefined )
            error_messages.push("The file for \"" + file.legend + "\" does not have data for column " + amu_column);

        if ( counts_column === '' || !Number.isInteger( Number(counts_column) ) )
            error_messages.push("The file for \"" + file.legend + "\" can't identify a column with the string \"" + counts_column + "\"");
        else if ( columns[counts_column] === undefined )
            error_messages.push("The file for \"" + file.legend + "\" does not have data for column " + counts_column);

        if ( error_messages.length > 0 )
            return { 'errors': error_messages };


        // ----------------------------------------
        // GCMS data (or at least the NASA version of it) has several sets of data contained within
        //  each file

        // The "primary" format is built by reading the AMU values until they reset from high to low
        //  ...the resulting rows of data are plotted together with "average TIME" versus "sum of COUNTS"
        // The "secondary" format is a plot of "AMU values" versus "COUNTS", but organized by the average
        //  TIME found during the "primary" phase
        var new_columns = {};
        new_columns.gcms_primary = [
            [],    // x_values
            [],    // y_values
        ];
        new_columns.gcms_secondary = {};

        var time_sum = 0;
        var time_num = 0;
        var count_sum = 0;

        var tmp_x = [];
        var tmp_y = [];

        // Skip over the header row, if it exists
        var starting_row = 0;
        if ( Number.isNaN( Number(columns[amu_column][0]) ) )
            starting_row = 1;
        var prev_amu_value = columns[amu_column][starting_row];

        for (var i = starting_row; i < columns[amu_column].length; i++) {
            if ( columns[amu_column][i] >= prev_amu_value ) {
                // This is the same "sample"
                // The "primary" graph has an x_value that's the average of the TIME column for
                //  this "sample"...
                time_sum += Number(columns[time_column][i]);
                time_num++;
                // ...its y_value is the combined sum of the COUNTS column for this "sample"
                count_sum += Number(columns[counts_column][i]);

                // The "secondary" graph has an x_value that's the actual AMU value...
                tmp_x.push( Number(columns[amu_column][i]) );
                // ...and its y_value is the associated COUNTS for this row
                tmp_y.push( Number(columns[counts_column][i]) );
            }
            else {
                // This is a new "sample"...
                if ( count_sum > 0 ) {
                    // If counts are above a certain number, save the x/y values from the previous
                    //  "sample"
                    var time_average = time_sum / time_num;
                    new_columns.gcms_primary[0].push(time_average);
                    new_columns.gcms_primary[1].push(count_sum);

                    // Store the values for the secondary graph too
                    new_columns.gcms_secondary[time_average] = [
                        tmp_x,
                        tmp_y,
                    ];
                }

                // Reset for the new "sample"
                time_sum = Number(columns[time_column][i]);
                time_num = 1;
                count_sum = Number(columns[counts_column][i]);

                tmp_x = [];
                tmp_y = [];
                tmp_x.push( Number(columns[amu_column][i]) );
                tmp_y.push( Number(columns[counts_column][i]) );
            }

            prev_amu_value = Number(columns[amu_column][i]);
        }


        // ----------------------------------------
        // No sense doing this more than once
        columns = new_columns;

        var element_id = "#FieldArea_" + file.dr_id + "_" + file.file_id;
        var json = JSON.stringify(columns);
        // console.log(json);
        $(element_id).html(json);
    }

    return columns;
}

/**
 * Renders the given chart object as an xy plot.
 * @param {odrChartObj} chart_obj
 * @param {function} onComplete
 */
function ODRGraph_GCMSlineChartPlotly(chart_obj, onComplete) {
    // console.log('plotting GCMS line chart');
    // console.log(chart_obj);

    var chart_data = [];

    var q = d3.queue();
    for (var sort_order in chart_obj.data_files) {
        var obj = chart_obj.data_files[sort_order];
        q.defer(ODRGraph_parseFile, obj, sort_order);
    }

    // Load the data asynchronously and plot when ready
    q.await(
        function(error) {
            // Save any error messages
            var error_messages = [];
            if (error)
                error_messages.push(error);

            // Store data for filtering
            var file_data = [];
            // skip 0 - error variable
            for (var i = 1; i < arguments.length; i++) {
                var file = arguments[i];
                file_data[file.display_order] = file;
            }

            // Is tracking loaded_data useful?
            var loaded_data = [];
            for (var display_order in file_data) {
                var file = file_data[display_order];
                var dr_id = file.dr_id;

                if (dr_id != "rollup") {
                    if (loaded_data[dr_id] == undefined) {
                        // console.log('Plotting GCMS xy: ' + dr_id);

                        var columns = ODRGraph_GCMSModifyFile(file, chart_obj);
                        if ( columns['errors'] !== undefined )
                            error_messages = columns['errors'];

                        if ( error_messages.length == 0 ) {
                            // Build the trace object for Plotly
                            var trace = {};
                            trace.x = columns['gcms_primary'][0];
                            trace.y = columns['gcms_primary'][1];
                            trace.name = file.legend;

                            // Want to use WebGL if at all possible, but need the ability to disable
                            //  it because phantomJS only works when rendering SVG
                            trace.mode = 'lines';
                            if ( $("#" + chart_obj.chart_id + "_disable_scatterGL").length == 0 || !$("#" + chart_obj.chart_id + "_disable_scatterGL").is(':checked') )
                                trace.type = 'scattergl';
                            else
                                trace.type = 'scatter';

                            // Add line to chart data
                            chart_data.push(trace);

                            // Store that this data is loaded
                            loaded_data[dr_id] = 1;
                        }
                    }
                }
            }

            // Define the axis settings
            var xaxis_settings = {};
            xaxis_settings.showline = true;
            xaxis_settings.showgrid = true;
            xaxis_settings.zeroline = false;
            xaxis_settings.title = chart_obj.upper_x_axis_caption;
            xaxis_settings.autottick = true;

            var yaxis_settings = {};
            yaxis_settings.showline = true;
            yaxis_settings.showgrid = true;
            yaxis_settings.zeroline = false;
            yaxis_settings.title = chart_obj.upper_y_axis_caption;
            yaxis_settings.autottick = true;
            if ( chart_obj.upper_y_axis_log == "yes" )
                yaxis_settings.type = "log";

            var layout = {
                // title: 'Title of the Graph',
                hovermode: 'closest',
                // autosize: true,
                margin: {
                    l: 70,
                    r: 20,
                    b: 70,
                    t: 20,
                    pad: 4
                },
                // paper_bgcolor: '#7f7f7f',
                // plot_bgcolor: '#c7c7c7',
                xaxis: xaxis_settings,
                yaxis: yaxis_settings,

                showlegend: true,
                hoverlabel: {
                    namelength: 50
                },
            };

            if ( error_messages.length > 0 ) {
                // Encountered an error...don't display any data
                chart_data = [];
                layout.annotations = ODRGraph_getErrorMessages(error_messages);
            }

            // Create responsive div for automatic resizing
            var graph_div = plotlyResponsiveDiv(chart_obj);
            Plotly.newPlot(graph_div, chart_data, layout).then(
                onComplete(chart_obj)
            );

            graph_div.on('plotly_click', function (data) {
                // console.log(data);
                // for (var i=0; i < data.points.length; i++) {
                    var selected_x = data.points[0].x;
                    var selected_y = Math.log10(data.points[0].y);

                    var annotation = {
                        text: selected_x + ', ' + selected_y,
                        x: selected_x,
                        y: selected_y,
                    };

                    Plotly.relayout(graph_div, {annotations: [annotation]});

                    ODRGraph_GCMSbarChartPlotly(selected_x, chart_obj);
                // }
            });
        }
    )
}

/**
 * Renders the given chart object as an xy plot.
 * @param {number} x_value
 * @param {odrChartObj} chart_obj
 */
function ODRGraph_GCMSbarChartPlotly(x_value, chart_obj) {
    // console.log('plotting GCMS line chart');
    // console.log(chart_obj);

    var chart_data = [];

    var q = d3.queue();
    for (var sort_order in chart_obj.data_files) {
        var obj = chart_obj.data_files[sort_order];
        q.defer(ODRGraph_parseFile, obj, sort_order);
    }

    // Load the data asynchronously and plot when ready
    q.await(
        function(error) {
            // Save any error messages
            var error_messages = [];
            if (error)
                error_messages.push(error);

            // Store data for filtering
            var file_data = [];
            // skip 0 - error variable
            for (var i = 1; i < arguments.length; i++) {
                var file = arguments[i];
                file_data[file.display_order] = file;
            }

            // Is tracking loaded_data useful?
            var loaded_data = [];
            for (var display_order in file_data) {
                var file = file_data[display_order];
                var dr_id = file.dr_id;

                if (dr_id != "rollup") {
                    if (loaded_data[dr_id] == undefined) {
                        // console.log('Plotting GCMS xy: ' + dr_id);

                        var columns = ODRGraph_GCMSModifyFile(file, chart_obj);
                        if ( columns['errors'] !== undefined )
                            error_messages = columns['errors'];

                        if ( error_messages.length == 0 ) {
                            // Build the trace object for Plotly
                            var trace = {};
                            trace.x = columns['gcms_secondary'][x_value][0];
                            trace.y = columns['gcms_secondary'][x_value][1];
                            trace.name = file.legend;

                            trace.type = 'bar';
                            trace.orientation = 'v';

                            // Add line to chart data
                            chart_data.push(trace);

                            // Store that this data is loaded
                            loaded_data[dr_id] = 1;
                        }
                    }
                }
            }

            // Define the axis settings
            var xaxis_settings = {};
            xaxis_settings.showline = true;
            xaxis_settings.showgrid = true;
            xaxis_settings.zeroline = false;
            xaxis_settings.title = chart_obj.lower_x_axis_caption;
            xaxis_settings.autottick = true;

            var yaxis_settings = {};
            yaxis_settings.showline = true;
            yaxis_settings.showgrid = true;
            yaxis_settings.zeroline = false;
            yaxis_settings.title = chart_obj.lower_y_axis_caption;
            yaxis_settings.autottick = true;
            if ( chart_obj.lower_y_axis_log == "yes" )
                yaxis_settings.type = "log";

            var layout = {
                // title: 'Title of the Graph',
                hovermode: 'closest',
                // autosize: true,
                margin: {
                    l: 70,
                    r: 20,
                    b: 70,
                    t: 20,
                    pad: 4
                },
                // paper_bgcolor: '#7f7f7f',
                // plot_bgcolor: '#c7c7c7',
                xaxis: xaxis_settings,
                yaxis: yaxis_settings,

                showlegend: true,
                hoverlabel: {
                    namelength: 50
                },
            };

            if ( error_messages.length > 0 ) {
                // Encountered an error...don't display any data
                chart_data = [];
                layout.annotations = ODRGraph_getErrorMessages(error_messages);
            }

            // Create responsive div for automatic resizing
            var graph_div = plotlyResponsiveDiv(chart_obj, chart_obj.chart_id + "_secondary");
            // console.log( chart_data );
            Plotly.newPlot(graph_div, chart_data, layout);
        }
    )
}