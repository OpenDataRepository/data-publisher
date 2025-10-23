/**
 * Open Data Repository Data Publisher
 * odr_graph_plugin.js
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This file contains the plotly/graphing functions used by the "standard" graph plugin, which has
 * the ability to draw line, bar, histogram, pie, and stacked area plots.
 */

// !!! IMPORTANT: you MUST use 'var' instead of 'let' in this file...phantomJS will break !!!
// !!! IMPORTANT: you CAN'T use optional function arguments either...nothing like function foo(bar, baz = "") !!!

/**
 * Parses the chart object for generic x_axis settings.
 *
 * @param {odrChartObj} chart_obj
 * @returns {object}
 */
function ODRGraph_getXAxisSettings(chart_obj) {
    var xaxis_settings = {};
    xaxis_settings.showline = true;
    xaxis_settings.showgrid = true;
    xaxis_settings.zeroline = false;

    if (chart_obj.x_axis_dir == "desc" && (chart_obj.x_axis_min == "auto" || chart_obj.x_axis_max == "auto"))
        xaxis_settings.autorange = 'reversed';

    if (chart_obj.x_axis_log == "yes")
        xaxis_settings.type = 'log';

    if (chart_obj.x_axis_caption != "")    // TODO - need some way to read the captions from file
        xaxis_settings.title = {text: chart_obj.x_axis_caption};

    if (chart_obj.x_axis_tick_interval != "auto") {
        xaxis_settings.dtick = chart_obj.x_axis_tick_interval;
        xaxis_settings.tick0 = chart_obj.x_axis_tick_start;
    }
    else {
        xaxis_settings.autottick = true;
    }

    if (chart_obj.x_axis_labels != "yes")
        xaxis_settings.showticklabels = false;

    // TODO - is there a way to make x_axis range do something when only one is defined?
    if (chart_obj.x_axis_min != "auto" && chart_obj.x_axis_max != "auto" )
        xaxis_settings.range = [ chart_obj.x_axis_min, chart_obj.x_axis_max ];

    return xaxis_settings;
}

/**
 * Parses the chart object for generic y_axis settings.
 *
 * @param {odrChartObj} chart_obj
 * @returns {object}
 */
function ODRGraph_getYAxisSettings(chart_obj) {
    var yaxis_settings = {};
    yaxis_settings.zeroline = false;

    if (chart_obj.y_axis_dir == "desc" && (chart_obj.y_axis_min == "auto" || chart_obj.y_axis_max == "auto"))
        yaxis_settings.autorange = 'reversed';

    if (chart_obj.y_axis_log == "yes")
        yaxis_settings.type = 'log';

    if (chart_obj.y_axis_caption != "")
        yaxis_settings.title = {text: chart_obj.y_axis_caption};

    if (chart_obj.y_axis_tick_interval != "auto") {
        yaxis_settings.dtick = chart_obj.y_axis_tick_interval;
        yaxis_settings.tick0 = chart_obj.y_axis_tick_start;
    }
    else {
        yaxis_settings.autottick = true;
    }

    if (chart_obj.x_axis_labels != "yes")
        yaxis_settings.showticklabels = false;

    // TODO - is there a way to make y_axis range do something when only one is defined?
    if (chart_obj.y_axis_min != "auto" && chart_obj.y_axis_max != "auto" )
        yaxis_settings.range = [ chart_obj.y_axis_min, chart_obj.y_axis_max ];

    return yaxis_settings;
}

/**
 * Parses the chart object to set up error bars if needed.
 * @param {object} chart_obj
 * @param {array} columns
 * @returns {object|null}
 */
/*
function getErrorBarSettings(chart_obj, columns) {
    // Plotly has a pile of settings for error bars, which ODR compresses slightly
    // If this point is reached, then the chart_obj wants error bars
    var error_y = {
        visible: true,
    };

    // ODR has already guaranteed that these values aren't completely mismatched...either both +/-
    //  are going to define data, or both of them are going to define values.  The values could be
    //  different, however, in which case the error bars are asymmetric.
    // Additionally, if the error bars are supposed to come from columns of data, then ODR has
    //  already converted them into zero-indexed values

    // Set whether the error bars are going to be symmetric...
    error_y.symmetric = true;
    if ( chart_obj.error_bar_plus_value !== chart_obj.error_bar_minus_value )
        error_y.symmetric = false;

    // Determine the source of data for the error bars
    if ( chart_obj.error_bar_plus_type === "data" ) {
        error_y.type = "data";
        error_y.array = columns[ chart_obj.error_bar_plus_value ];
    }
    else {
        var value = chart_obj.error_bar_plus_value;
        if ( value === "sqrt" ) {
            error_y.type = "sqrt";
        }
        else if ( value.includes("%") !== false ) {
            error_y.type = "percent";
            error_y.value = value.substring(0, (value.length-1));
        }
        else {
            error_y.type = "constant";
            error_y.value = value;
        }
    }

    if ( !error_y.symmetric ) {
        if ( chart_obj.error_bar_minus_type === "data" )
            error_y.arrayminus = columns[ chart_obj.error_bar_minus_value ];
        else if ( chart_obj.error_bar_minus_type === "value" ) {
            var value = chart_obj.error_bar_minus_value;
            // Don't need to set the "type" attribute again, and "sqrt" is not valid for asymmetric
            if ( value.includes("%") !== false )
                error_y.valueminus = value.substring(0, (value.length-1));
            else
                error_y.valueminus = value;
        }
    }

    return error_y;
}
*/

