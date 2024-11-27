/*
 * https://github.com/akeyboardlife/puppeteer-save-svg/blob/master/main.js
 */

/* jshint esversion: 8 */

const puppeteer = require('puppeteer');
const fs = require('fs');

const bs = require('nodestalker');
const client = bs.Client('127.0.0.1:11300');
const tube = 'odr_ima_data_finisher';

let browser;
let token = '';

function delay(time) {
    return new Promise(function(resolve) {
        setTimeout(resolve, time)
    });
}

async function app() {
    browser = await puppeteer.launch({headless:'new'});
    console.log('IMA Data Finisher Start');
    client.watch(tube).onSuccess(function(tubeName) {
        function resJob() {
            client.reserve().onSuccess(async function(job) {
                // console.log('Reserved (' + Date.now() + '): ' , job);

                try {
                    // console.log('THE JOB' + job.data);
                    let data = JSON.parse(job.data);

                    // console.log('Starting job: ' + job.id);
                    // console.log('Job Data: ', job);

                    // throw Error('Break for debugging.');
                    /*
                       API TEST API TEST
                     */

                    // Login/get token
                    // console.log('API URL: ', data.api_login_url);
                    let post_data = {
                        'username': data.api_user,
                        'password': data.api_key
                    };
                    let login_token = await apiCall(data.api_login_url, post_data, 'POST');
                    token = login_token.token;
                    // console.log('Login Token: ', login_token.token);


                    // Check Status of Job
                    // console.log(data.api_job_status_url + ' -- ' + data.tracked_job_id);
                    let status_url = data.api_job_status_url + '/' + data.tracked_job_id + '/1';
                    let tracked_job = await apiCall(status_url, '', 'GET');
                    if(tracked_job && tracked_job.id !== undefined) {
                        // console.log('Tracked Job Status: ', tracked_job);
                        // If total = current - process files & mark complete
                        if(tracked_job.current === tracked_job.total) {
                            let output_path = data.base_path + '/web/uploads/IMA';
                            if(data.ima_update_rebuild) {
                                // move files to be "updates" and update load file with timestamp
                                // Mineral Data
                                await fs.rename(data.mineral_data_filename, output_path + '/mineral_data_update.js', () => {});

                                // Mineral Name List
                                await fs.rename(data.mineral_data_include_filename, output_path + '/mineral_names_update.php', () => {});

                                // Cell Params Data
                                await fs.rename(data.cell_params_filename, output_path + '/cellparams_data_update.js', () => {});

                                // References Data
                                await fs.rename(data.references_filename, output_path + '/references_update.js', () => {});

                                // Master Tag Data
                                await fs.rename(data.master_tag_data_filename, output_path + '/master_tag_data.js', () => {});
                            }
                            else {
                                // replace base files with new ones
                                // let basepath = '/home/rruff/data-publisher/';
                                // record.mineral_data_filename = mineral_data_filename;
                                // record.cell_params_filename = cell_params_filename;
                                // record.references_filename = references_filename;
                                // record.master_tag_data_filename = master_tag_data_filename;

                                // Mineral Data
                                await fs.rename(data.mineral_data_filename, output_path + '/mineral_data.js', () => {});
                                await fs.rename(data.mineral_data_include_filename, output_path + '/mineral_names.php', () => {});

                                // Cell Params Data
                                console.log('CELL PARAMS FILE: ' + data.cell_params_filename);
                                await fs.rename(data.cell_params_filename, output_path + '/cellparams_data.js', () => {});

                                // References Data
                                await fs.rename(data.references_filename, output_path + '/references.js', () => {});

                                // Master Tag Data
                                await fs.rename(data.master_tag_data_filename, output_path + '/master_tag_data.js', () => {});

                                // Delete tmp files
                                // await deleteTmpFiles(data);

                                // Overwrite existing update files with empty files
                                await writeFile(output_path + '/mineral_data_update.js', '');
                                await writeFile(output_path + '/cellparams_data_update.js', '');
                                await writeFile(output_path + '/references_update.js', '');
                                await writeFile(output_path + '/master_tag_data_update.js', '');


                            }

                            // Mark complete using update job
                            // console.log('Updating Job:', data.api_create_job_url);
                            tracked_job.completed = 1;
                            tracked_job = await apiCall(data.api_create_job_url, { 'job':  tracked_job}, 'PUT');
                            // console.log('Tracked Job: ', tracked_job);
                        }
                        else {
                            client.use(tube)
                                .onSuccess(
                                    (tubeName) => {
                                        if(data.counter === undefined) {
                                            data.counter = 1;
                                        }
                                        else {
                                            data.counter++;
                                        }
                                        // Limit to 50000s of run time 10000 count @5s each
                                        if(data.counter < 10000) {
                                            client.put(JSON.stringify(data),1,5).onSuccess((jobId) => {
                                                console.log('IMA Job Finisher ID: ', jobId);
                                            });
                                        }
                                    }
                                );
                        }
                        // Delete Job - Complete or re-queued
                        client.deleteJob(job.id).onSuccess(function(del_msg) {
                            console.log('Deleted 2 (' + Date.now() + '): ' , job.id);
                            resJob();
                        });
                    }
                    else {
			// TODO Figure out why deleting causes node.fs error/crash
                        // await deleteTmpFiles(data);

                        // throw Error and delete beanstalk job
			console.log('Throwing not found error');
                        throw('Job not found.');
                    }
                }
                catch (e) {
                    // console.log('Error occurred: ', e);
                    client.deleteJob(job.id).onSuccess(function(del_msg) {
                        console.log('Deleted 1 (' + Date.now() + '): ' , job.id);
                        resJob();
                    });
                }
            });
        }
        resJob();
    });
}

