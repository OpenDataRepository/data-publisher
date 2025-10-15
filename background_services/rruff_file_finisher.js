/*
 * https://github.com/akeyboardlife/puppeteer-save-svg/blob/master/main.js
 */

/* jshint esversion: 8 */

const puppeteer = require('puppeteer');
const fs = require('fs');

const { execSync } = require('child_process');

const bs = require('nodestalker');
const client = bs.Client('127.0.0.1:11300');
const tube = 'odr_rruff_file_finisher';

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

                    console.log('Starting job: ' + job.id);
                    console.log('Job Data: ', job);

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
                            // TODO Build Zip Files Here

                            let base_path = '/home/rruff/data-publisher/app/rruff_files';
                            let web_path = '/home/rruff/data-publisher/web/zipped_data_files';

                            let file_objects = {};
                            file_objects['raman/lr-raman'] = 'raman/LR-Raman.zip';
                            file_objects['raman/excellent_oriented'] = 'raman/excellent_oriented.zip';
                            file_objects['raman/excellent_unoriented'] = 'raman/excellent_unoriented.zip';
                            file_objects['raman/fair_oriented'] = 'raman/fair_oriented.zip';
                            file_objects['raman/fair_unoriented'] = 'raman/fair_unoriented.zip';
                            file_objects['raman/poor_oriented'] = 'raman/poor_oriented.zip';
                            file_objects['raman/poor_unoriented'] = 'raman/poor_unoriented.zip';
                            file_objects['raman/unrated_oriented'] = 'raman/unrated_oriented.zip';
                            file_objects['raman/unrated_unoriented'] = 'raman/unrated_unoriented.zip';
                            file_objects['raman/ignore_oriented'] = 'raman/ignore_oriented.zip';

                            file_objects['rruff_good_images'] = 'rruff_good_images.zip';
                            file_objects['chemistry/microprobe_data'] = 'chemistry/Microprobe_Data.zip';
                            file_objects['chemistry/reference_pdf'] = 'chemistry/Reference_PDF.zip';

                            file_objects['infrared/processed'] = 'infrared/Processed.zip';
                            file_objects['infrared/raw'] = 'infrared/RAW.zip';

                            file_objects['powder/dif'] = 'powder/DIF.zip';
                            file_objects['powder/reference_pdf'] = 'powder/Reference_PDF.zip';
                            file_objects['powder/refinement_data'] = 'powder/Refinement_Data.zip';
                            file_objects['powder/refinement_output_data'] = 'powder/Refinement_Output_Data.zip';
                            file_objects['powder/xy_processed'] = 'powder/XY_Processed.zip';
                            file_objects['powder/xy_raw'] = 'powder/XY_RAW.zip';

                            for(let key of Object.keys(file_objects)) {
                                let file_name =  web_path + '/' + file_objects[key];
                                let dir_path = base_path + '/' + key;
                                console.log('Zipping: ', dir_path, ' to ', file_name);
                                if(
                                    fs.existsSync(dir_path)
                                    && !isEmpty(dir_path)
                                ) {
                                    console.log('Zip Start: ', new Date().toISOString() );
                                    // && fs.lstatSync(dir_path).isDirectory()
                                    try {
                                        await execSync('zip -FSr ' + file_name  + ' *', {
                                            cwd: dir_path,
                                            stdio: 'ignore'
                                        });
                                    }
                                    catch (e) {
                                        if(e.status !== 12) {
                                            console.log('Error occurred: ', e);
                                        }
                                    }
                                    console.log('Zip End: ', new Date().toISOString() );
                                }
                            }

                            // Mark complete using update job
                            tracked_job.completed = 1;
                            tracked_job = await apiCall(data.api_create_job_url, { 'job':  tracked_job}, 'PUT');
                            console.log('Tracked Job: ', tracked_job.total);
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
                    console.log('Error occurred: ', e);
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

const sleep = (delay) => new Promise((resolve) => setTimeout(resolve, delay));

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

/*
  Is directory empty?
 */
function isEmpty(path) {
    return fs.readdirSync(path).length === 0;
}

app();