/**
 * Updates the interactive graph controls on the page.
 * TODO - going to need an additional 2 selects and 2 text inputs in order to change error columns
 *
 * @param {odrChartObj} chart_obj
 * @param {string} chart_type
 * @param {odrDataFile} file
 * @returns {array}
 */
function ODRGraph_updateSelectedColumns(chart_obj, chart_type, file) {
    var chart_id = chart_obj.chart_id;
    var num_selectors = 2;

    var ids = [];
    var selected_values = [];
    for (var i = 1; i <= num_selectors; i++) {
        var id = "#" + chart_id + "_column_" + i;
        ids.push(id);
        // console.log( 'pushing ' + id );

        // If the headers variable is defined, then reset the column names in the dropdowns
        if ( file.new_file === true ) {
            $(id).children('option').remove();

            for (var j = 0; j < file.headers.length; j++) {
                var element = $("<option>", {"value": j, "html": file.headers[j]});
                $(id).append(element);
            }
        }
    }

    if ( file.new_file === true ) {
        // If the column headers were reset, then select the default options

        // Select the current graph type
        $("#" + chart_id + "_graph_type").children('option').each(function(index, elem) {
            if ( $(elem).val() === chart_type )
                $(elem).prop('selected', true);
            else
                $(elem).prop('selected', false);
        });

        // The x_column is straightforward...all of the column numbers in the chart_obj are 1-indexed,
        //  but the values given to plotly should be 0-indexed instead
        var default_x_column = Number(chart_obj.x_values_column) - 1;
        // Select the correct option for the desired x_column in the interactive graph popup
        $(ids[0] + " option:eq(" + default_x_column + ")").prop('selected', true);
        // The selected x_column is always the first value in this variable
        selected_values[0] = default_x_column;

        // The y_columns are more complicated...want to use column names if they're present
        var used_column_names = false;
        if (chart_obj.y_value_columns_start !== '' && chart_obj.y_value_columns_end !== '') {
            // ...the column names may not exist in the file, though
            var start_column = null;
            var end_column = null;
            for (var column_id in file.headers) {
                var column_name = file.headers[column_id].replaceAll(/[ \n\t\r]/g, '').toLowerCase();
                if (chart_obj.y_value_columns_start === column_name)
                    start_column = parseInt(column_id);
                if (chart_obj.y_value_columns_end === column_name)
                    end_column = parseInt(column_id);
            }

            if ( start_column !== null && end_column !== null ) {
                // Since both the start and end columns are in the file, they can get used
                used_column_names = true;

                // May not want the columns being named to be included in the graph...
                if ( chart_obj.y_value_columns_type === 'exclusive' ) {
                    start_column += 1;
                    end_column -= 1;
                }

                // Select the correct option for each y_column in the interactive graph popup
                for (var i = start_column; i <= end_column; i++) {
                    $(ids[1] + " option:eq(" + i + ")").prop('selected', true);
                    selected_values.push( i );
                }
            }
        }

        if ( !used_column_names ) {
            // Since column names weren't used, fall back to column numbers...
            if ( chart_obj.y_values_column.match(/,/) ) {
                // Multiple y columns...
                var y_columns = chart_obj.y_values_column.split(/,/);
                for (var i = 0; i < y_columns.length; i++) {
                    // Select the correct option for this y_column in the interactive graph popup
                    $(ids[1] + " option:eq(" + (y_columns[i] - 1) + ")").prop('selected', true);
                    selected_values.push( y_columns[i] - 1 );
                }
            }
            else {
                var default_y_column = Number(chart_obj.y_values_column) - 1;
                // Select the correct option for the desired y_column in the interactive graph popup
                $(ids[1] + " option:eq(" + default_y_column + ")").prop('selected', true);
                selected_values[1] = default_y_column;
            }
        }
    }
    else {
        // If this is a subsequent selection on the interactive graph, then determine which columns
        //  the user has selected...do the x_column first...
        selected_values[0] = $(ids[0]).val();

        // There might be multiple selected y_columns...
        var selected_y_columns = $(ids[1]).val();
        if ( Array.isArray(selected_y_columns) ) {
            // ...if so, then each of them need to go into the array for plotly to use
            selected_y_columns.forEach((elem) => {
                selected_values.push(elem);
            });
        }
        else {
            // ...but there's only a single selection, so don't need to get fancy
            selected_values[1] = selected_y_columns;
        }
    }
    // console.log( 'selected_values', selected_values );

    // Show settings specific to the current graph type
    $("." + chart_id + "_settings").addClass('ODRHidden');
    if ( chart_type === 'histogram' )
        $("#" + chart_id + "_histogram_settings").removeClass('ODRHidden');
    else if ( chart_type === 'bar' )
        $("#" + chart_id + "_bar_settings").removeClass('ODRHidden');
    else if ( chart_type === 'xy' )
        $("#" + chart_id + "_line_settings").removeClass('ODRHidden');

    // Re-enable and relabel the selectors based on the current graph type
    $("#" + chart_id + "_settings").find(".graph_columns").addClass('ODRHidden');
    if ( chart_type === 'histogram' ) {
        // Histograms only read one column
        $(ids[0] + "_label").html("values: ").removeClass('ODRHidden');
        $(ids[0]).removeClass('ODRHidden');
    }
    else if ( chart_type === 'pie' ) {
        // Pie charts need two columns
        $(ids[0] + "_label").html("values: ").removeClass('ODRHidden');
        $(ids[0]).removeClass('ODRHidden');
        $(ids[1] + "_label").html("labels: ").removeClass('ODRHidden');
        $(ids[1]).removeClass('ODRHidden');
    }
    else {
        // The other graph types need x/y columns  TODO - error selectors for these?
        $(ids[0] + "_label").html("x values: ").removeClass('ODRHidden');
        $(ids[0]).removeClass('ODRHidden');
        $(ids[1] + "_label").html("y values: ").removeClass('ODRHidden');
        $(ids[1]).removeClass('ODRHidden');
    }

    return selected_values;
}

