/*
 * https://github.com/akeyboardlife/puppeteer-save-svg/blob/master/main.js
 */

const puppeteer = require('puppeteer');
const fs = require('fs');

async function buildGraph() {
    // configure folder and http url path
    // the folder contain all the html file

    // node graph_renderer.js <source_html> <ouput_svg>
    if (process.argv.length !== 5) {
        console.error('Expected 3 arguments.');
        process.exit(1);
    }
    console.log(process.argv)

    let page_url = process.argv[2]
    let output_svg = process.argv[3]
    let selector = process.argv[4]
    console.log('Selector: ' + selector)

    const browser = await puppeteer.launch({headless:'new'})
    const page = await browser.newPage();
    page.on('console', message =>
        console.log(`${message.type().substr(0, 3).toUpperCase()} ${message.text()}`)
    )
    await page.setViewport({ width: 1400, height: 800 });
    // await page.goto('https://beta.rruff.net/odr_rruff/uploads/files/Chart__25238_31858_31860.html');
    await page.goto(page_url);
    await page.content();

    // Wait for javascript to render
    const watchDog = page.waitForFunction('window.odr_graph_status === "ready"');
    await watchDog;

    let html = await page.evaluate(() => document.querySelector('body').innerHTML)
    console.log(html);
    // let svgInline = await page.evaluate(() => document.querySelector('#' + selector).innerHTML)
    let svgInline = await page.evaluate(() => document.querySelector('svg').outerHTML)

    fs.writeFile(output_svg,svgInline,(err)=>{
        if (err){
            console.error(err)
            return
        }
        console.log(`Write SVG finised`)
    })

    await browser.close()
    return 'graph built'
}

async function app() {
    let done = await buildGraph();
    console.log(done)
}

app();