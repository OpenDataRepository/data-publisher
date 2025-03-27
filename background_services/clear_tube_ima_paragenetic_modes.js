/*
 * https://github.com/akeyboardlife/puppeteer-save-svg/blob/master/main.js
 */

const puppeteer = require('puppeteer');
const fs = require('fs');

const bs = require('nodestalker');
const client = bs.Client('127.0.0.1:11300');
const tube = 'odr_paragenetic_modes_record_builder';
let browser;

function delay(time) {
    return new Promise(function(resolve) {
        setTimeout(resolve, time)
    });
}

async function app() {
    browser = await puppeteer.launch({headless:'new'});
    console.log('Clearing Tube IMA Paragenetic Modes...');
    client.watch(tube).onSuccess(function(data) {
        function resJob() {
            client.reserve().onSuccess(async function(job) {
                try {
                    client.deleteJob(job.id).onSuccess(function(del_msg) {
                        console.log('Deleted Job: ', job);
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

app();