/**
 * Renders the given chart object as a Histogram plot.
 * @param {odrChartObj} chart_obj
 * @param {function} onComplete
 */
function ODRGraph_histogramChartPlotly(chart_obj, onComplete) {
    // console.log('plotting histogram chart');
    // console.log( JSON.stringify(chart_obj) );

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

            // May not always want to show the legend...
            var show_legend = false;

            var loaded_data = [];
            for (var display_order in file_data) {
                var file = file_data[display_order];
                var dr_id = file.dr_id;

                if (dr_id != "rollup") {
                    if (loaded_data[dr_id] == undefined) {
                        // console.log('Plotting histogram: ' + dr_id);

                        var columns = file.columns;
                        var selected_columns = ODRGraph_updateSelectedColumns(chart_obj, "histogram", file);
                        var data_column = selected_columns[0];

                        if ( data_column === '' || !Number.isInteger( Number(data_column) ) )
                            error_messages.push("The file for \"" + file.legend + "\" can't identify a column with the string \"" + data_column + "\"");
                        else if ( columns[data_column] === undefined )
                            error_messages.push("The file for \"" + file.legend + "\" does not have data in column " + (data_column+1));

                        // Build the trace object for Plotly
                        var trace = {};
                        var dir = 'v';
                        if ( $("#" + chart_obj.chart_id + "_histogram_dir").length > 0 )
                            dir = $("#" + chart_obj.chart_id + "_histogram_dir").val();
                        else if (chart_obj.bar_type !== undefined && chart_obj.histogram_dir === "horizontal")
                            trace.orientation = 'h';

                        if (dir === 'h')
                            trace.y = columns[data_column];
                        else
                            trace.x = columns[data_column];

                        trace.opacity = '0.6';
                        trace.type = 'histogram';
                        trace.name = file.legend;

                        // Only show the legend if there's text there
                        if ( trace.name !== '' )
                            show_legend = true;

                        // Add line to chart data
                        chart_data.push(trace);

                        // Store that this data is loaded
                        loaded_data[dr_id] = 1;
                    }
                }
            }

            // Get the axis settings from the chart object
            var xaxis_settings = ODRGraph_getXAxisSettings(chart_obj);
            var yaxis_settings = ODRGraph_getYAxisSettings(chart_obj);

            var layout = {
                hovermode: 'closest',
                margin: {
                    l: 70,
                    r: 20,
                    b: 70,
                    t: 20,
                    pad: 4
                },
                bargap: 0.05,
                bargroupgap: 0.2,

                xaxis: xaxis_settings,
                yaxis: yaxis_settings,

                showlegend: show_legend,
                hoverlabel: {
                    namelength: 50,
                    font: {
                        size: 20,
                    }
                },
            };

            // Histogram-specific layout settings...
            if ( $("#" + chart_obj.chart_id + "_histogram_stack").length > 0 )
                layout.barmode = $("#" + chart_obj.chart_id + "_histogram_stack").val();
            else if (chart_obj.histogram_stack === undefined)
                layout.barmode = 'group';
            else {
                if (chart_obj.histogram_stack === "stacked")
                    layout.barmode = "stack";
                else if (chart_obj.histogram_stack === "overlay")
                    layout.barmode = "overlay";
                else
                    layout.barmode = 'group';
            }

            if ( error_messages.length > 0 ) {
                // Encountered an error...don't display any data
                chart_data = [];
                layout.annotations = ODRGraph_getErrorMessages(error_messages);
            }

            // Create responsive div for automatic resizing
            var graph_div = plotlyResponsiveDiv(chart_obj);
            Plotly.newPlot(graph_div, chart_data, layout).then(function() {
                onComplete(chart_obj)
            });
        }
    )
}

