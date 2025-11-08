/*
 * https://github.com/akeyboardlife/puppeteer-save-svg/blob/master/main.js
 */

/* jshint esversion: 8 */

const https = require('https');
const fs = require('fs');
const { execSync } = require('child_process');
const bs = require('nodestalker');
// TODO Probably don't need these
// Will just query all changed AMCSD records ???
const client = bs.Client('127.0.0.1:11300');
const amcsd_record_analyzer_tube = 'odr_amcsd_record_analyzer';

// Post changed file to file builder using this tube
const file_builder_client = bs.Client('127.0.0.1:11300');
const file_builder_tube = 'odr_amcsd_file_builder';

const amcsd_file_finisher_client = bs.Client('127.0.0.1:11300');
const amcsd_file_finisher_tube = 'odr_amcsd_file_finisher';

let token = '';

function delay(time) {
    return new Promise(function(resolve) {
        setTimeout(resolve, time);
    });
}

/**
 * Recursively clear all files from a directory while keeping directory structure intact
 * @param {string} dirPath - The directory path to clear
 */
function clearFilesRecursive(dirPath) {
    if (!fs.existsSync(dirPath)) {
        return;
    }

    const entries = fs.readdirSync(dirPath, { withFileTypes: true });

    for (let entry of entries) {
        const fullPath = dirPath + '/' + entry.name;

        if (entry.isDirectory()) {
            // Recursively clear files in subdirectory
            clearFilesRecursive(fullPath);
        } else {
            // Delete file
            console.log('  Deleting file: ', fullPath);
            fs.unlinkSync(fullPath);
        }
    }
}

/**
 * Clear all files from an array of directory paths
 * @param {string} basePath - The base path for all directories
 * @param {Array<string>} folders - Array of relative folder paths
 */
function clearFilesFromDirectories(basePath, folders) {
    console.log('Clearing files from directories...');
    let fileCount = 0;

    for (let folder of folders) {
        let fullPath = basePath + folder;
        console.log('Processing directory: ', fullPath);

        if (fs.existsSync(fullPath)) {
            const entriesBefore = fs.readdirSync(fullPath, { withFileTypes: true });
            const filesBefore = entriesBefore.filter(e => e.isFile()).length;

            clearFilesRecursive(fullPath);
            fileCount += filesBefore;

            console.log('  Cleared ' + filesBefore + ' file(s)');
        } else {
            console.log('  Directory does not exist, skipping');
        }
    }

    console.log('Finished clearing ' + fileCount + ' total file(s) from ' + folders.length + ' directories');
}

