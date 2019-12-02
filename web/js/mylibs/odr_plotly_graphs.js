/**
 * Created by nate on 10/31/16.
 */



// Global Definitions
if (ODR_PLOTLY_GLOBALS == undefined) {
    var WIDTH_IN_PERCENT_OF_PARENT = 100;
    var HEIGHT_IN_PERCENT_OF_PARENT = 100;

    function plotlyResponsiveDiv(chart_obj) {
        /*
        // d3.v3 version
        var gd3 = d3.select("#" + chart_obj.chart_id)
        .append('div')
        .style({
            'width': WIDTH_IN_PERCENT_OF_PARENT + '%',
            'margin-left': (100 - WIDTH_IN_PERCENT_OF_PARENT) / 2 + '%',
            'height': HEIGHT_IN_PERCENT_OF_PARENT + '%',
            'margin-top': '0%'
        });


        console.log($(gd3).parent().html())

        var gd = gd3.node()
        page_plots.push(gd)
        return gd
        */

        // D3.v4 Version
        console.log(d3.version);
        var gd3 = d3.select("#" + chart_obj['chart_id'])
            .append('div')
            .style('width', WIDTH_IN_PERCENT_OF_PARENT + '%')
            .style('margin-left', (100 - WIDTH_IN_PERCENT_OF_PARENT) / 2 + '%')
            .style('height', HEIGHT_IN_PERCENT_OF_PARENT + '%')
            .style('margin-top', '0%');

        // console.log( gd3.node().innerHTML);

        var gd = gd3.node();
        page_plots.push(gd);
        return gd;


        // jQuery Version
        /*
        var gd3 = $("#" + chart_obj.chart_id)
            .append('<div class="testbitch">asdf</div>')

        $(gd3).css('width', WIDTH_IN_PERCENT_OF_PARENT + '%')
        $(gd3).css('margin-left', (100 - WIDTH_IN_PERCENT_OF_PARENT) / 2 + '%')
        $(gd3).css('height', HEIGHT_IN_PERCENT_OF_PARENT + '%')
        $(gd3).css('margin-top', '0%')

        console.log($(gd3).parent().html())

        var gd = $(gd3)
        page_plots.push(gd)
        return gd
        */
    }

    var ODR_PLOTLY_GLOBALS = true
}

var clearPlotlyBars = function(chart_obj) {
    $("#plotlybars_" + chart_obj.chart_id).hide()
}

var preparePlotlyStatic = function(chart_obj) {
    console.log('Removing divs')
    // Need to remove non-svg items from Plotly Output
    // Remove the Modebar Stuff
    var svgs = $("#" + chart_obj.chart_id + " svg.main-svg")
    $("#" + chart_obj.chart_id + " div.js-plotly-plot").before(svgs)
    $("#" + chart_obj.chart_id + " div.modebar").remove()
    // Remove divs (not-SVG Compliant)
    $("#" + chart_obj.chart_id + " div").remove()
    // Add viewBox="0 0 1400 450" preserveAspectRatio="xMinYMin meet"
    var main_svg = $("#" + chart_obj.chart_id)
    $(main_svg).attr('preserveAspectRatio', 'xMinyMin meet')
    // $(main_svg).attr('viewBox', '0 0 ' + chart_obj.graph_width * 1.5  + ' ' + chart_obj.graph_height * 1.5)
    $(main_svg).attr('viewBox', '0 0 ' + chart_obj.graph_width  + ' ' + chart_obj.graph_height)

    console.log('appending div')
    $('body').append('<div id="PlotlyDone"></div>');
}