/**
 * Renders the given chart object as a Bar plot.
 * @param {odrChartObj} chart_obj
 * @param {function} onComplete
 */
function ODRGraph_barChartPlotly(chart_obj, onComplete) {
    // console.log('plotting bar chart');
    // console.log( JSON.stringify(chart_obj) );
/*
    // The error bar values have been sanitized in the Graph Plugin...
    var error_bar_plus_type = chart_obj.error_bar_plus_type;
    var error_bar_plus_value = chart_obj.error_bar_plus_value;
    var error_bar_minus_type = chart_obj.error_bar_minus_type;
    var error_bar_minus_value = chart_obj.error_bar_minus_value;
*/
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

            // May not always want to show the legend...
            var show_legend = false;

            // Is tracking loaded_data useful?
            var loaded_data = [];
            for (var display_order in file_data) {
                var file = file_data[display_order];
                var dr_id = file.dr_id;

                if (dr_id != "rollup") {
                    if (loaded_data[dr_id] == undefined) {
                        // console.log('Plotting bar: ' + dr_id);

                        var columns = file.columns;
                        var selected_columns = ODRGraph_updateSelectedColumns(chart_obj, "bar", file);

                        $.each(selected_columns, function(index, column_id) {
                            if ( column_id === '' || !Number.isInteger( Number(column_id) ) )
                                error_messages.push("The file for \"" + file.legend + "\" can't identify a column with the string \"" + column_id + "\"");
                            else if ( columns[column_id] === undefined )
                                error_messages.push("The file for \"" + file.legend + "\" does not have data for column " + (column_id+1));
                        });

                        if ( selected_columns.length < 2 )
                            error_messages.push("Unable to plot a \"bar\" graph for \"" + file.legend + "\" with only one column selected");

                        // The column to use for the x axis will be the first entry in selected_columns...
                        var x_column = selected_columns[0];
/*
                        if ( error_bar_plus_type === 'data' ) {
                            if ( error_bar_plus_value === '' || !Number.isInteger( Number(error_bar_plus_value) ) )
                                error_messages.push("The file for \"" + file.legend + "\" can't identify a column with the string \"" + error_bar_plus_value + "\"");
                            else if ( columns[error_bar_plus_value] === undefined )
                                error_messages.push("The file for \"" + file.legend + "\" does not have data for column " + (error_bar_plus_value+1));
                        }

                        if ( error_bar_minus_type === 'data' ) {
                            if ( error_bar_minus_value === '' || !Number.isInteger( Number(error_bar_minus_value) ) )
                                error_messages.push("The file for \"" + file.legend + "\" can't identify a column with the string \"" + error_bar_minus_value + "\"");
                            else if ( columns[error_bar_minus_value] === undefined )
                                error_messages.push("The file for \"" + file.legend + "\" does not have data for column " + (error_bar_minus_value+1));
                        }
*/
                        // ...but there could be multiple columns from the same file that the user
                        //  wants to use for the y axis, and each of them need their own trace...
                        for (var i = 1; i < selected_columns.length; i++) {
                            var y_column = selected_columns[i];

                            // Build the trace object for Plotly
                            var trace = {};
                            trace.x = columns[x_column];
                            trace.y = columns[y_column];
                            trace.type = 'bar';
/*
                            if ( chart_obj.error_bar_plus_type !== "none" ) {
                                // If error bars are required, then translate the provided options into
                                //  something Plotly understands
                                trace.error_y = getErrorBarSettings(chart_obj, columns);
                            }
*/
                            if ( $("#" + chart_obj.chart_id + "_bar_type").length > 0 )
                                trace.orientation = $("#" + chart_obj.chart_id + "_bar_type").val();
                            else if (chart_obj.bar_type === undefined)
                                trace.orientation = 'v';
                            else {
                                if (chart_obj.bar_type === "horizontal")
                                    trace.orientation = 'h';
                                else
                                    trace.orientation = 'v';
                            }

                            // Name used for grouping bars
                            trace.name = file.legend;

                            // Only show the legend if there's text there
                            if ( trace.name !== '' )
                                show_legend = true;

                            // Add line to chart data
                            chart_data.push(trace);

                            // Store that this data is loaded
                            loaded_data[dr_id] = 1;
                        }
                    }
                }
            }

            // Get the axis settings from the chart object
            var xaxis_settings = ODRGraph_getXAxisSettings(chart_obj);
            var yaxis_settings = ODRGraph_getYAxisSettings(chart_obj);

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

                showlegend: show_legend,
                hoverlabel: {
                    namelength: 50,
                    font: {
                        size: 20,
                    }
                },
            };

            // NOTE: "stacked" barmode only works when multiple columns from the same file are graphed
            // It won't stack data from multiple files together
            if ( $("#" + chart_obj.chart_id + "_bar_options").length > 0 )
                layout.barmode = $("#" + chart_obj.chart_id + "_bar_options").val();
            else if (chart_obj.bar_options === undefined)
                layout.barmode = 'group';
            else {
                if (chart_obj.bar_options === "stacked")
                    layout.barmode = 'stack';
                else
                    layout.barmode = 'group';
            }

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
        }
    )
}

