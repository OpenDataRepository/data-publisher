/**
 * Open Data Repository Data Publisher
 * odr_plotly_graphs.js
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This file contains the plotly/graphing functions common to all graph plugins.
 */

/**
 * @typedef {Object} odrCSV
 * @type {object}
 * @property {number} dr_id   - the datarecord this file was uploaded to
 * @property {number} file_id - the id of this file
 * @property {string} legend  - the label to use for this file's data
 * @property {string} url     - the download URL for this file
 */

/**
 * @typedef {Object} odrDataFile
 * @type {object}
 * @property {number} dr_id
 * @property {number} file_id
 * @property {number} display_order
 * @property {string} url
 * @property {string} legend
 * @property {array} columns
 * @property {array} headers
 * @property {boolean} new_file
 */

/**
 * @typedef {Object} odrChartObj
 * @type {object}
 * // These properties are defined for every chartObj
 * @property {string} chart_id
 * @property {array} data_files
 * @property {string} graph_width
 * @property {string} graph_height
 * @property {string} layout
 *
 * // These properties are only defined for chartObjs created via the GraphPlugin
 * @property {string} chart_type
 * @property {boolean} use_rollup
 * @property {string} line_type
 * @property {string} normalize_y_axis
 * @property {string} bar_type
 * @property {string} bar_options
 * @property {string} histogram_dir
 * @property {string} histogram_stack
 * @property {number} x_values_column
 * @property {number} y_values_column
 * @property {string} x_axis_min
 * @property {string} x_axis_max
 * @property {string} x_axis_dir
 * @property {string} x_axis_labels
 * @property {string} x_axis_tick_interval
 * @property {string} x_axis_tick_start
 * @property {string} x_axis_caption
 * @property {string} x_axis_log
 * @property {string} y_axis_min
 * @property {string} y_axis_min
 * @property {string} y_axis_max
 * @property {string} y_axis_dir
 * @property {string} y_axis_tick_interval
 * @property {string} y_axis_tick_start
 * @property {string} y_axis_labels
 * @property {string} y_axis_caption
 * @property {string} y_axis_log
 *
 * // These properties are only defined for chartObjs created via the GCMassSpecPlugin
 * @property {number} time_column
 * @property {number} amu_column
 * @property {number} counts_column
 */

// !!! IMPORTANT: you MUST use 'var' instead of 'let' in this file...phantomJS will break !!!
// !!! IMPORTANT: you CAN'T use optional function arguments either...nothing like function foo(bar, baz = "") !!!

// Global Definitions
// if (ODR_PLOTLY_GLOBALS == undefined) {
    var WIDTH_IN_PERCENT_OF_PARENT = 100;
    var HEIGHT_IN_PERCENT_OF_PARENT = 100;

    /**
     * Sets up the dynamic version of the graph on the page.
     * @param {odrChartObj} chart_obj
     * @param {string} alternate_id
     */
    function plotlyResponsiveDiv(chart_obj, alternate_id) {
        // D3.v4 Version
        // console.log(d3.version);
        var div_id = "#" + chart_obj['chart_id'];
        if ( alternate_id !== undefined ) {
            div_id = "#" + alternate_id;
            $(div_id).html('');
        }

        var gd3 = d3.select(div_id)
            .append('div')
            .style('width', WIDTH_IN_PERCENT_OF_PARENT + '%')
            .style('margin-left', (100 - WIDTH_IN_PERCENT_OF_PARENT) / 2 + '%')
            .style('height', HEIGHT_IN_PERCENT_OF_PARENT + '%')
            .style('margin-top', '0%');

        // console.log( gd3.node().innerHTML);

        var gd = gd3.node();
        page_plots.push(gd);
        return gd;
    }

    // var ODR_PLOTLY_GLOBALS = true;
// }

/**
 * Hides the "loading" bars that are displayed when transitioning from static graphs to dynamic graphs.
 * @param {odrChartObj} chart_obj
 */
var clearPlotlyBars = function(chart_obj) {
    $("#plotlybars_" + chart_obj.chart_id).hide()
}

/**
 * Sets up the static version of the graph on the page.  Should only be used by the phantomJS graph
 * builder stuff.
 * @param {odrChartObj} chart_obj
 */
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
 * Forces a (slightly) delayed rebuild of the graph, so that the "loading" bars have a chance to
 * display.
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
 * Triggers a complete reload of the dynamic graph, for when the user has changed an option.
 * @param {string} chart_id
 */
function ODRGraph_reloadGraph(chart_id) {
    var elem = $("#" + chart_id + "_graph_type");
    if ( $(elem).length > 0 ) {
        var current = $(elem).val();
        window["SetupGraphs_" + chart_id](current);
    }
    else {
        window["SetupGraphs_" + chart_id]();
    }
}

/**
 * Attempts to download and parse a file from ODR.
 *
 * @param {odrCSV} file
 * @param {int} display_order
 * @param {function} callback
 */
function ODRGraph_parseFile(file, display_order, callback) {
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
                var props = ODRGraph_guessFileProperties(tmpCSV);
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

                // Attempt to extract reasonable header columns from the file
                var headers = ODRGraph_getCSVHeaders(columns);

                var data_file = {};
                data_file.dr_id = file.dr_id;
                data_file.display_order = display_order;
                data_file.url = file.url;
                data_file.legend = file.legend;
                data_file.columns = columns;
                data_file.headers = headers;
                data_file.new_file = true;

                console.log("Lines downloaded: " + columns[0].length);
                var json = JSON.stringify(columns);
                // console.log(json);
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

        data_file.headers = ODRGraph_getCSVHeaders(data_file.columns);
        data_file.new_file = false;

        callback(null, data_file);
    }
}

/**
 * Returns an array of header values based on the columns of data from a file...if the first row of
 * all columns looks like a string, then it'll attempt to return those...but if not, it'll return
 * an array of "Column #" strings.
 * @param {array} columns
 * @returns {array}
 */
function ODRGraph_getCSVHeaders(columns) {
    var all_numerical = true;
    for (var i = 0; i < columns.length; i++) {
        var value = Number( columns[i][0] );
        if ( Number.isNaN(value) ) {
            all_numerical = false;
            break;
        }
    }
    // Ran into a few files with a different number of header columns than data columns
    var mismatched_headers = false;
    for (var i = 0; i < columns.length; i++) {
        if ( (columns[i][0] !== undefined && columns[i][1] === undefined)
            || (columns[i][0] === undefined && columns[i][1] !== undefined)
        ) {
            mismatched_headers = true;
        }
    }

    var headers = [];
    for (var i = 0; i < columns.length; i++) {
        if ( all_numerical || mismatched_headers )
            headers.push( 'Column ' + (i+1) );
        else
            headers.push( columns[i][0] );
    }

    return headers;
}

/**
 * Converts any error messages encountered into plotly annotations so they can get displayed.
 * @param {string[]} error_messages
 * @returns {array}
 */
function ODRGraph_getErrorMessages(error_messages) {
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
function ODRGraph_guessFileProperties(lines) {
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