function odrCSV(dr_id, display_order, file, callback) {

/*
    // d3.v3 version
    return d3.xhr(file.url).get(function (err, response) {
        // console.log(file.url);
        var dirtyCSV = response.responseText;

        // Strip Headers
        var cleanCSV = [];
        var tmpCSV = dirtyCSV.split('\n');
        tmpCSV.forEach(function(line) {
            if(!line.match(/^#/)) {
                cleanCSV.push(line)
            }
        });

        var data_file = {};
        data_file.dr_id = dr_id;
        data_file.display_order = display_order;
        data_file.url = file.url;
        data_file.legend = file.legend;
        data_file.lines = cleanCSV;

        console.log("Lines found: " + cleanCSV.length);
        return callback(null, data_file);
    });
*/

    // D3.v4 Version
    d3.request(file.url)
        // Handle Error
        .on('error', function(error) {
            callback(error)
        })
        // Parse File
        .on("load", function(xhr){
            console.log(file.url);

            var dirtyCSV = xhr.responseText;
            // Strip Headers
            var cleanCSV = [];
            var tmpCSV = dirtyCSV.split('\n');
            tmpCSV.forEach(function(line) {
                if(!line.match(/^#/)) {
                    cleanCSV.push(line)
                }
            });

            var data_file = {};
            data_file.dr_id = dr_id;
            data_file.display_order = display_order;
            data_file.url = file.url;
            data_file.legend = file.legend;
            data_file.lines = cleanCSV;

            console.log("Lines found: " + cleanCSV.length);
            callback(null, data_file)
        })
        .send("GET");

}

function histogramChartPlotly(chart_obj, onComplete) {

    var chart_data = [];

    var q = d3.queue();
    for (var sort_order in chart_obj.data_files) {
        var obj = chart_obj.data_files[sort_order];
        var dr_id = obj['dr_id'];

        q.defer(odrCSV, dr_id, sort_order, obj);
    }

    // Load the data asynchronously and plot when ready
    q.await(
        function(error) {
            if (error) {
                // We can't proceed
                // Should display error message
            }

            // Store data for filtering
            var file_data = [];
            // skip 0 - error variable
            for (i = 1; i < arguments.length; i++) {
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
                        console.log('Plotting histogram: ' + dr_id);

                        var lines = file.lines;
                        var values = [];
                        for (var i = 0; i < lines.length; i++) {
                            // Skip commented lines...
                            if ( lines[i].match(/^#/) )
                                continue;

                            // No separators for histograms
                            var val = Number( lines[i].trim() );
                            if ( !isNaN(val) )
                                values.push(val);
                        }

                        // Build the trace object for Plotly
                        var trace = {};
                        if (chart_obj.histogram_dir !== undefined && chart_obj.histogram_dir === "horizontal")
                            trace.y = values;
                        else
                            trace.x = values;

                        trace.opacity = '0.6';
                        trace.type = 'histogram';
                        trace.name = file.legend;

                        // Add line to chart data
                        chart_data.push(trace);

                        // Store that this data is loaded
                        loaded_data[dr_id] = 1;
                    }
                }
            }

            console.log('trace generated');
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
            };

            if (chart_obj.histogram_stack != undefined) {
                if (chart_obj.histogram_stack === "stacked")
                    layout.barmode = "stack";
                else if (chart_obj.histogram_stack === "overlay")
                    layout.barmode = "overlay";
            }


            // Create responsive div for automatic resizing
            var graph_div = plotlyResponsiveDiv(chart_obj);
            Plotly.newPlot(graph_div, chart_data, layout).then(function() {
                onComplete(chart_obj)
            });
        }
    )
}
/*
function polarChartPlotly(chart_obj, onComplete) {

    var chart_data = [];

    var q = d3.queue();
    for (var sort_order in chart_obj.data_files) {
        var obj = chart_obj.data_files[sort_order];
        var dr_id = obj['dr_id'];

        q.defer(odrCSV, dr_id, sort_order, obj);
    }

    // Load the data asynchronously and plot when ready
    q.await(
        function(error) {
            if (error) {
                // We can't proceed
                // Should display error message
            }

            // Store data for filtering
            var file_data = [];
            // skip 0 - error variable
            for (i = 1; i < arguments.length; i++) {
                var file = arguments[i];
                file_data[file.display_order] = file;
            }

            // Is tracking loaded_data useful?
            var loaded_data = []
            for (var display_order in file_data) {
                var file = file_data[display_order];
                var dr_id = file.dr_id;

                if (dr_id != "rollup") {
                    if (loaded_data[dr_id] == undefined) {
                        console.log('Plotting ' + dr_id);

                        var lines = file.lines;
                        var x = [];
                        for (var i = 0; i < lines.length; i++) {
                            var val = lines[i]
                            val = val.trim()
                            // Load the numeric values
                            if(!val.match(/^#/) && (val.match(/^[0-9]/) || val.match(/^\.[0-9]/))) {
                                x.push(Number(val))
                            }
                        }

                        // Build the trace object for Plotly
                        var trace = {}
                        if (chart_obj.histogram_dir != undefined && chart_obj.histogram_dir == "horizontal") {
                            trace.y = x
                        }
                        else {
                            trace.x = x
                        }
                        trace.opacity = '0.6'
                        trace.type = 'histogram'
                        // Name used for grouping bars
                        trace.name = file.legend

                        // Add line to chart data
                        chart_data.push(trace)

                        // Store that this data is loaded
                        loaded_data[dr_id] = 1
                    }
                }
            }

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
            }

            if (chart_obj.histogram_stack != undefined) {
                if (chart_obj.histogram_stack == "stacked") {
                    layout.barmode = "stack"
                }
                else if (chart_obj.histogram_stack == "overlay") {
                    layout.barmode = "overlay";
                }
            }


            // Create responsive div for automatic resizing
            var graph_div = plotlyResponsiveDiv(chart_obj)
            Plotly.newPlot(graph_div, chart_data, layout).then(
                onComplete(chart_obj)
            )
        }
    )
}
*/
function barChartPlotly(chart_obj, onComplete) {

    var chart_data = [];

    var q = d3.queue();
    for (var sort_order in chart_obj.data_files) {
        var obj = chart_obj.data_files[sort_order];
        var dr_id = obj['dr_id'];

        q.defer(odrCSV, dr_id, sort_order, obj);
    }

    // Load the data asynchronously and plot when ready
    q.await(
        function(error) {
            if (error) {
                // We can't proceed
                // Should display error message
            }

            // Store data for filtering
            var file_data = [];
            // skip 0 - error variable
            for (i = 1; i < arguments.length; i++) {
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
                        console.log('Plotting bar: ' + dr_id);

                        var lines = file.lines;
                        var x = [];
                        var y = [];
                        var e = [];
                        for (var i = 0; i < lines.length; i++) {
                            // Skip commented lines...
                            if ( lines[i].match(/^#/) )
                                continue;

                            // Split the string into "words"...too many different separators and formats
                            var values = lines[i].match(/[a-zA-Z0-9\.\-\+]+/g);
                            for(var j in values)
                                values[j] = values[j].trim();

                            // Load the numeric values
                            if ( values !== null ) {
                                if (values.length === 2) {
                                    var x_tmp = values[0];    // x values could be strings or numbers
                                    var y_tmp = Number(values[1]);

                                    if (!isNaN(y_tmp)) {
                                        x.push(x_tmp);
                                        y.push(y_tmp);
                                    }
                                } else if (values.length === 3) {
                                    var x_tmp = values[0];    // x values could be strings or numbers
                                    var y_tmp = Number(values[1]);
                                    var e_tmp = Number(values[2]);

                                    if (!isNaN(y_tmp) && !isNaN(e_tmp)) {
                                        x.push(x_tmp);
                                        y.push(y_tmp);
                                        e.push(e_tmp);
                                    }
                                }
                            }
                        }

                        // Build the trace object for Plotly
                        var trace = {};
                        trace.x = x;
                        trace.y = y;
                        trace.type = 'bar';
                        if (e.length > 0) {
                            trace.error_y = {
                                type: 'data',
                                array: e,
                                visible: true
                            }
                        }

                        if (chart_obj.bar_type !== undefined && chart_obj.bar_type === "horizontal")
                            trace.orientation = 'h';
                        else
                            trace.orientation = 'v';

                        // Name used for grouping bars
                        trace.name = file.legend;

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
                    l: 70,
                    r: 20,
                    b: 70,
                    t: 20,
                    pad: 4
                },
                // paper_bgcolor: '#7f7f7f',
                // plot_bgcolor: '#c7c7c7',
            };

            // TODO - don't think this works...
            if (chart_obj.bar_options !== undefined && chart_obj.bar_options === "stacked")
                layout.barmode = 'stack';

            // Create responsive div for automatic resizing
            var graph_div = plotlyResponsiveDiv(chart_obj);
            Plotly.newPlot(graph_div, chart_data, layout).then(
                onComplete(chart_obj)
            );
        }
    )
}

function lineerrorChartPlotly(chart_obj, onComplete) {

    var chart_data = [];

    var q = d3.queue();
    for (var sort_order in chart_obj.data_files) {
        var obj = chart_obj.data_files[sort_order];
        var dr_id = obj['dr_id'];

        q.defer(odrCSV, dr_id, sort_order, obj);
    }

    // Load the data asynchronously and plot when ready
    q.await(
        function(error) {
            if (error) {
                // We can't proceed
                // Should display error message
            }

            // Store data for filtering
            var file_data = [];
            // skip 0 - error variable
            for (i = 1; i < arguments.length; i++) {
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
                        console.log('Plotting xy+error: ' + dr_id);

                        var lines = file.lines;
                        var x = [];
                        var y = [];
                        var e = [];
                        var f = [];
                        for (var i = 0; i < lines.length; i++) {
                            // Skip commented lines...
                            if ( lines[i].match(/^#/) )
                                continue;

                            // Split the string into "words"...too many different separators and formats
                            var values = lines[i].match(/[a-zA-Z0-9\.\-\+]+/g);
                            for (var j in values)
                                values[j] = values[j].trim();

                            // Load the numeric values (both must be valid for line to be accepted)
                            if ( values !== null ) {
                                var x_tmp = Number(values[0]);
                                var y_tmp = Number(values[1]);

                                if (!isNaN(x_tmp) && !isNaN(y_tmp)) {
                                    x.push(x_tmp);
                                    y.push(y_tmp);
                                }

                                if (values[2] !== undefined) {
                                    var e_tmp = Number(values[2]);
                                    if (!isNaN(e_tmp))
                                        e.push(e_tmp);
                                }

                                if (values[3] !== undefined) {
                                    var f_tmp = Number(values[3]);
                                    if (!isNaN(f_tmp))
                                        f.push(f_tmp);
                                }
                            }
                        }

                        // Build the trace object for Plotly
                        var trace = {};
                        trace.x = x;
                        trace.y = y;
                        trace.error_y = {};
                        trace.error_y.type = 'data';
                        if (f.length == e.length) {
                            console.log('asymmetric');
                            console.log(f.length);
                            console.log(e.length);

                            trace.error_y.symmetric = false;
                            trace.error_y.array = e;
                            trace.error_y.arrayminus = f;
                        }
                        else {
                            console.log('symmetric');
                            trace.error_y.array = e;
                            trace.error_y.symmetric = true;
                            trace.error_y.visible = true;
                        }

                        if (chart_obj.line_type !== undefined)
                            trace.mode = chart_obj.line_type;
                        else
                            trace.mode = 'lines';

                        trace.name = file.legend;

                        // Add line to chart data
                        chart_data.push(trace);

                        // Store that this data is loaded
                        loaded_data[dr_id] = 1;
                    }
                }
            }

            var xaxis_settings = {};
            if(chart_obj.x_axis_dir == "desc" && (chart_obj.x_axis_min == "auto" || chart_obj.x_axis_max == "auto")) {
                xaxis_settings.autorange = 'reversed';
            }

            if(chart_obj.x_axis_log == "yes")  {
                xaxis_settings.type = 'log';
            }

            if(chart_obj.x_axis_caption != "") {
                xaxis_settings.title = chart_obj.x_axis_caption;
            }

            if (chart_obj.x_axis_tick_interval != "auto") {
                xaxis_settings.dtick = chart_obj.x_axis_tick_interval;
                xaxis_settings.tick0 = chart_obj.x_axis_tick_start;
            }
            else {
                xaxis_settings.autottick = true;
            }

            if(chart_obj.x_axis_labels != "yes") {
                xaxis_settings.showticklabels = false;
            }

            if(chart_obj.x_axis_min != "auto" && chart_obj.x_axis_max != "auto" ) {
                xaxis_settings.range = [ chart_obj.x_axis_min, chart_obj.x_axis_max ];
            }

            xaxis_settings.showline = true;
            xaxis_settings.showgrid = true;
            xaxis_settings.zeroline = false;

            var yaxis_settings = {};
            if(chart_obj.y_axis_dir == "desc" && (chart_obj.y_axis_min == "auto" || chart_obj.y_axis_max == "auto")) {
                yaxis_settings.autorange = 'reversed';
            }
            if(chart_obj.y_axis_log == "yes") {
                yaxis_settings.type = 'log';
            }

            if(chart_obj.y_axis_caption != "") {
                yaxis_settings.title = chart_obj.y_axis_caption;
            }

            if (chart_obj.y_axis_tick_interval != "auto") {
                yaxis_settings.dtick = chart_obj.y_axis_tick_interval;
                yaxis_settings.tick0 = chart_obj.y_axis_tick_start;
            }
            else {
                yaxis_settings.autottick = true;
            }

            if(chart_obj.x_axis_labels != "yes") {
                yaxis_settings.showticklabels = false;
            }

            if(chart_obj.y_axis_min != "auto" && chart_obj.y_axis_max != "auto" ) {
                yaxis_settings.range = [ chart_obj.y_axis_min, chart_obj.y_axis_max ];
            }

            yaxis_settings.showline = true;
            yaxis_settings.showgrid = true;
            yaxis_settings.zeroline = false;

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
                yaxis: yaxis_settings
            };

            // Create responsive div for automatic resizing
            var graph_div = plotlyResponsiveDiv(chart_obj);
            Plotly.newPlot(graph_div, chart_data, layout).then(
                onComplete(chart_obj)
            );
        }
    )
}

