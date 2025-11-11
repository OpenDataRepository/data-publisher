const https = require('https');
const fs = require('fs');
const fg = require('fast-glob');
const path = require('path');

const { exec } = require('child_process');
const bs = require('nodestalker');
const client = bs.Client('127.0.0.1:11300');
const tube = 'odr_amcsd_file_builder';
const Memcached = require("memcached-promise");

let memcached_client;
let token = '';
let base_fs_path = '/home/rruff/data-publisher/app/amcsd_files';

/**
 * Check if a file exists and is up to date compared to the file_updated timestamp
 * @param {string} filePath - The path to the file to check
 * @param {string} fileUpdated - The updated timestamp from metadata
 * @returns {boolean} - True if file exists and is up to date, false otherwise
 */
function isFileUpToDate(filePath, fileUpdated) {
    try {
        const stats = fs.statSync(filePath);
        const fileModTime = new Date(stats.mtime);
        const fileUpdatedDate = new Date(fileUpdated);

        console.log('Checking file: ', filePath);
        console.log('  File modification time: ', fileModTime.toISOString());
        console.log('  Metadata updated time:  ', fileUpdatedDate.toISOString());

        if (fileModTime >= fileUpdatedDate) {
            // File exists and is up to date
            console.log('  Result: File is up to date, keeping existing file');
            return true;
        } else {
            console.log('  Result: File is outdated, will re-download');
            return false;
        }
    } catch (error) {
        // File doesn't exist or can't be accessed
        console.log('Checking file: ', filePath);
        console.log('  Result: File does not exist or cannot be accessed, will download');
        return false;
    }
}

/*
    AMCSD Related UUIDs
 */
let template_uuids = [];
template_uuids['original_cif_file'] = '6430c61b2291b0af2bb7f7de17ae';  // Original CIF
template_uuids['cif_file'] = 'e3574a2f9e3dd0e33bac9f65070a';  // Minimal CIF
template_uuids['dif_file'] = '6c98e6ab485d594f0034d370feb8';
template_uuids['amc_file'] = '303a7211c55c99fe5a655d936c51';

async function app() {
    memcached_client = new Memcached('localhost:11211', {retries: 10, retry: 10000, remove: false});

    console.log('AMCSD FILE Builder Start');
    client.watch(tube).onSuccess(function (data) {
        function resJob() {
            client.reserve().onSuccess(async function (job) {
                // console.log('Reserved (' + Date.now() + '): ' , job);
                try {
                    let record = JSON.parse(job.data);
                    console.log('Starting job: ' + job.id);
                    // Login/get token
                    // Get token from memcached
                    token = '';

                    let token_data = await memcached_client.get('ima_api_token');
                    if (token_data !== undefined && token_data !== '') {
                        let token_object = JSON.parse(token_data);
                        // if token timestamp > 2 minutes old, get new token
                        if (token_object.timestamp < (Date.now() - 2 * 60 * 1000)) {
                            // Get new token and set timestamp
                            let token_obj = await getToken(record);
                            token = token_obj.token;
                        } else {
                            // console.log('Using token: ' + token_object.token);
                            token = token_object.token;
                        }
                    } else {
                        // Set token
                        let token_obj = await getToken(record);
                        token = token_obj.token;
                    }

                    /*
                        path: ^/api/{version}/dataset/record/{record_uuid}
                     */
                    let record_url = record.base_url + '/api/v5/dataset/record/' + record.unique_id;
                    let record_data = await loadPage(record_url);

                    console.log('Record Data: ', record_data);

                    // AMCSD record has only fields
                    let fields = record_data['fields_' + record_data.database_uuid];
                    for(let i = 0; i < fields.length; i++) {
                        let field = fields[i];
                        if(field['field_' + template_uuids['amc_file']] !== undefined) {
                            await processFile(field['field_' + template_uuids['amc_file']], 'amc');
                        }
                        else if(field['field_' + template_uuids['original_cif_file']] !== undefined) {
                            await processFile(field['field_' + template_uuids['original_cif_file']], 'cif');
                        }
                        else if(field['field_' + template_uuids['cif_file']] !== undefined) {
                            await processFile(field['field_' + template_uuids['cif_file']], 'cif');
                        }
                        else if(field['field_' + template_uuids['dif_file']] !== undefined) {
                            await processFile(field['field_' + template_uuids['dif_file']], 'dif');
                        }
                    }

                    let worker_job = {
                        'job': {
                            'tracked_job_id': record.tracked_job_id,
                            'random_key': 'AMCSD_FILE_' + Math.floor(Math.random() * 99999999).toString(),
                            'job_order': 0,
                            'line_count': 1
                        }
                    };
                    worker_job = await apiCall(record.api_worker_job_url, worker_job, 'POST');
                    // console.log('Worker Job: ', worker_job);

                    client.deleteJob(job.id).onSuccess(function (del_msg) {
                        // console.log('Deleted (' + Date.now() + '): ' , job);
                        resJob();
                    });
                } catch (e) {
                    // TODO need to put job as unfinished - maybe not due to errors
                    console.log('Error occurred: ', e);
                    // Depending on Error we might want to retry
                    // I.E. Expired token jobs should not be deleted
                    client.deleteJob(job.id).onSuccess(function (del_msg) {
                        // console.log('Deleted (' + Date.now() + '): ' , job);
                        console.log('Deleted (' + Date.now() + '): ', job.id);
                        resJob();
                    });
                }
            });
        }
        resJob();
    });
}