async function deleteTmpFiles(data) {
    // Job not found - delete tmp file
    console.log('Deleting temp files.');
    try {
        await fs.exists(data.mineral_data_filename, async (exists) => {
            if (exists) {
                await fs.rm(data.mineral_data_filename);
            }
        });
    
        // Cell Params Data
        await fs.exists(data.data.cell_params_filename, async (exists) => {
            if (exists) {
                await fs.rm(data.cell_params_filename);
            }
        });
    
        // References Data
        await fs.exists(data.references_filename, async (exists) => {
            if (exists) {
                await fs.rm(data.references_filename);
            }
        });
    
        // Master Tag Data
        await fs.exists(data.master_tag_data_filename, async (exists) => {
            if (exists) {
                await fs.rm(data.master_tag_data_filename);
            }
        });
    }
    catch (e) {
        console.log('Temp file deletion error');
    }
}

const sleep = (delay) => new Promise((resolve) => setTimeout(resolve, delay));

async function writeFile(file_name, content) {
    try {
        fs.writeFileSync(file_name, content);
        // file written successfully
    } catch (err) {
        console.log(err);
    }
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
            // console.log('Adding Bearer Token');
            page.setExtraHTTPHeaders({
                'Authorization': 'Bearer ' + token
            });
        }

        // Request intercept handler... will be triggered with
        // each page.goto() statement
        page.on('request', interceptedRequest => {
            let data = {
                'method': method,
                headers: { ...interceptedRequest.headers(), "content-type": "application/json"}
            };

            if(post_data !== '') {
                // console.log('Attaching POST Data', post_data);
                data['postData'] = JSON.stringify(post_data);
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

const https = require('https')

let http_options = {
    hostname: '',
    port: 443,
    path: '/',
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
    },
};

/**
 * Do a request with options provided.
 *
 * @param {Object} options
 * @param {Object} data
 * @return {Promise} a promise of request
 */
function doRequest(options, data) {
    return new Promise((resolve, reject) => {
        const req = https.request(options, (res) => {
            res.setEncoding('utf8');
            let responseBody = '';

            res.on('data', (chunk) => {
                responseBody += chunk;
            });

            res.on('end', () => {
                resolve(JSON.parse(responseBody));
            });
        });

        req.on('error', (err) => {
            reject(err);
        });

        req.write(data);
        req.end();
    });
}

app();
