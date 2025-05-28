/*
 * https://github.com/akeyboardlife/puppeteer-save-svg/blob/master/main.js
 */

/* jshint esversion: 8 */

const puppeteer = require('puppeteer');
const fs = require('fs');
const { execSync } = require('child_process');


const bs = require('nodestalker');
// TODO Probably don't need these
// Will just query all changed RRUFF records ???
const client = bs.Client('127.0.0.1:11300');
const rruff_record_analyzer_tube = 'odr_rruff_record_analyzer';

// Post changed file to file builder using this tube
const file_builder_client = bs.Client('127.0.0.1:11300');
const file_builder_tube = 'odr_rruff_file_builder';

const rruff_file_finisher_client = bs.Client('127.0.0.1:11300');
const rruff_file_finisher_tube = 'odr_rruff_file_finisher';

let browser;
let token = '';

function delay(time) {
    return new Promise(function(resolve) {
        setTimeout(resolve, time);
    });
}

async function app() {
    browser = await puppeteer.launch({headless:'new'});
    console.log('RRUFF File Builder Start');
    client.watch(rruff_record_analyzer_tube).onSuccess(function() {
        function resJob() {
            client.reserve().onSuccess(
                async function(job) {
                // console.log('Reserved (' + Date.now() + '): ' , job);

                try {
                    // console.log("THE JOB"  + job.data);
                    let data = JSON.parse(job.data);

                    console.log('Starting job: ' + job.id);
                    console.log('Job Data: ', job);

                    /*
                       API TEST API TEST
                     */

                    // Login/get token
                    console.log('API URL: ', data.api_login_url);
                    let post_data = {
                        'username': data.api_user,
                        'password': data.api_key
                    };
                    let login_token = await apiCall(data.api_login_url, post_data, 'POST');
                    token = login_token.token;

                    // Create tracked Job


                    // If update, let's clear all files sin

                    /*

                    {
                        "id": 0,
                        "job_type": "ima_update",
                        "target_entity": "ima_page",
                        "additional_data":
                        "none",
                        "total": 755
                    }

                     */

                    // Initialize extension for temp files
                    let tmp_file_extension = Date.now();

                    let new_job = {
                        'job': {
                            'id': 0,
                            'job_type': 'rruff_file_update',
                            'target_entity': 'rruff_files',
                            'additional_data': tmp_file_extension,
                            'total': 99999999
                        }
                    };

                    // console.log('Creating Job:', data.api_create_job_url);
                    let tracked_job = await apiCall(data.api_create_job_url, new_job, 'POST');
                    console.log('Tracked Job ID: ', tracked_job.id);


                    /*
                       API TEST API TEST
                     */
                    /*
                    {
                        "ima_uuid":"0f59b751673686197f49f4e117e9",
                        "cell_params_uuid":"a85a97461686ef3dfe77e14e2209",
                        "mineral_data":"web\\/uploads\\/mineral_data.js",
                        "cell_params":"web\\/uploads\\/cell_params.js",
                        "cell_params_range":"web\\/uploads\\/cell_params_range.js",
                        "cell_params_synonyms":"web\\/uploads\\/cell_params_synonyms.js",
                        "tag_data":"web\\/uploads\\/master_tag_data.js",
                        "ima_url":"\\/\\/BASE_URL\\/odr_rruff",
                        "cell_params_url":"\\/\\/BASE_URL\\/odr_rruff"
                     }
                     */
                    let basepath = '/home/rruff/data-publisher/';

                    // TODO - No such thing as an update version??
                    /*
                        // data.cell_params_url
                    }
                     */

                    // Get RRUFF Records
                    if(data.full_rruff_url.match(/999999$/)) {
                        // This is an update - calculate time since last update
                        console.log('Checking last_updated.txt');
                        let stats = fs.statSync(
                            '/home/rruff/data-publisher/app/rruff_files/last_updated.txt'
                        );
                        let mtime = stats.mtime;
                        data.full_rruff_url = data.full_rruff_url.replace(/99999999/,mtime);
                        console.log(mtime);
                    }
                    else {
                        // touch the time file
                        console.log('touching last_updated.txt');
                        await execSync('touch last_updated.txt', {
                            cwd: '/home/rruff/data-publisher/app/rruff_files',
                            stdio: 'ignore'
                        });
                    }
                    let rruff_record_data = await loadPage(data.full_rruff_url);
                    console.log('RRUFF RECORDS: ', rruff_record_data.records.length);

                    // Determine number of records in job

                    // Push individual jobs to file builder queue
                    let job_count = 0;
                    for(let i = 0; i < rruff_record_data.records.length; i++) {
                    // for(let i = 185; i < 190; i++) {
                    // for(let i = 500; i < 1800; i++) {
                        let record = rruff_record_data.records[i];
                        // if(record.unique_id === '0b3152bc5a408202a2d6a1dea3de') {
                            // console.log(record);
                            record.api_user = data.api_user;
                            record.api_key = data.api_key;
                            record.api_login_url = data.api_login_url;
                            record.api_worker_job_url = data.api_worker_job_url;
                            record.api_job_status_url = data.api_job_status_url;
                            record.tracked_job_id = tracked_job.id;
                            record.file_extension = tmp_file_extension;
                            record.base_path = basepath;
                            record.base_url = data.base_url;

                            await file_builder_client.use(file_builder_tube);

                            let jobId = await file_builder_client.put(JSON.stringify(record));
                            job_count++;
                            console.log('RRUFF FILE Record Job ID: ', jobId);
                        // }
                    }

                    console.log('Job Count: ', job_count);

                    // console.log('Updating Job:', data.api_create_job_url);
                    tracked_job.total = job_count;
                    tracked_job = await apiCall(data.api_create_job_url, { 'job':  tracked_job}, 'PUT');
                    console.log('Tracked Job: ', tracked_job);


                    // Add job to finisher tube
                    let record = {};
                    record.api_user = data.api_user;
                    record.api_key = data.api_key;
                    record.api_login_url = data.api_login_url;
                    record.api_create_job_url = data.api_create_job_url;
                    record.api_worker_job_url = data.api_worker_job_url;
                    record.api_job_status_url = data.api_job_status_url;
                    record.tracked_job_id = tracked_job.id;
                    record.file_extension = tmp_file_extension;
                    record.base_path = basepath;
                    record.base_url = data.base_url;

                    rruff_file_finisher_client.use(rruff_file_finisher_tube)
                        .onSuccess(
                            () => {
                                rruff_file_finisher_client.put(JSON.stringify(record))
                                    .onSuccess(
                                        (jobId) => {
                                            console.log('IMA Record Job ID: ', jobId);
                                        }
                                );
                            }
                        );


                    // throw Error('Break for debugging.');

                    client.deleteJob(job.id).onSuccess(function() {
                        console.log('Deleted [complete] (' + Date.now() + '): ' , job);
                        resJob();
                    });
                }
                catch (e) {
                    // TODO need to put job as unfinished - maybe not due to errors
                    console.log('Error occurred: ', e);
                    client.deleteJob(job.id).onSuccess(function() {
                        console.log('Deleted (' + Date.now() + '): ' , job);
                        resJob();
                    });
                }
            });
        }
        resJob();
    });
}

