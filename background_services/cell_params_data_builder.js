/*
 * https://github.com/akeyboardlife/puppeteer-save-svg/blob/master/main.js
 *
 * DEPRECATED - NAS 20250122
 *
 */

const puppeteer = require('puppeteer');
const fs = require('fs');

const bs = require('nodestalker');
const client = bs.Client('127.0.0.1:11300');
const tube = 'odr_cell_params_data_builder';
let browser;

function delay(time) {
    return new Promise(function(resolve) {
        setTimeout(resolve, time)
    });
}

async function app() {
    browser = await puppeteer.launch({headless:'new'});
    client.watch(tube).onSuccess(function(data) {
        function resJob() {
            client.reserve().onSuccess(async function(job) {
                console.log('Reserved (' + Date.now() + '): ' , job);

                try {
                    let data = JSON.parse(job.data)

                    console.log('Starting job: ' + job.id)
                    await loadPage(data.url);

                    client.deleteJob(job.id).onSuccess(function(del_msg) {
                        console.log('Deleted (' + Date.now() + '): ' , job);
                        resJob();
                    });
                }
                catch (e) {
                    // TODO need to put job as unfinished - maybe not due to errors
                    console.log('Error occurred: ', e);
                    client.deleteJob(job.id).onSuccess(function(del_msg) {
                        console.log('Deleted (' + Date.now() + '): ' , job);
                        resJob();
                    });
                }
            });
        }
        resJob();
    });
}

async function loadPage(page_url) {
    // configure folder and http url path
    const page = await browser.newPage();
    page.on('console', message =>
        console.log(`${message.type().substr(0, 3).toUpperCase()} ${message.text()}`)
    );
    await page.setViewport({ width: 1400, height: 4800 });

    try {
        // let contentHtml = fs.readFileSync('/home/odr/data-publisher/web/uploads/files/' + page_url, 'utf8');
        // console.log('HTML Retrieved', contentHtml)
        // await page.setContent(contentHtml);
        console.log('Content Set');
        let result = await page.goto('https:' + page_url, {timeout: 30000});
        if (result.status() === 404) {
            console.error('404 status code found in result');
            throw('404 - file not found');
        }

        await page.content();


        console.log('Waiting 4s');
        // Delay to let AJAX Content Load
        // TODO Replace with call to ensure content is loaded
        await delay(4000);
        // let html = await page.evaluate(() => document.querySelector('body').innerHTML);
        // console.log(html);

        // console.log('Taking Screenshot');
        // await page.screenshot({
            // path: '/tmp/screenshot_' + Math.floor(Math.random() * 1000000) + '.jpg'
        // });
        // console.log('End Screenshot');

        await page.close();
        return 'page loaded';

    } catch (err) {
        console.error('Error thrown');
        throw(err);
    }
}

app();
