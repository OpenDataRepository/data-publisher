/* jshint esversion: 8 */

const https = require('https');
const fs = require('fs');
const { execSync } = require('child_process');
const bs = require('nodestalker');
const client = bs.Client('127.0.0.1:11300');
const tube = 'odr_amcsd_file_finisher';

let token = '';

function delay(time) {
    return new Promise(function(resolve) {
        setTimeout(resolve, time)
    });
}

async function app() {
    console.log('RRUFF File Finisher Start');
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

                            let base_path = '/home/rruff/data-publisher/app/amcsd_files';
                            let web_path = '/home/rruff/data-publisher/web/AMS/zipped_files';

                            // TODO Move existing zips to legacy
                            // https://rruff.geo.arizona.edu/AMS/zipped_files/amc_archive_2024_01_02.zip
                            let file_objects = {};
                            file_objects['dif'] = 'dif.zip';
                            file_objects['cif'] = 'cif.zip';
                            file_objects['original_cif'] = 'original_cif.zip';
                            file_objects['amc'] = 'amc.zip';

                            for(let key of Object.keys(file_objects)) {
                                let file_name =  web_path + '/' + file_objects[key];
                                let dir_path = base_path + '/' + key;
                                console.log('Zipping: ', dir_path, ' to ', file_name);
                                if(
                                    fs.existsSync(dir_path)
                                    && !isEmpty(dir_path)
                                ) {
                                    console.log('Zip Start: ', new Date().toISOString() );
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

    return new Promise((resolve, reject) => {
        try {
            // Parse the URL to extract hostname and path
            const url = new URL('https://' + api_url);

            const options = {
                hostname: url.hostname,
                port: 443,
                path: url.pathname + url.search,
                method: method,
                headers: {
                    'Content-Type': 'application/json'
                }
            };

            // Add bearer token if set
            if(token !== '') {
                options.headers['Authorization'] = 'Bearer ' + token;
            }

            // Add content-length for POST/PUT requests
            if(post_data !== '' && (method === 'POST' || method === 'PUT')) {
                const postDataString = JSON.stringify(post_data);
                options.headers['Content-Length'] = Buffer.byteLength(postDataString);
            }

            const req = https.request(options, (res) => {
                let data = '';

                res.on('data', (chunk) => {
                    data += chunk;
                });

                res.on('end', () => {
                    try {
                        const responseBody = JSON.parse(data);
                        resolve(responseBody);
                    } catch (e) {
                        reject(new Error('Failed to parse JSON response: ' + e.message));
                    }
                });
            });

            req.on('error', (err) => {
                console.error('Error thrown');
                reject(err);
            });

            // Write POST/PUT data if present
            if(post_data !== '' && (method === 'POST' || method === 'PUT')) {
                req.write(JSON.stringify(post_data));
            }

            req.end();

        } catch (err) {
            console.error('Error thrown');
            reject(err);
        }
    });
}

/*
  Is directory empty?
 */
function isEmpty(path) {
    return fs.readdirSync(path).length === 0;
}

app();