/**
 * Renders the given chart object as a Pie chart.
 * @param {odrChartObj} chart_obj
 * @param {function} onComplete
 */
function ODRGraph_pieChartPlotly(chart_obj, onComplete) {
    // console.log('plotting pie chart');
    // console.log( JSON.stringify(chart_obj) );

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
                        // console.log('Plotting pie: ' + dr_id);

                        var columns = file.columns;
                        var selected_columns = ODRGraph_updateSelectedColumns(chart_obj, "piechart", file);
                        var data_column = selected_columns[0];
                        var labels_column = selected_columns[1];

                        if ( selected_columns.length < 2 )
                            error_messages.push("Unable to plot an \"pie\" graph for \"" + file.legend + "\" with only one column selected");

                        if ( data_column === '' || !Number.isInteger( Number(data_column) ) )
                            error_messages.push("The file for \"" + file.legend + "\" can't identify a column with the string \"" + data_column + "\"");
                        else if ( columns[data_column] === undefined )
                            error_messages.push("The file for \"" + file.legend + "\" does not have data for column " + (data_column+1));
                        else if ( columns[data_column].length > 100 )
                            error_messages.push("Not creating a pie chart from the file \"" + file.legend + "\" because it would have more than 100 slices");

                        if ( selected_columns.length >= 2 ) {
                            if ( labels_column === '' || !Number.isInteger( Number(labels_column) ) )
                                error_messages.push("The file for \"" + file.legend + "\" can't identify a column with the string \"" + labels_column + "\"");
                            else if ( columns[labels_column] === undefined )
                                error_messages.push("The file for \"" + file.legend + "\" does not have data for column " + (labels_column+1));
                        }


                        // Build the trace object for Plotly
                        var trace = {};
                        trace.type = 'pie';
                        trace.values = columns[data_column];
                        trace.labels = columns[labels_column];

                        // Add line to chart data
                        chart_data.push(trace);

                        // Store that this data is loaded
                        loaded_data[dr_id] = 1;
                    }
                }
            }

            var layout = {
                // title: 'Title of the Graph',
                hovermode: 'closest',
                // autosize: true,
                margin: {
                    l: 30,
                    r: 30,
                    b: 30,
                    t: 30,
                    pad: 4
                },
                // paper_bgcolor: '#7f7f7f',
                // plot_bgcolor: '#c7c7c7',

                showlegend: true,
                hoverlabel: {
                    namelength: 50,
                    font: {
                        size: 20,
                    }
                },
            };

            if ( error_messages.length > 0 ) {
                // Encountered an error...don't display any data
                chart_data = [];
                layout.annotations = ODRGraph_getErrorMessages(error_messages);
            }

            // Create responsive div for automatic resizing
            var graph_div = plotlyResponsiveDiv(chart_obj);
            Plotly.newPlot(graph_div, chart_data, layout).then(function() {
                onComplete(chart_obj)
            });
        }
    )
}

