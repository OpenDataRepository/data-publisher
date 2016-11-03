var server = require('webserver').create();
var system = require('system');
var webPage = require('webpage');
var fs = require('fs');
var data;
var address;
var selector;
var output;
var port = 9494;
console.log('running')
var service = server.listen(port, function (request, response) {
    try {

        function waitFor(testFx, onReady, timeOutMillis) {
            var maxtimeOutMillis = timeOutMillis ? timeOutMillis : 10000, //< Default Max Timout is 3s
                start = new Date().getTime(),
                condition = false,
                interval = setInterval(function() {
                    if ( (new Date().getTime() - start < maxtimeOutMillis) && !condition ) {
                        // If not time-out yet and condition not yet fulfilled
                        condition = (typeof(testFx) === "string" ? eval(testFx) : testFx()); //< defensive code
                    } else {
                        if(!condition) {
                            // If condition still not fulfilled (timeout but condition is 'false')
                            console.log("'waitFor()' timeout");
                            // phantom.exit()

                            // Cal the finish anyway since it will at least return something
                            typeof(onReady) === "string" ? eval(onReady) : onReady(); //< Do what it's supposed to do once the condition is fulfilled
                            clearInterval(interval); //< Stop this interval
                        } else {
                            // Condition fulfilled (timeout and/or condition is 'true')
                            console.log("'waitFor()' finished in " + (new Date().getTime() - start) + "ms.");
                            typeof(onReady) === "string" ? eval(onReady) : onReady(); //< Do what it's supposed to do once the condition is fulfilled
                            clearInterval(interval); //< Stop this interval
                        }
                    }
                }, 50); //< repeat check every 250ms
        };

        var page = webPage.create()
        console.log('Request received at ' + new Date());
        // TODO: parse `request` and determine where to go
        console.log(JSON.stringify(request, null, 4));
        console.log(request.post);
        data = JSON.parse(request.post).data;

        // if (system.args.length < 4) {
        //     console.log('Usage: phantom-crowbar.js <some URL> <selector> <outputfile>');
        //     phantom.exit();
        // }
        //
        address = data.URL;
        selector = data.selector;
        output = data.output;

// logging
        page.onConsoleMessage = function (msg) {
            console.log('Console: ' + msg);
        };


// big screen
        page.viewportSize = {
            width: 1400,
            height: 800
        };

        page.clearMemoryCache();

// open dat page
        page.open(address, function (status) {
            if (status !== 'success') {
                console.log('FAIL to load the address');
                // phantom.exit();
            }
            else {
                // Wait for 'signin-dropdown' to be visible
                waitFor(function() {
                    // Check in the page if a specific element is now visible
                    return page.evaluate(function() {
                        return $("#PlotlyDone").is(":visible");
                    });
                }, function() {
                    console.log('page loaded')


                response.statusCode = 200;
                response.headers = {
                    'Cache': 'no-cache',
                    'Content-Type': 'text/plain;charset=utf-8'
                };

                    console.log('plot done')
                    // TODO: do something on the page and generate `result`
                    var out = page.evaluate(function (s) {
                        // actually get the svg out, using a lot of the crowbar code
                        var source = '';
                        var doctype = '<?xml version="1.0" standalone="no"?><!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd" >';
                        var prefix = {
                            xmlns: "http://www.w3.org/2000/xmlns/",
                            xlink: "http://www.w3.org/1999/xlink",
                            svg: "http://www.w3.org/2000/svg"
                        }

                        var styles = '';
                        var styleSheets = document.styleSheets;

                        for (var i = 0; i < styleSheets.length; i++) {
                            processStyleSheet(styleSheets[i]);
                        }

                        // much simplified code from the crowbar
                        // don't care about illustrator
                        // and i don't use import rules
                        function processStyleSheet(ss) {
                            if (ss.cssRules) {
                                for (var i = 0; i < ss.cssRules.length; i++) {
                                    var rule = ss.cssRules[i];
                                    styles += "\n" + rule.cssText;
                                }
                            }
                        }

                        // mostly untouched from the crowbar
                        console.log(s)
                        var svg = document.getElementById(s);
                        svg.setAttribute("version", "1.1");
                        console.log("made it here")

                        var defsEl = document.createElement("defs");
                        svg.insertBefore(defsEl, svg.firstChild);
                        var styleEl = document.createElement("style")
                        defsEl.appendChild(styleEl);
                        console.log("made it to 89")
                        styleEl.setAttribute("type", "text/css");
                        svg.removeAttribute("xmlns");
                        svg.removeAttribute("xlink");
                        // These are needed for the svg
                        if (!svg.hasAttributeNS(prefix.xmlns, "xmlns")) {
                            svg.setAttributeNS(prefix.xmlns, "xmlns", prefix.svg);
                            console.log("made it to 96")
                        }
                        if (!svg.hasAttributeNS(prefix.xmlns, "xmlns:xlink")) {
                            svg.setAttributeNS(prefix.xmlns, "xmlns:xlink", prefix.xlink);
                            console.log("made it to 100")
                        }

                        var svgxml = (new XMLSerializer()).serializeToString(svg)
                            .replace('<div', '<svg ')
                            .replace('</div>', '</svg>')
                            // .replace('</style>', '<![CDATA[' + styles + ']]></style>');
                        source += doctype + svgxml;
                        console.log("made it to return source")

                        return source;

                    }, selector);

                    // write it out to a file
                    // response.write(result);

                    fs.write(output, out, 'w');
                    console.log('Evaluated our code');
                    page.close();
                });
            }




        });
        page.onClosing = function(closingPage) {
            console.log("Page is closing " + closingPage.url)
            localStorage.clear()
            response.close();
        };
    }
    catch (err) {
        console.log(err)
    }
});