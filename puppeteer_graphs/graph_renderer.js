/*
 * https://github.com/akeyboardlife/puppeteer-save-svg/blob/master/main.js
 */

const puppeteer = require('puppeteer');
const fs = require('fs');

(async()=>{
    // configure folder and http url path
    // the folder contain all the html file


    const folderPath='./'
    const browser = await puppeteer.launch({headless:true})


    const page = await browser.newPage();
    await page.setViewport({ width: 1200, height: 1200 });
    await page.goto('https://beta.rruff.net/odr_rruff/uploads/files/Chart__25238_31858_31860.html');

    let html = await page.content()

    const watchDog = page.waitForFunction('window.status === "ready"');
    await watchDog;

	console.log(html);
    let svgInline = await page.evaluate(() => document.querySelector('svg').innerHTML)

    if (!fs.existsSync(`${folderPath}svgs/`)){
        fs.mkdirSync(`${folderPath}svgs/`);
    }
    fs.writeFile(`${folderPath}svgs/test.svg`,svgInline,(err)=>{
        if (err){
            console.error(err)
            return
        }
        console.log(`Write SVG finised`)
    })

    await browser.close()
})()

function delay(timeout){
    return new Promise((resolve)=>{
        setTimeout(resolve,timeout)
    })
}
