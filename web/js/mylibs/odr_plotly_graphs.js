/**
 * Created by nate on 10/31/16.
 */

// Global Definitions
if (ODR_PLOTLY_GLOBALS == undefined) {
    var WIDTH_IN_PERCENT_OF_PARENT = 100;
    var HEIGHT_IN_PERCENT_OF_PARENT = 100;

    function plotlyResponsiveDiv(chart_obj) {
        // D3.v4 Version
        // console.log(d3.version);
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
    // console.log('Removing divs')
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

    // console.log('appending div')
    $('body').append('<div id="PlotlyDone"></div>');
}

/**
 *
 * @param {string} chart_id
 */
function ODRGraph_triggerRebuild(chart_id) {
    // Don't run when the static graph is visible
    if ( $("#" + chart_id + "_Static_GraphWrapper").is(':visible') )
        return;
    // Need to manually delete any existing dynamic graph before making a new one
    $("#" + chart_id).children(".js-plotly-plot").each(function() {
        $(this).remove();
    });
    $("#plotlybars_" + chart_id).show();

    setTimeout("ODRGraph_reloadGraph(\"" + chart_id + "\")", 100);
}

/**
 *
 * @param {string} chart_id
 */
function ODRGraph_reloadGraph(chart_id) {
    var current = $("#" + chart_id + "_graph_type").val();
    window["SetupGraphs_" + chart_id](current);
}

/**
 * @typedef {Object} odrCSV~File
 * @type {object}
 * @property {number} dr_id   - the datarecord this file was uploaded to
 * @property {number} file_id - the id of this file
 * @property {string} legend  - the label to use for this file's data
 * @property {string} url     - the download URL for this file
 */

// TODO - something somewhere in here does not work on a page with multiple graphs...probably all the friggin globals...

/**
 * Attempts to download and parse a file from ODR.
 *
 * @param {...odrCSV~File} file
 * @param {int} display_order
 * @param {function} callback
 */
function odrCSV(file, display_order, callback) {
    // console.log(dr_id, display_order, file, callback);

    var element_id = "#FieldArea_" + file.dr_id + "_" + file.file_id;
    if ( $(element_id).length == 0 ) {

        var element = $("<textarea>", {"id": element_id.substring(1), "style": "display: none !important;"});
        $("#FieldArea_" + file.dr_id).append(element);

        // D3.v4 Version
        d3.request(file.url)
            // Handle Error
            .on('error', function(error) {
                callback(error)
            })
            // Parse File
            .on("load", function(xhr){
                // console.log(file.url);

                var dirtyCSV = xhr.responseText;
                var tmpCSV = dirtyCSV.split('\n');

                // Attempt to guess several properties of the file to be graphed
                var props = ODRPlotly_guessFileProperties(tmpCSV);
                console.log(props);

                // Going to split the data up by columns so it's easier for the javascript to switch
                //  what it actually graphs later on
                var columns = [];
                if ( props.num_columns !== null ) {
                    for (var i = 0; i < props.num_columns; i++)
                        columns[i] = [];
                    // console.log('columns initialized');
                }
                // else {
                //     console.log('could not initialize columns');
                // }

                tmpCSV.forEach(function(line) {
                    // Ignore lines that are comments
                    if ( !line.match(/^#/) ) {

                        // The file isn't guaranteed to have a delimiter...
                        var values = null;
                        if ( props.delimiter !== null ) {
                            // ...but if it looks like it does, then split the string with it
                            values = line.split(props.delimiter);
                        }
                        else {
                            // ...and if it doesn't look like it has a proper delimiter, attempt to
                            //  split the string to find "words" instead
                            values = line.match(/[a-zA-Z0-9\.\-\+]+/g);
                        }

                        if ( values !== null ) {
                            for (var j = 0; j < values.length; j++) {
                                // If the file didn't find a delimiter or isn't quite properly formed,
                                //  then we need to create column entries here
                                if ( columns[j] === undefined )
                                    columns[j] = [];

                                // No real reason to convert to a number here...plotly will do it,
                                //  and plotly will also automatically ignore any non-numerical value
                                columns[j].push( values[j].trim() );
                            }
                        }
                    }
                });

                // Attempt to extract a reasonable header from the file
                var all_numerical = true;
                for (var i = 0; i < columns.length; i++) {
                    var value = Number( columns[i][0] );
                    if ( Number.isNaN(value) ) {
                        all_numerical = false;
                        break;
                    }
                }
                var headers = [];
                for (var i = 0; i < columns.length; i++) {
                    if ( all_numerical )
                        headers.push( 'Column ' + (i+1) );
                    else
                        headers.push( columns[i][0] );
                }

                var data_file = {};
                data_file.dr_id = file.dr_id;
                data_file.display_order = display_order;
                data_file.url = file.url;
                data_file.legend = file.legend;
                data_file.columns = columns;
                data_file.headers = headers;

                console.log("Lines downloaded: " + columns[0].length);
                var json = JSON.stringify(columns);
                console.log(json);
                $(element_id).html(json);

                callback(null, data_file)
            })
            .send("GET");
    }
    else {
        var data_file = {};
        data_file.dr_id = file.dr_id;
        data_file.display_order = display_order;
        data_file.url = file.url;
        data_file.legend = file.legend;

        var json = $(element_id).html();
        data_file.columns = JSON.parse(json);
        console.log("Lines read: " + data_file.columns[0].length);

        // Don't need to reload the headers
        delete data_file.headers;

        callback(null, data_file);
    }
}

/**
 * Converts any error messages encountered into plotly annotations so they can get displayed.
 * @param {string[]} error_messages
 * @returns {array}
 */
function getErrorMessages(error_messages) {
    var messages = [];
    var final_message_text = "";
    error_messages.forEach(function(msg, index) {
        // Need to manually wrap these error messages
        var wrapping_length = 70;
        var wrapped_msg = "Error " + (index+1) + ":";
        var tmp_length = wrapped_msg.length;
        wrapped_msg = "<b>" + wrapped_msg + "</b>";

        var pieces = msg.split(" ");
        for (var i = 0; i < pieces.length; i++) {
            if ((tmp_length + pieces[i].length) > wrapping_length) {
                wrapped_msg += "<br>" + pieces[i];
                tmp_length = pieces[i].length + 1;
            } else {
                wrapped_msg += " " + pieces[i];
                tmp_length += pieces[i].length + 1;
            }
        }

        // Don't know how many error messages there are, so it's safer to anchor at the top and keep
        //  creating additional lines
        final_message_text += wrapped_msg + "<br>";
    });

    messages.push({
        showarrow: false,
        font: {
            size: 22,
        },
        text: final_message_text,
        // with these "yref" and "yanchor" properties...
        yref: "paper",
        yanchor: "top",
        // ...a value of 1 for the "y" property anchors the annotation to the top, and a value
        //  of 0 anchors to the bottom
        y: 1,
    });

    return messages;
}

/**
 * Attempts to locate the delimiter and the number of columns from the first several lines of the
 * given file.
 *
 * @param {string[]} lines
 * @return {object}
 */
function ODRPlotly_guessFileProperties(lines) {
    // Since these are (hopefully) scientific data files, the set of valid delimiters is (hopefully)
    //  pretty small
    var valid_delimiters = ["\t", ","];
    // NOTE: do not put the space character in there...if the file is using the space character as
    //  a delimiter, then it's safer for the graph code to split the line apart into "words" instead
    //  of splitting by a specific character sequence

    // Read the first couple non-comment lines in the file...
    var max_line_count = 10;
    var current_line = 0;
    var characters = {};
    for (var i = 0; i < lines.length; i++) {
        var line = lines[i];
        if ( !line.match(/^#/) ) {
            characters[current_line] = {};

            // ...and count how many of each character is encountered
            for (var j = 0; j < line.length; j++) {
                var char = line.charAt(j);
                // If the line contains a valid delimiter, then store how many times it occurs
                if ( valid_delimiters.indexOf(char) !== -1 ) {
                    if (characters[current_line][char] === undefined)
                        characters[current_line][char] = 0;
                    characters[current_line][char]++;
                }
                else if ( char === "\"" || char === "\'" ) {
                    // If the line contained a singlequote or a doublequote, then ignore it completely
                    delete characters[current_line];
                    break;
                }
            }

            current_line++;
            if ( current_line >= max_line_count ) {
                characters.length = Object.keys(characters).length;
                break;
            }
        }
    }


    // Filter out the invalid delimiters
    var delimiter_count = {};
    for (var i = 0; i < valid_delimiters.length; i++) {
        var delimiter = valid_delimiters[i];
        delimiter_count[delimiter] = undefined;

        for (var j = 0; j < characters.length; j++) {
            if ( characters[j] !== undefined && characters[j][delimiter] !== undefined ) {
                if ( delimiter_count[delimiter] === undefined ) {
                    // Store how many times this delimiter occurs on the first valid line of data
                    //  in the file
                    delimiter_count[delimiter] = characters[j][delimiter];
                }
                else if ( delimiter_count[delimiter] !== characters[j][delimiter] ) {
                    // This line has a different number of this delimiter than the earlier lines in
                    //  the file...it's probably not safe to call this a delimiter
                    delimiter_count[delimiter] = null;
                    break;
                }
            }
        }
    }

    // Determine which of the remaining delimiters is most likely for the file
    var delimiter_guess = null;
    var columns_guess = null;
    for (var i = 0; i < valid_delimiters.length; i++) {
        var delimiter = valid_delimiters[i];
        if ( delimiter_count[delimiter] !== null && delimiter_count[delimiter] !== undefined ) {
            if ( delimiter_guess === null ) {
                // Ideally, the first delimiter found will be the only one...
                delimiter_guess = delimiter;
                columns_guess = delimiter_count[delimiter] + 1;
            }

            // Currently only consider tab and comma as valid delimiters...if for some reason both
            //  are "valid" at this point, then ignore comma and use tab
            // TODO - ...if additional characters become considered as valid delimiters, then this logic probably needs changed
        }
    }

    var props = {
        delimiter: delimiter_guess,
        num_columns: columns_guess,
    };
    return props;
}

/**
 * Parses the chart object for generic x_axis settings.
 * @param {object} chart_obj
 * @returns {object}
 */
function getXAxisSettings(chart_obj) {
    var xaxis_settings = {};
    xaxis_settings.showline = true;
    xaxis_settings.showgrid = true;
    xaxis_settings.zeroline = false;

    if (chart_obj.x_axis_dir == "desc" && (chart_obj.x_axis_min == "auto" || chart_obj.x_axis_max == "auto"))
        xaxis_settings.autorange = 'reversed';

    if (chart_obj.x_axis_log == "yes")
        xaxis_settings.type = 'log';

    if (chart_obj.x_axis_caption != "")    // TODO - need some way to read the captions from file
        xaxis_settings.title = chart_obj.x_axis_caption;

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
 * @param {object} chart_obj
 * @returns {object}
 */
function getYAxisSettings(chart_obj) {
    var yaxis_settings = {};
    yaxis_settings.zeroline = false;

    if (chart_obj.y_axis_dir == "desc" && (chart_obj.y_axis_min == "auto" || chart_obj.y_axis_max == "auto"))
        yaxis_settings.autorange = 'reversed';

    if (chart_obj.y_axis_log == "yes")
        yaxis_settings.type = 'log';

    if (chart_obj.y_axis_caption != "")
        yaxis_settings.title = chart_obj.y_axis_caption;

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
 * @param {object} chart_obj
 * @param {string} chart_type
 * @param {array|undefined} headers
 * @returns {array}
 */
function updateSelectedColumns(chart_obj, chart_type, headers) {
    var chart_id = chart_obj.chart_id;
    var num_selectors = 2;

    var ids = [];
    var selected_values = [];
    for (var i = 1; i <= num_selectors; i++) {
        var id = "#" + chart_id + "_column_" + i;
        ids.push(id);

        // If the headers variable is defined, then reset the column names in the dropdowns
        if ( headers !== undefined ) {
            $(id).children('option').remove();

            var element = $("<option>", {"value": "", "html": ""});
            $(id).append(element);

            for (var j = 0; j < headers.length; j++) {
                var element = $("<option>", {"value": j, "html": headers[j]});
                $(id).append(element);
            }
        }

        // Determine the selected values for each of the dropdowns
        var val = $(id).val();
        if ( val === '' )
            selected_values.push( val );
        else
            selected_values.push( Number(val) );
    }

    // If the column headers were reset, then select the default options
    if ( headers !== undefined ) {
        // Select the current graph type
        $("#" + chart_id + "_graph_type").children('option').each(function(index, elem) {
            if ( $(elem).val() === chart_type )
                $(elem).prop('selected', true);
            else
                $(elem).prop('selected', false);
        });

        // Select the default columns based on the plugin settings
        $(ids[0] + " option:eq(" + chart_obj.x_values_column + ")").prop('selected', true);
        selected_values[0] = Number(chart_obj.x_values_column) - 1;
        $(ids[1] + " option:eq(" + chart_obj.y_values_column + ")").prop('selected', true);
        selected_values[1] = Number(chart_obj.y_values_column) - 1;
    }

    // Show the graph type selector
    $("#" + chart_id + "_graph_type_label").show();
    $("#" + chart_id + "_graph_type").show();

    $("#" + chart_id + "_render_type_label").show();
    $("#" + chart_id + "_render_type").show();

    // Re-enable and relabel the selectors based on the current graph type
    $(".graph_columns").hide();
    if ( chart_type === 'histogram' ) {
        // Histograms only read one column
        $(ids[0] + "_label").html("values: ").show();
        $(ids[0]).show();
    }
    else if ( chart_type === 'pie' ) {
        // Pie charts need two columns
        $(ids[0] + "_label").html("values: ").show();
        $(ids[0]).show();
        $(ids[1] + "_label").html("labels: ").show();
        $(ids[1]).show();
    }
    else {
        // The other graph types need x/y columns  TODO - error selectors for these?
        $(ids[0] + "_label").html("x values: ").show();
        $(ids[0]).show();
        $(ids[1] + "_label").html("y values: ").show();
        $(ids[1]).show();
    }

    return selected_values;
}

function histogramChartPlotly(chart_obj, onComplete) {
    // console.log('plotting histogram chart');
    // console.log(chart_obj);

    var chart_data = [];

    var q = d3.queue();
    for (var sort_order in chart_obj.data_files) {
        var obj = chart_obj.data_files[sort_order];
        q.defer(odrCSV, obj, sort_order);
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
            for (i = 1; i < arguments.length; i++) {
                var file = arguments[i];
                file_data[file.display_order] = file;
            }

            var loaded_data = [];
            for (var display_order in file_data) {
                var file = file_data[display_order];
                var dr_id = file.dr_id;

                if (dr_id != "rollup") {
                    if (loaded_data[dr_id] == undefined) {
                        console.log('Plotting histogram: ' + dr_id);

                        var columns = file.columns;
                        var selected_columns = updateSelectedColumns(chart_obj, "histogram", file.headers);
                        var data_column = selected_columns[0];

                        if ( data_column === '' || !Number.isInteger( Number(data_column) ) )
                            error_messages.push("The file for \"" + file.legend + "\" can't identify a column with the string \"" + data_column + "\"");
                        else if ( columns[data_column] === undefined )
                            error_messages.push("The file for \"" + file.legend + "\" does not have data in column " + (data_column+1));

                        // Build the trace object for Plotly
                        var trace = {};
                        if (chart_obj.histogram_dir !== undefined && chart_obj.histogram_dir === "horizontal")
                            trace.y = columns[data_column];
                        else
                            trace.x = columns[data_column];

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

            // Get the axis settings from the chart object
            var xaxis_settings = getXAxisSettings(chart_obj);
            var yaxis_settings = getYAxisSettings(chart_obj);

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
            };

            // Histogram-specific layout settings...
            if (chart_obj.histogram_stack != undefined) {
                if (chart_obj.histogram_stack === "stacked")
                    layout.barmode = "stack";
                else if (chart_obj.histogram_stack === "overlay")
                    layout.barmode = "overlay";
            }

            if ( error_messages.length > 0 ) {
                // Encountered an error...don't display any data
                chart_data = [];
                layout.annotations = getErrorMessages(error_messages);
            }

            // Create responsive div for automatic resizing
            var graph_div = plotlyResponsiveDiv(chart_obj);
            Plotly.newPlot(graph_div, chart_data, layout).then(function() {
                onComplete(chart_obj)
            });
        }
    )
}

function barChartPlotly(chart_obj, onComplete) {
    // console.log('plotting bar chart');
    // console.log(chart_obj);
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
        q.defer(odrCSV, obj, sort_order);
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

                        var columns = file.columns;
                        var selected_columns = updateSelectedColumns(chart_obj, "bar", file.headers);
                        var x_column = selected_columns[0];
                        var y_column = selected_columns[1];

                        if ( x_column === '' || !Number.isInteger( Number(x_column) ) )
                            error_messages.push("The file for \"" + file.legend + "\" can't identify a column with the string \"" + x_column + "\"");
                        else if ( columns[x_column] === undefined )
                            error_messages.push("The file for \"" + file.legend + "\" does not have data for column " + (x_column+1));

                        if ( y_column === '' || !Number.isInteger( Number(y_column) ) )
                            error_messages.push("The file for \"" + file.legend + "\" can't identify a column with the string \"" + y_column + "\"");
                        else if ( columns[y_column] === undefined )
                            error_messages.push("The file for \"" + file.legend + "\" does not have data for column " + (y_column+1));
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

            // Get the axis settings from the chart object
            var xaxis_settings = getXAxisSettings(chart_obj);
            var yaxis_settings = getYAxisSettings(chart_obj);

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

            // TODO - don't think this works...
            if (chart_obj.bar_options !== undefined && chart_obj.bar_options === "stacked")
                layout.barmode = 'stack';

            if ( error_messages.length > 0 ) {
                // Encountered an error...don't display any data
                chart_data = [];
                layout.annotations = getErrorMessages(error_messages);
            }

            // Create responsive div for automatic resizing
            var graph_div = plotlyResponsiveDiv(chart_obj);
            Plotly.newPlot(graph_div, chart_data, layout).then(
                onComplete(chart_obj)
            );
        }
    )
}

function pieChartPlotly(chart_obj, onComplete) {
    // console.log('plotting pie chart');
    // console.log(chart_obj);

    var chart_data = [];

    var q = d3.queue();
    for (var sort_order in chart_obj.data_files) {
        var obj = chart_obj.data_files[sort_order];
        q.defer(odrCSV, obj, sort_order);
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
                        console.log('Plotting pie: ' + dr_id);

                        var columns = file.columns;
                        var selected_columns = updateSelectedColumns(chart_obj, "piechart", file.headers);
                        var data_column = selected_columns[0];
                        var labels_column = selected_columns[1];

                        if ( data_column === '' || !Number.isInteger( Number(data_column) ) )
                            error_messages.push("The file for \"" + file.legend + "\" can't identify a column with the string \"" + data_column + "\"");
                        else if ( columns[data_column] === undefined )
                            error_messages.push("The file for \"" + file.legend + "\" does not have data for column " + (data_column+1));
                        else if ( columns[data_column].length > 100 )
                            error_messages.push("Not creating a pie chart from the file \"" + file.legend + "\" because it would have more than 100 slices");

                        if ( labels_column === '' || !Number.isInteger( Number(labels_column) ) )
                            error_messages.push("The file for \"" + file.legend + "\" can't identify a column with the string \"" + labels_column + "\"");
                        else if ( columns[labels_column] === undefined )
                            error_messages.push("The file for \"" + file.legend + "\" does not have data for column " + (labels_column+1));


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
            };

            if ( error_messages.length > 0 ) {
                // Encountered an error...don't display any data
                chart_data = [];
                layout.annotations = getErrorMessages(error_messages);
            }

            // Create responsive div for automatic resizing
            var graph_div = plotlyResponsiveDiv(chart_obj);
            Plotly.newPlot(graph_div, chart_data, layout).then(function() {
                onComplete(chart_obj)
            });
        }
    )
}

function lineChartPlotly(chart_obj, onComplete) {
    // console.log('plotting line chart');
    // console.log(chart_obj);
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
        q.defer(odrCSV, obj, sort_order);
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

                        var columns = file.columns;
                        var selected_columns = updateSelectedColumns(chart_obj, "xy", file.headers);
                        var x_column = selected_columns[0];
                        var y_column = selected_columns[1];

                        if ( x_column === '' || !Number.isInteger( Number(x_column) ) )
                            error_messages.push("The file for \"" + file.legend + "\" can't identify a column with the string \"" + x_column + "\"");
                        else if ( columns[x_column] === undefined )
                            error_messages.push("The file for \"" + file.legend + "\" does not have data for column " + (x_column+1));

                        if ( y_column === '' || !Number.isInteger( Number(y_column) ) )
                            error_messages.push("The file for \"" + file.legend + "\" can't identify a column with the string \"" + y_column + "\"");
                        else if ( columns[y_column] === undefined )
                            error_messages.push("The file for \"" + file.legend + "\" does not have data for column " + (y_column+1));
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

                        // Build the trace object for Plotly
                        var trace = {};
                        trace.name = file.legend;
                        trace.x = columns[x_column];
                        trace.y = columns[y_column]

                        if ( $("#" + chart_obj.chart_id + "_render_type").length > 0 && $("#" + chart_obj.chart_id + "_render_type").is(':checked') )
                            trace.type = 'scattergl';
                        else
                            trace.type = 'scatter';

                        if (chart_obj.line_type !== undefined)
                            trace.mode = chart_obj.line_type;    // NOTE: if trace.mode == 'lines', then it's an "xy" plot.  if trace.mode == 'markers', then it's a "scatter" plot
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

            // Get the axis settings from the chart object
            var xaxis_settings = getXAxisSettings(chart_obj);
            var yaxis_settings = getYAxisSettings(chart_obj);

            // The "Normalize Y Axis" setting can require changes to the axis settings...
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

            if ( error_messages.length > 0 ) {
                // Encountered an error...don't display any data
                chart_data = [];
                layout.annotations = getErrorMessages(error_messages);
            }

            // Create responsive div for automatic resizing
            var graph_div = plotlyResponsiveDiv(chart_obj);
            Plotly.newPlot(graph_div, chart_data, layout).then(
                onComplete(chart_obj)
            );
        }
    )
}

