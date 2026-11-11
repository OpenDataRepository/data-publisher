require('./dev_env');  // load .env + relax Node TLS for self-signed dev hosts
/* jshint esversion: 8 */

const api = require('./api_client');
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

                // Declared out here so the catch block can re-queue it (with _files_done)
                let data;
                try {
                    // console.log('THE JOB' + job.data);
                    data = JSON.parse(job.data);

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

                            if(data._files_done) {
                                // An earlier attempt already zipped the files, then lost its connection
                                //  before marking the job complete
                                console.log('Files already zipped; marking the job complete.');
                            }
                            else {
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
                            }
                            // From here on a retry must only mark the job complete
                            data._files_done = true;

                            // Mark complete using update job
                            tracked_job.completed = 1;
                            tracked_job = await apiCall(data.api_create_job_url, { 'job':  tracked_job}, 'PUT');
                            console.log('Tracked Job: ', tracked_job.total);
                        }
                        else {
                            client.use(tube)
                                .onSuccess(
                                    (tubeName) => {
                                        // This check got through; connection retries start over
                                        delete data._connection_retries;
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
                    // A dropped connection is not a reason to abandon the build: put the job
                    //  back (with _files_done, if the files were already zipped)
                    await api.requeueIfConnectionError(e, client, tube, job, data);
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

// Shared client (api_client.js) -- retries dropped connections where that is safe
async function apiCall(api_url, post_data, method) {
    return api.apiCall(api_url, post_data, method, { token: token });
}

/*
  Is directory empty?
 */
function isEmpty(path) {
    return fs.readdirSync(path).length === 0;
}

app();