async function processFile(field, file_type) {
    console.log('\n\n\n\nPROCESS FILE: ', field.files);
    let directory_files = [];
    let valid_files = [];
    if(
        field !== undefined
        && field.files !== undefined
        && field.files[0] !== undefined
    ) {
        let file = field.files[0];

        if(file !== undefined) {
            let file_uuid = field.files[0].file_uuid;
            console.log('File UUID: ', file_uuid);
            let file_name = field.files[0].original_name;
            console.log('File Name: ', file_name);
            let file_url = field.files[0].href;
            let file_updated = field.files[0]._file_metadata._create_date;

            // Determine Directory Name
            let output_file_directory = '';
            switch (file_type) {
                case 'dif':
                    output_file_directory = 'dif';
                    break;
                case 'cif':
                    output_file_directory = 'cif';
                    break;
                case 'amc':
                    output_file_directory = 'amc';
                    break;
            }

            let folder = base_fs_path + '/' + output_file_directory;
            let stub = file_name;
            let files_search = folder + '/' + stub + '*';
            console.log('Search for files: ', folder + '/' + stub + '*');
            // stubs.push(folder + '/' + stub);
            const entries = fg.sync([files_search], { dot: false });
            console.log('Entries: ', entries);
            directory_files.push(...entries)

            // This is a valid file
            let new_file = folder + '/' + file_name;
            valid_files.push(new_file);

            let found = false;
            for(let k = 0; k < entries.length; k++) {
                if(entries[k] === new_file) {
                    // Check if file exists and is up to date
                    found = isFileUpToDate(entries[k], file_updated);
                }
            }

            if(!found) {
                // Download and write file
                const downloadPath = path.resolve(folder); // Ensure this directory exists or create it
                if (fs.existsSync(downloadPath)){
                    console.log('Directory found: ', downloadPath);
                    try {
                        console.log('Download file: ', file_url);
                        await wgetFile(file_url, new_file);
                    }
                    catch(e) {
                        console.log('Error downloading file: ', e);
                    }
                }
                else {
                    console.log('Directory not found: ', downloadPath);
                }
            }
        }
    }

    for(let i= 0; i < directory_files.length; i++) {
        console.log('Validating file: ', directory_files[i]);
        if(valid_files.indexOf(directory_files[i]) === -1) {
            // Delete this file
            console.log('Deleting file: ', directory_files[i]);
            fs.unlinkSync(directory_files[i]);
        }
    }
}

async function getToken(record) {
    // Get new token and set timestamp
    // console.log('API URL: ', record.api_login_url);
    let post_data = {
        'username': record.api_user,
        'password': record.api_key
    };
    let token_obj = await apiCall(record.api_login_url, post_data, 'POST');
    token_obj.timestamp = Date.now();
    await memcached_client.set('ima_api_token', JSON.stringify(token_obj), 180);
    return token_obj;
}

async function loadPage(page_url) {
    return await apiCall(page_url, '', 'GET');
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

async function wgetFile(url, filename) {
    let cmd = 'wget -O "' + filename + '" ' + url;
    let child = await exec(
        cmd,
        async function (error, stdout, stderr) {
            console.log('stdout: ' + stdout);
            console.log('stderr: ' + stderr);
            if (error !== null) {
                console.log('exec error: ' + error);
            }
        }
    );
}

app();