async function apiCall(api_url, post_data, method) {
    console.log('API Call: ', api_url);
    try {
        const page = await browser.newPage();
        page.on('console', message =>
            console.log(`${message.type().substr(0, 3).toUpperCase()} ${message.text()}`)
        );

        // Allows you to intercept a request; must appear before
        // your first page.goto()
        await page.setRequestInterception(true);

        // Use bearer token if it is set.
        if(token !== '') {
            page.setExtraHTTPHeaders({
                'Authorization': 'Bearer ' + token
            });
        }

        // Request intercept handler... will be triggered with
        // each page.goto() statement
        page.on('request', interceptedRequest => {
            let data = {
                'method': method,
                headers: { ...interceptedRequest.headers(), 'content-type': 'application/json'}
            };

            if(post_data !== '') {
                data.postData = JSON.stringify(post_data);
            }

            // Request modified... finish sending!
            interceptedRequest.continue(data);
        });

        // Navigate, trigger the intercept, and resolve the response
        const response = await page.goto('https://' + api_url);
        const responseBody = JSON.parse(await response.text());

        await page.close();
        return responseBody;

    } catch (err) {
        console.error('Error thrown');
        throw(err);
    }
}

async function loadPage(page_url) {
    // console.log('Loading page: ', page_url);
    return await apiCall(page_url, '', 'GET');
}


app();