function stackedAreaChartPlotly(chart_obj, onComplete) {
    // console.log('plotting stacked area chart');
    // console.log(chart_obj);
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
        q.defer(odrCSV, obj, sort_order);
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

                        var columns = file.columns;
                        var selected_columns = updateSelectedColumns(chart_obj, "stackedarea", file.headers);
                        var x_column = selected_columns[0];
                        var y_column = selected_columns[1];

                        if ( x_column === '' || !Number.isInteger( Number(x_column) ) )
                            error_messages.push("The file for \"" + file.legend + "\" can't identify a column with the string \"" + x_column + "\"");
                        else if ( columns[x_column] === undefined )
                            error_messages.push("The file for \"" + file.legend + "\" does not have data for column " + (x_column+1));

                        if ( y_column === '' || !Number.isInteger( Number(y_column) ) )
                            error_messages.push("The file for \"" + file.legend + "\" can't identify a column with the string \"" + y_column + "\"");
                        else if ( columns[y_column] === undefined )
                            error_messages.push("The file for \"" + file.legend + "\" does not have data for column " + (y_column+1));
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

                        // Build the trace object for Plotly
                        var trace = {};
                        trace.x = columns[x_column];
                        trace.y = columns[y_column];
                        trace.fill = 'tozeroy';
                        trace.mode = 'lines';

                        if ( $("#" + chart_obj.chart_id + "_render_type").length > 0 && $("#" + chart_obj.chart_id + "_render_type").is(':checked') )
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

                        // Add line to chart data
                        chart_data.push(trace);

                        // Store that this data is loaded
                        loaded_data[dr_id] = 1;
                    }
                }
            }

            // Get the axis settings from the chart object
            var xaxis_settings = getXAxisSettings(chart_obj);
            var yaxis_settings = getYAxisSettings(chart_obj);

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

            if ( error_messages.length > 0 ) {
                // Encountered an error...don't display any data
                chart_data = [];
                layout.annotations = getErrorMessages(error_messages);
            }

            // Create responsive div for automatic resizing
            var graph_div = plotlyResponsiveDiv(chart_obj);
            Plotly.newPlot(graph_div, chart_data, layout).then(
                onComplete(chart_obj)
            );
        }
    )
}