function pieChartPlotly(chart_obj, onComplete) {

    console.log('plotting pie chart');
    var chart_data = [];

    var q = d3.queue();
    for (var sort_order in chart_obj.data_files) {
        var obj = chart_obj.data_files[sort_order];
        var dr_id = obj['dr_id'];

        q.defer(odrCSV, dr_id, sort_order, obj);
    }

    // Load the data asynchronously and plot when ready
    q.await(
        function(error) {
            if (error) {
                // We can't proceed
                // Should display error message
            }

            // Store data for filtering
            var file_data = [];
            // skip 0 - error variable
            for (i = 1; i < arguments.length; i++) {
                var file = arguments[i];
                file_data[file.display_order] = file;
            }

            // Is tracking loaded_data useful?
            var loaded_data = [];
            for (var display_order in file_data) {
                var file = file_data[display_order];
                var dr_id = file.dr_id;

                // var separator = undefined;
                var labels_column = undefined;
                var values_column = undefined;

                if (dr_id != "rollup") {
                    if (loaded_data[dr_id] == undefined) {
                        console.log('Plotting pie: ' + dr_id);

                        var lines = file.lines;
                        var labels = [];
                        var values = [];
                        for (var i = 0; i < lines.length; i++) {
                            // Skip commented lines...
                            if ( lines[i].match(/^#/) )
                                continue;

                            // Split the string into "words"...too many different separators and formats
                            var tmp = lines[i].match(/[a-zA-Z0-9\.\-\+]+/g);
                            for (var j in tmp)
                                tmp[j] = tmp[j].trim();

                            if ( tmp !== null ) {
                                if ( tmp.length === 1 ) {
                                    // Only one column, assume it's numeric
                                    var val = Number( lines[i].trim() );
                                    if ( !isNaN(val) )
                                        values.push(val);
                                }
                                else {
                                    // Labels could be in either column, really...
                                    if ( labels_column === undefined ) {
                                        if ( isNaN( Number(tmp[0]) ) ) {
                                            // First column is not numeric, assume it contains labels
                                            labels_column = 0;
                                            values_column = 1;
                                        }
                                        else if ( isNaN( Number(tmp[1]) ) ) {
                                            // Second column is not numeric, assume it contains labels
                                            labels_column = 1;
                                            values_column = 0;
                                        }
                                        else {
                                            // Both columns look like numbers, default to labels being first
                                            labels_column = 0;
                                            values_column = 1;
                                        }
                                    }

                                    labels.push( tmp[labels_column] );
                                    values.push( tmp[values_column] );
                                }
                            }
                        }

                        // Build the trace object for Plotly
                        var trace = {};

                        trace.type = 'pie';
                        trace.values = values;
                        if ( labels.length > 0 )
                            trace.labels = labels;

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
            };

            // Create responsive div for automatic resizing
            var graph_div = plotlyResponsiveDiv(chart_obj);
            Plotly.newPlot(graph_div, chart_data, layout).then(function() {
                onComplete(chart_obj)
            });
        }
    )
}

function lineChartPlotly(chart_obj, onComplete) {

    var chart_data = [];

    var q = d3.queue();
    for (var sort_order in chart_obj.data_files) {
        var obj = chart_obj.data_files[sort_order];
        var dr_id = obj['dr_id'];

        q.defer(odrCSV, dr_id, sort_order, obj);
    }

    // Load the data asynchronously and plot when ready
    q.await(
        function(error) {
            if (error) {
                // We can't proceed
                // Should display error message
            }

            // Store data for filtering
            var file_data = [];
            // skip 0 - error variable
            for (i = 1; i < arguments.length; i++) {
                var file = arguments[i];
                file_data[file.display_order] = file;
            }

            // Is tracking loaded_data useful?
            var trace_count = 0;
            var loaded_data = [];
            for (var display_order in file_data) {
                var file = file_data[display_order];
                var dr_id = file.dr_id;

                if (dr_id != "rollup") {
                    if (loaded_data[dr_id] == undefined) {
                        console.log('Plotting xy: ' + dr_id);

                        var lines = file.lines;
                        var x = [];
                        var y = [];
                        for (var i = 0; i < lines.length; i++) {
                            // Skip commented lines...
                            if ( lines[i].match(/^#/) )
                                continue;

                            // Split the string into "words"...too many different separators and formats
                            var values = lines[i].match(/[a-zA-Z0-9\.\-\+]+/g);
                            for (var j in values)
                                values[j] = values[j].trim();

                            // Load the numeric values (both must be valid for line to be accepted)
                            if ( values !== null && values.length >= 2 ) {
                                var x_tmp = Number(values[0]);
                                var y_tmp = Number(values[1]);

                                if (!isNaN(x_tmp) && !isNaN(y_tmp)) {
                                    x.push(x_tmp);
                                    y.push(y_tmp);
                                }
                            }
                        }

                        // Build the trace object for Plotly
                        var trace = {};
                        trace.x = x;
                        trace.y = y;
                        trace.name = file.legend;

                        if (chart_obj.line_type !== undefined)
                            trace.mode = chart_obj.line_type;
                        else
                            trace.mode = 'lines';

                        // When each file is being plotted with its own y-axis scaling, plotly
                        //  requires each set of data to reference its own y-axis
                        trace_count++;
                        if ( chart_obj.normalize_y_axis === "yes" ) {
                            if (trace_count === 1) {
                                // Use the default value for first set of data
                                trace.yaxis = 'y';
                            }
                            else {
                                // Subsequent sets of data get "y2", "y3", "y4", etc
                                trace.yaxis = 'y' + trace_count.toString();
                            }
                        }

                        // Add line to chart data
                        chart_data.push(trace);

                        // Store that this data is loaded
                        loaded_data[dr_id] = 1;
                    }
                }
            }

            // console.log( JSON.stringify(chart_obj) );

            var xaxis_settings = {};
            if(chart_obj.x_axis_dir == "desc" && (chart_obj.x_axis_min == "auto" || chart_obj.x_axis_max == "auto")) {
                xaxis_settings.autorange = 'reversed';
            }

            if(chart_obj.x_axis_log == "yes")  {
                xaxis_settings.type = 'log';
            }

            if(chart_obj.x_axis_caption != "") {
                xaxis_settings.title = chart_obj.x_axis_caption;
            }

            if (chart_obj.x_axis_tick_interval != "auto") {
                xaxis_settings.dtick = chart_obj.x_axis_tick_interval;
                xaxis_settings.tick0 = chart_obj.x_axis_tick_start;
            }
            else {
                xaxis_settings.autottick = true;
            }

            if(chart_obj.x_axis_labels != "yes") {
                xaxis_settings.showticklabels = false;
            }

            if(chart_obj.x_axis_min != "auto" && chart_obj.x_axis_max != "auto" ) {
                xaxis_settings.range = [ chart_obj.x_axis_min, chart_obj.x_axis_max ];
            }

            xaxis_settings.showline = true;
            xaxis_settings.showgrid = true;
            xaxis_settings.zeroline = false;

            var yaxis_settings = {};
            if(chart_obj.y_axis_dir == "desc" && (chart_obj.y_axis_min == "auto" || chart_obj.y_axis_max == "auto")) {
                yaxis_settings.autorange = 'reversed';
            }
            if(chart_obj.y_axis_log == "yes") {
                yaxis_settings.type = 'log';
            }

            if(chart_obj.y_axis_caption != "") {
                yaxis_settings.title = chart_obj.y_axis_caption;
            }

            if (chart_obj.y_axis_tick_interval != "auto") {
                yaxis_settings.dtick = chart_obj.y_axis_tick_interval;
                yaxis_settings.tick0 = chart_obj.y_axis_tick_start;
            }
            else {
                yaxis_settings.autottick = true;
            }

            if(chart_obj.x_axis_labels != "yes") {
                yaxis_settings.showticklabels = false;
            }

            if(chart_obj.y_axis_min != "auto" && chart_obj.y_axis_max != "auto" ) {
                yaxis_settings.range = [ chart_obj.y_axis_min, chart_obj.y_axis_max ];
            }

            yaxis_settings.zeroline = false;


            if ( chart_obj.normalize_y_axis === "no" || trace_count < 2 ) {
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
                yaxis_settings.visible = false;
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
                yaxis: yaxis_settings
            };

            // When each set of data is being graphed with its own y-axis...
            if ( chart_obj.normalize_y_axis === "yes" ) {
                // ...then plotly requires a separate yaxis settings object for each set of data
                var axis_basestr = 'yaxis';
                for (i = 1; i <= trace_count; i++) {
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

            // console.log( JSON.stringify(layout) );

            // Create responsive div for automatic resizing
            var graph_div = plotlyResponsiveDiv(chart_obj);
            Plotly.newPlot(graph_div, chart_data, layout).then(
                onComplete(chart_obj)
            );
        }
    )
}

function stackedAreaChartPlotly(chart_obj, onComplete) {

    var chart_data = [];

    var q = d3.queue();
    for (var sort_order in chart_obj.data_files) {
        var obj = chart_obj.data_files[sort_order];
        var dr_id = obj['dr_id'];

        q.defer(odrCSV, dr_id, sort_order, obj);
    }

    // Load the data asynchronously and plot when ready
    q.await(
        function(error) {
            if (error) {
                // We can't proceed
                // Should display error message
            }

            // Store data for filtering
            var file_data = [];
            // skip 0 - error variable
            for (i = 1; i < arguments.length; i++) {
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
                        console.log('Plotting stacked area: ' + dr_id);

                        var lines = file.lines;
                        var x = [];
                        var y = [];
                        for (var i = 0; i < lines.length; i++) {
                            // Skip commented lines...
                            if ( lines[i].match(/^#/) )
                                continue;

                            // Split the string into "words"...too many different separators and formats
                            var values = lines[i].match(/[a-zA-Z0-9\.\-\+]+/g);
                            for (var j in values)
                                values[j] = values[j].trim();

                            // Load the numeric values
                            if ( values !== null && values.length === 2 ) {
                                var x_tmp = Number(values[0]);
                                var y_tmp = Number(values[1]);

                                if ( !isNaN(x_tmp) && !isNaN(y_tmp) ) {
                                    x.push(x_tmp);
                                    y.push(y_tmp);
                                }
                            }
                        }

                        // Build the trace object for Plotly
                        var trace = {};
                        trace.x = x;
                        trace.y = y;
                        trace.type = 'scatter';
                        trace.fill = 'tozeroy';
                        trace.mode = 'lines';

                        // Name used for grouping bars
                        trace.name = file.legend;

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
                    l: 70,
                    r: 20,
                    b: 70,
                    t: 20,
                    pad: 4
                },
                // paper_bgcolor: '#7f7f7f',
                // plot_bgcolor: '#c7c7c7',
            };

            // Create responsive div for automatic resizing
            var graph_div = plotlyResponsiveDiv(chart_obj);
            Plotly.newPlot(graph_div, chart_data, layout).then(
                onComplete(chart_obj)
            );
        }
    )
}