/**
 * Renders the given chart object as an xy plot.
 * @param {odrChartObj} chart_obj
 * @param {function} onComplete
 */
function ODRGraph_lineChartPlotly(chart_obj, onComplete) {
    // console.log('plotting line chart');
    // console.log( JSON.stringify(chart_obj) );
/*
    // The error bar values have been sanitized in the Graph Plugin...
    var error_bar_plus_type = chart_obj.error_bar_plus_type;
    var error_bar_plus_value = chart_obj.error_bar_plus_value;
    var error_bar_minus_type = chart_obj.error_bar_minus_type;
    var error_bar_minus_value = chart_obj.error_bar_minus_value;
*/
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

            // Normalizing the y axis has consequences both in trace creation and layout settings
            // ...plotly requires each set of data to reference its own y-axis
            var normalize_y_axis = false;
            if ( $("#" + chart_obj.chart_id + "_normalize_y").length > 0 ) {
                // Need to ensure the dynamic option can override the plugin option
                if ( $("#" + chart_obj.chart_id + "_normalize_y").is(':checked') )
                    normalize_y_axis = true;
                else
                    normalize_y_axis = false;
            }
            else if ( chart_obj.normalize_y_axis === "yes" ) {
                // Otherwise, fall back to the plugin option
                normalize_y_axis = true;
            }

            // May not always want to show the legend...
            var show_legend = false;

            // Is tracking loaded_data useful?
            var trace_count = 0;
            var loaded_data = [];
            for (var display_order in file_data) {
                var file = file_data[display_order];
                var dr_id = file.dr_id;

                if (dr_id != "rollup") {
                    if (loaded_data[dr_id] == undefined) {
                        // console.log('Plotting xy: ' + dr_id);

                        var columns = file.columns;
                        var selected_columns = ODRGraph_updateSelectedColumns(chart_obj, "xy", file);

                        /*
                        // Just skip column id it doesn't exist
                        $.each(selected_columns, function(index, column_id) {
                            if ( column_id === '' || !Number.isInteger( Number(column_id) ) )
                                error_messages.push("The file for \"" + file.legend + "\" can't identify a column with the string \"" + column_id + "\"");
                            else if ( columns[column_id] === undefined )
                                error_messages.push("The file for \"" + file.legend + "\" does not have data for column " + (column_id+1));
                        });
                         */

                        if ( selected_columns.length < 2 )
                            error_messages.push("Unable to plot an \"xy\" graph for \"" + file.legend + "\" with only one column selected");

                        // The column to use for the x axis will be the first entry in selected_columns...
                        var x_column = selected_columns[0];
/*
                        if ( error_bar_plus_type === 'data' ) {
                            if ( error_bar_plus_value === '' || !Number.isInteger( Number(error_bar_plus_value) ) )
                                error_messages.push("The file for \"" + file.legend + "\" can't identify a column with the string \"" + error_bar_plus_value + "\"");
                            else if ( columns[error_bar_plus_value] === undefined )
                                error_messages.push("The file for \"" + file.legend + "\" does not have data for column " + (error_bar_plus_value+1));
                        }

                        if ( error_bar_minus_type === 'data' ) {
                            if ( error_bar_minus_value === '' || !Number.isInteger( Number(error_bar_minus_value) ) )
                                error_messages.push("The file for \"" + file.legend + "\" can't identify a column with the string \"" + error_bar_minus_value + "\"");
                            else if ( columns[error_bar_minus_value] === undefined )
                                error_messages.push("The file for \"" + file.legend + "\" does not have data for column " + (error_bar_minus_value+1));
                        }
*/

                        // ...but there could be multiple columns from the same file that the user
                        //  wants to use for the y axis, and each of them need their own trace...
                        for (var i = 1; i < selected_columns.length; i++) {
                            var y_column = selected_columns[i];

                            // Build the trace object for Plotly
                            var trace = {};
                            trace.x = columns[x_column];
                            trace.y = columns[y_column];

                            // If plotting more than one column from the file, then need to append
                            //  the column name after the default legend value
                            trace.name = file.legend;
                            if ( selected_columns.length > 2 )
                                trace.name = file.legend + ' ' + file.headers[ selected_columns[i] ];

                            // Only show the legend if there's text there
                            if ( trace.name !== '' )
                                show_legend = true;

                            // Want to use WebGL if at all possible, but need the ability to disable
                            //  it because phantomJS only works when rendering SVG
                            if ( $("#" + chart_obj.chart_id + "_disable_scatterGL").length == 0 || !$("#" + chart_obj.chart_id + "_disable_scatterGL").is(':checked') )
                                trace.type = 'scattergl';
                            else
                                trace.type = 'scatter';

                            if ( $("#" + chart_obj.chart_id + "_line_type").length > 0 )
                                trace.mode = $("#" + chart_obj.chart_id + "_line_type").val();
                            else if (chart_obj.line_type !== undefined)
                                trace.mode = chart_obj.line_type;    // NOTE: if trace.mode == 'lines', then it's an "xy" plot.  if trace.mode == 'markers', then it's more of a "scatter" plot
                            else
                                trace.mode = 'lines';

                            trace_count++;
                            if ( normalize_y_axis ) {
                                if (trace_count === 1) {
                                    // Use the default value for first set of data
                                    trace.yaxis = 'y';
                                }
                                else {
                                    // Subsequent sets of data get "y2", "y3", "y4", etc
                                    trace.yaxis = 'y' + trace_count.toString();
                                }
                            }
/*
                            else if ( chart_obj.error_bar_plus_type !== "none" ) {
                                // If error bars are required, then translate the provided options into
                                //  something Plotly understands
                                trace.error_y = getErrorBarSettings(chart_obj, columns);
                            }
*/

                            // Add line to chart data
                            chart_data.push(trace);

                            // Store that this data is loaded
                            loaded_data[dr_id] = 1;
                        }
                    }
                }
            }

            // Get the axis settings from the chart object
            var xaxis_settings = ODRGraph_getXAxisSettings(chart_obj);
            var yaxis_settings = ODRGraph_getYAxisSettings(chart_obj);

            // The "Normalize Y Axis" setting can require changes to the axis settings...
            if ( !normalize_y_axis || trace_count < 2 ) {
                // Gridlines and ticks make sense here because all files are going to be displayed
                //  with the exact same y-axis scaling
                yaxis_settings.showline = true;
                yaxis_settings.showgrid = true;

                // Also, always display y-axis markers when there's only one file
            }
            else {
                // These settings don't make sense when each file being graphed has its own
                //  y-axis scaling...
                yaxis_settings.showline = false;
                yaxis_settings.showgrid = false;
                yaxis_settings.showticklabels = false;
                yaxis_settings.visible = true;
            }

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

                showlegend: show_legend,
                hoverlabel: {
                    namelength: 50,
                    font: {
                        size: 20,
                    }
                },
            };

            // When each set of data is being graphed with its own y-axis...
            if ( normalize_y_axis ) {
                // ...then plotly requires a separate yaxis settings object for each set of data
                var axis_basestr = 'yaxis';
                for (var i = 1; i <= trace_count; i++) {
                    // The very first set of data doesn't need modified settings...
                    if (i > 1) {
                        // ...but all sets of data after the first need an additional property

                        //  Need to create a copy of the original y-axis settings object...
                        // NOTE - could also use json stringify() then json parse(), since this doesn't have any dates
                        var settings_copy = jQuery.extend({}, yaxis_settings);
                        var axis_str = axis_basestr + i.toString();

                        layout[axis_str] = settings_copy;

                        if ( i >= 2 ) {
                            // All sets of data after the first need to overlay the ORIGINAL y-axis
                            // Apparently, attempting to overlay "y2" or "y3" only results in
                            //  displaying the very last plot
                            layout[axis_str].overlaying = 'y';
                        }
                    }
                }
            }

            if ( error_messages.length > 0 ) {
                // Encountered an error...don't display any data
                chart_data = [];
                layout.annotations = ODRGraph_getErrorMessages(error_messages);
            }

            // Create responsive div for automatic resizing
            var graph_div = plotlyResponsiveDiv(chart_obj);
            // console.log( JSON.stringify(graph_div) );
            // console.log( JSON.stringify(chart_data) );
            // console.log( JSON.stringify(layout) );
            Plotly.newPlot(graph_div, chart_data, layout).then(
                onComplete(chart_obj)
            );
        }
    )
}

