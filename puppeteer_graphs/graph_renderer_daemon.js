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
                console.log('reserved', job);

                let data = JSON.parse(job.data)
                // data: {"input_html":"Chart__63079_63081_63083_63085.html","output_svg":"\\/home\\/odr\\/data-publisher\\/app\\/tmp\\/graph_bea14f33-2105-4699-918d-667efead692a","selector":"Chart_9849ce6d_22b6_4546_8b8d_e435d2f01d50"}

                console.log('Starting job: ' + job.id)
                await buildGraph(data.input_html, data.output_svg, data.selector);

                client.deleteJob(job.id).onSuccess(function(del_msg) {
                    console.log('deleted', job);
                    // console.log('message', del_msg);
                    resJob();
                });
            });
        }
        resJob();
    });
}

async function buildGraph(page_url, output_svg, selector) {
    // configure folder and http url path
    // the folder contain all the html file
    const page = await browser.newPage();
    page.on('console', message =>
        console.log(`${message.type().substr(0, 3).toUpperCase()} ${message.text()}`)
    )
    await page.setViewport({ width: 1400, height: 800 });
    // await page.goto('https://beta.rruff.net/odr_rruff/uploads/files/Chart__25238_31858_31860.html');
    // console.log('https://nu.odr.io/uploads/files/' +  page_url);
    await page.goto('https://nu.odr.io/uploads/files/' +  page_url);
    await page.content();

    // Wait for javascript to render
    const watchDog = page.waitForFunction('window.odr_graph_status === "ready"');
    await watchDog;

    let html = await page.evaluate(() => document.querySelector('body').innerHTML);
    // console.log(html);
    // let svgInline = await page.evaluate(() => document.querySelector('#' + selector).innerHTML)
    let svgInline = await page.evaluate(() => document.querySelector('svg').outerHTML);

    fs.writeFile(output_svg,svgInline,(err)=>{
        if (err){
            console.error(err)
            return
        }
        console.log(`Write SVG finised`);
    });

    await page.close();
    return 'graph built';
}

app();