async function app() {
    console.log('AMCSD File Builder Start');
    client.watch(amcsd_record_analyzer_tube).onSuccess(function() {
        function resJob() {
            client.reserve().onSuccess(
                async function(job) {
                // console.log('Reserved (' + Date.now() + '): ' , job);

                try {
                    // console.log("THE JOB"  + job.data);
                    let data = JSON.parse(job.data);

                    console.log('Starting job: ' + job.id);
                    console.log('Job Data: ', job);

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
                            'job_type': 'amcsd_file_update',
                            'target_entity': 'amcsd_files',
                            'additional_data': tmp_file_extension,
                            'total': 99999999
                        }
                    };

                    console.log('Creating Job:', data.api_create_job_url);
                    let tracked_job = await apiCall(data.api_create_job_url, new_job, 'POST');
                    console.log('Tracked Job ID: ', tracked_job.id);

                    /*
                        The Data:  {
                            base_url: '//beta.rruff.net/odr_rruff',
                            ima_update_rebuild: true,
                            api_user: 'rruff-prod-api@odr.io',
                            api_key: 'XUY*bkd.rzd4qta6pcd',
                            rruff_database_uuid: 'ddc5e9ba834ad596cc31aebb1225',
                            recent: '99999999',
                            full_rruff_url: '//beta.rruff.net/odr_rruff/api/v5/search/database/ddc5e9ba834ad596cc31aebb1225/modified/records/1762453083202',
                            rruff_modified_files_url: '//beta.rruff.net/odr_rruff/api/v5/dataset/ddc5e9ba834ad596cc31aebb1225/modified_files/1762453083202',
                            api_login_url: '//beta.rruff.net/odr_rruff/api/v3/token',
                            api_worker_job_url: '//beta.rruff.net/odr_rruff/api/v4/worker/job',
                            api_create_job_url: '//beta.rruff.net/odr_rruff/api/v4/job',
                            api_job_status_url: '//beta.rruff.net/odr_rruff/api/v4/job/status'
                        }
                     */

                    let basepath = '/home/rruff/data-publisher/';
                    let file_basepath = '/home/rruff/data-publisher/app/amcsd_files/';
                    let folder_array = [
                        'cif',
                        'dif',
                        'amc'
                    ];

                    // Get AMCSD Records
                    if(data.full_amcsd_url.match(/999999$/)) {
                        // This is an update - calculate time since last update
                        console.log('Checking last_updated.txt');
                        let stats = fs.statSync(
                            file_basepath + 'last_updated.txt'
                        );
                        let mtime = stats.mtime.getTime();

                        // TODO Remove the hardcoded time
                        data.full_amcsd_url = data.full_amcsd_url.replace(/99999999/,mtime);
                        console.log('Updating files modified since: ' + mtime);

                        // Get all the modified files
                        // /dataset/{datatype_uuid}/deleted_files/{recent}
                        data.amcsd_modified_files_url = data.amcsd_modified_files_url.replace(/99999999/,mtime);
                        console.log('Modified Files: ', data.amcsd_modified_files_url);
                        let amcsd_modified_files = await loadPage(data.amcsd_modified_files_url);
                        // console.log('Modified File Count: ', amcsd_modified_files);
                        console.log('Modified File Count: ', amcsd_modified_files.files.length);
                        for(let i = 0; i < amcsd_modified_files.files.length; i++) {
                            // console.log('AMCSD Modified File: (' + i + '):: ', amcsd_modified_files.files[i]);

                            // Check if file exists in any of the directories
                            let fileName = amcsd_modified_files.files[i];
                            let fileFound = false;

                            for(let j = 0; j < folder_array.length; j++) {
                                let fullPath = file_basepath + folder_array[j] + fileName;

                                if(fs.existsSync(fullPath)) {
                                    try {
                                        fs.unlinkSync(fullPath);
                                        console.log('File deleted: ' + fullPath);
                                    } catch (err) {
                                        console.error('Error deleting file: ' + fullPath, err);
                                    }
                                    fileFound = true;
                                    break;
                                }
                            }

                            if(!fileFound) {
                                console.log('File not found: ' + fileName);
                            }
                        }
                    }
                    else {
                        console.log('FULL Rebuild Requested.  All files will be deleted.');
                        // Delete all files from the directory paths
                        // We're doing a full rebuild with full downloads of all files
                         clearFilesFromDirectories(file_basepath, folder_array);
                    }

                    console.log('data.full_amcsd_url: ', data.full_amcsd_url);
                    let amcsd_record_data = await loadPage(data.full_amcsd_url);

                    // Note - the number of amcsd records could be different than the
                    // number of records that had files deleted.  The AMCSD records that
                    // get analyzed are only public and non-deleted.
                    console.log('AMCSD RECORDS: ', amcsd_record_data.records.length);

                    console.log('Touching last_updated.txt');
                    // We do this here because we want subsequent launches of this system
                    // to only work on files that were modified after this one launched.
                    // We probably should modify the system to reject a launch or cancel the
                    // previous operation if the system is launched again.
                    await execSync('touch last_updated.txt', {
                        cwd: file_basepath,
                        stdio: 'ignore'
                    });

                    // Determine number of records in job
                    console.log('The Data: ', data)
                    // Push individual jobs to file builder queue
                    let job_count = 0;
                    await file_builder_client.use(file_builder_tube);
                    for(let i = 0; i < 1000; i++) {
                    // for(let i = 0; i < amcsd_record_data.records.length; i++) {
                        let record = amcsd_record_data.records[i];
                        record.api_user = data.api_user;
                        record.api_key = data.api_key;
                        record.api_login_url = data.api_login_url;
                        record.api_worker_job_url = data.api_worker_job_url;
                        record.api_job_status_url = data.api_job_status_url;
                        record.tracked_job_id = tracked_job.id;
                        record.file_extension = tmp_file_extension;
                        record.base_path = basepath;
                        record.base_url = data.base_url;

                        // Put it in the tube
                        await file_builder_client.put(JSON.stringify(record));
                        // console.log('AMCSD Record: ', record);
                        // console.log('AMCSD FILE Record Job ID: ', jobId);

                        job_count++;
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

                    amcsd_file_finisher_client.use(amcsd_file_finisher_tube)
                        .onSuccess(
                            () => {
                                amcsd_file_finisher_client.put(JSON.stringify(record))
                                    .onSuccess(
                                        (jobId) => {
                                            console.log('AMCSD Record Job ID: ', jobId);
                                        }
                                );
                            }
                        );


                    // throw Error('Break for debugging.');

                    client.deleteJob(job.id).onSuccess(function() {
                        console.log('Deleted [complete] (' + Date.now() + '): ' , job.id);
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

async function loadPage(page_url) {
    // console.log('Loading page: ', page_url);
    return await apiCall(page_url, '', 'GET');
}


app();