/**
 * Renders the given chart object as a StackedArea plot.
 * @param {odrChartObj} chart_obj
 * @param {function} onComplete
 */
function ODRGraph_stackedAreaChartPlotly(chart_obj, onComplete) {
    // console.log('plotting stacked area chart');
    // console.log( JSON.stringify(chart_obj) );
/*
    // The error bar values have been sanitized in the Graph Plugin...
    var error_bar_plus_type = chart_obj.error_bar_plus_type;
    var error_bar_plus_value = chart_obj.error_bar_plus_value;
    var error_bar_minus_type = chart_obj.error_bar_minus_type;
    var error_bar_minus_value = chart_obj.error_bar_minus_value;
*/
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

            // May not always want to show the legend...
            var show_legend = false;

            // Is tracking loaded_data useful?
            var loaded_data = [];
            for (var display_order in file_data) {
                var file = file_data[display_order];
                var dr_id = file.dr_id;

                if (dr_id != "rollup") {
                    if (loaded_data[dr_id] == undefined) {
                        // console.log('Plotting stacked area: ' + dr_id);

                        var columns = file.columns;
                        var selected_columns = ODRGraph_updateSelectedColumns(chart_obj, "stackedarea", file);

                        $.each(selected_columns, function(index, column_id) {
                            if ( column_id === '' || !Number.isInteger( Number(column_id) ) )
                                error_messages.push("The file for \"" + file.legend + "\" can't identify a column with the string \"" + column_id + "\"");
                            else if ( columns[column_id] === undefined )
                                error_messages.push("The file for \"" + file.legend + "\" does not have data for column " + (column_id+1));
                        });

                        if ( selected_columns.length < 2 )
                            error_messages.push("Unable to plot an \"stackedarea\" graph for \"" + file.legend + "\" with only one column selected");

                        // The column to use for the x axis will be the first entry in selected_columns...
                        var x_column = selected_columns[0];
/*
                        if ( error_bar_plus_type === 'data' ) {
                            if ( error_bar_plus_value === '' || !Number.isInteger( Number(error_bar_plus_value) ) )
                                error_messages.push("The file for \"" + file.legend + "\" can't identify a column with the string \"" + error_bar_plus_value + "\"");
                            else if ( columns[error_bar_plus_value] === undefined )
                                error_messages.push("The file for \"" + file.legend + "\" does not have data for column " + (error_bar_plus_value+1));
                        }

                        if ( error_bar_minus_type === 'data' ) {
                            if ( error_bar_minus_value === '' || !Number.isInteger( Number(error_bar_minus_value) ) )
                                error_messages.push("The file for \"" + file.legend + "\" can't identify a column with the string \"" + error_bar_minus_value + "\"");
                            else if ( columns[error_bar_minus_value] === undefined )
                                error_messages.push("The file for \"" + file.legend + "\" does not have data for column " + (error_bar_minus_value+1));
                        }
*/

                        // ...but there could be multiple columns from the same file that the user
                        //  wants to use for the y axis, and each of them need their own trace...
                        for (var i = 1; i < selected_columns.length; i++) {
                            var y_column = selected_columns[i];

                            // Build the trace object for Plotly
                            var trace = {};
                            trace.x = columns[x_column];
                            trace.y = columns[y_column];
                            trace.fill = 'tozeroy';
                            trace.mode = 'lines';

                            // Want to use WebGL if at all possible, but need the ability to disable
                            //  it because phantomJS only works when rendering SVG
                            if ( $("#" + chart_obj.chart_id + "_disable_scatterGL").length > 0 && !$("#" + chart_obj.chart_id + "_disable_scatterGL").is(':checked') )
                                trace.type = 'scattergl';
                            else
                                trace.type = 'scatter';
/*
                            if ( chart_obj.error_bar_plus_type !== "none" ) {
                                // If error bars are required, then translate the provided options into
                                //  something Plotly understands
                                trace.error_y = getErrorBarSettings(chart_obj, columns);
                            }
*/
                            // Name used for grouping bars
                            trace.name = file.legend;

                            // Only show the legend if there's text there
                            if ( trace.name !== '' )
                                show_legend = true;

                            // Add line to chart data
                            chart_data.push(trace);

                            // Store that this data is loaded
                            loaded_data[dr_id] = 1;
                        }
                    }
                }
            }

            // Get the axis settings from the chart object
            var xaxis_settings = ODRGraph_getXAxisSettings(chart_obj);
            var yaxis_settings = ODRGraph_getYAxisSettings(chart_obj);

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

                showlegend: show_legend,
                hoverlabel: {
                    namelength: 50,
                    font: {
                        size: 20,
                    }
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
        }
    )
}
