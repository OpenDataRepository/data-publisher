/*
 * https://github.com/akeyboardlife/puppeteer-save-svg/blob/master/main.js
 */

const puppeteer = require('puppeteer');
const fs = require('fs');

const bs = require('nodestalker');
const client = bs.Client('127.0.0.1:11300');
const tube = 'create_graph_preview';
let browser;

async function app() {
    browser = await puppeteer.launch({headless:'new'});
    client.watch(tube).onSuccess(function(data) {
        function resJob() {
            client.reserve().onSuccess(async function(job) {
                console.log('Reserved (' + Date.now() + '): ' , job);

                try {
                    let data = JSON.parse(job.data)
                    console.log('Starting job: ' + job.id)

                    await buildGraph(data.site_baseurl, data.odr_web_dir, data.builder_filepath, data.graph_filepath, data.selector, data.files_to_delete);

                    client.deleteJob(job.id).onSuccess(function(del_msg) {
                        console.log('Deleted (' + Date.now() + '): ' , job);
                        // console.log('message', del_msg);
                        resJob();
                    });
                }
                catch (e) {
                    // TODO need to put job as unfinished - maybe not due to errors
                    console.log('Error occurred: ', e);
                    client.deleteJob(job.id).onSuccess(function(del_msg) {
                        console.log('Deleted (' + Date.now() + '): ' , job);
                        // console.log('message', del_msg);
                        // console.log('message', del_msg);
                        resJob();
                    });
                }
            });
        }
        resJob();
    });
}

async function buildGraph(site_baseurl, odr_web_dir, builder_filepath, graph_filepath, selector, files_to_delete) {
    // configure folder and http url path
    // the folder contain all the html file
    const page = await browser.newPage();
    page.on('console', message =>
        console.log(`${message.type().substr(0, 3).toUpperCase()} ${message.text()}`)
    );
    await page.setViewport({ width: 1400, height: 800 });

    try {
        // Before doing anything, check whether the final graph file exists first...
        if ( fs.existsSync(graph_filepath) ) {
            console.log('final graph file "' + graph_filepath + '" already exists, skipping request');

            // ...if it does, then don't rebuild it
            await page.close();
            return 'graph built';
        }

        let builder_url = 'https:' + site_baseurl + builder_filepath;
        console.log('Attempting to load: ' + builder_url);

        let result = await page.goto(builder_url, {timeout: 30000});
        if (result.status() === 404) {
            // console.error('404 status code found in result');
            throw('404 - Builder HTML file not found');
        }

        await page.content();
        console.log('Page content loaded, waiting for window.' + selector + '_ready');
        // Wait for javascript to render
        const watchDog = page.waitForFunction('window.' + selector + '_ready === "ready"');
        await watchDog;
        console.log('Watchdog load')

        let html = await page.evaluate(() => document.querySelector('body').innerHTML);
        console.log(html);

        // let svgInline = await page.evaluate(() => document.querySelector('#' + selector).innerHTML)
        // let svgInline = await page.evaluate(() => document.querySelector('svg').outerHTML);
        // So, it turns out that plotly is now dividing the graphs it makes into three separate <svg> elements...
        let svgInline = await page.evaluate(() => document.querySelector('.ODRDynamicGraph').innerHTML);
        // ...however, browsers expect only one <svg> element as document root.  Awesome.

        if ( svgInline !== '' ) {
            // Fortunately, fixing this isn't completely horrible...easier to extract the entirety of
            //  the first <svg> element...
            let first_closing_bracket = svgInline.indexOf('>');
            let svg_attributes = svgInline.substring(0, first_closing_bracket+1);
            // ...and then surround the existing content with the original <svg> attributes
            svgInline = svg_attributes + svgInline + '</svg>';
        }

        // Write the modified svg text to the output file
        console.log('Writing final graph file to "' + graph_filepath + '"...');
        fs.writeFile(graph_filepath, svgInline, (err)=> {
            if (err) {
                console.error(err);
                return;
            }
            console.log('Write of graph file finished');
        });

        // Delete the builder HTML since it's no longer needed
        let builder_absolute_filepath = odr_web_dir + builder_filepath;
        console.log('Deleting builder HTML file "' + builder_absolute_filepath + '"...');
        fs.unlink(builder_absolute_filepath, (err) => {
            if (err) {
                console.error(err);
                return;
            }
            console.log('Builder HTML file deleted');
        });

        // Also need to delete any non-public files off the server
        files_to_delete.forEach((filepath) => {
            fs.unlink(filepath, (err) => {
                if (err) {
                    console.error(err);
                    return;
                }
                console.log('Non-public graph file "' + filepath + '" deleted');
            });
        });

        await page.close();
        return 'graph built';
    } catch (err) {
        console.error('Error thrown');
        throw(err);
    }
}

app();
