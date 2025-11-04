/*
 * https://github.com/akeyboardlife/puppeteer-save-svg/blob/master/main.js
 */

const https = require('https');
const fs = require('fs');

const bs = require('nodestalker');
const client = bs.Client('127.0.0.1:11300');
const tube = 'odr_paragenetic_modes_record_builder';
const Memcached = require("memcached-promise");

let memcached_client;
let token = '';

function delay(time) {
    return new Promise(function(resolve) {
        setTimeout(resolve, time)
    });
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

async function app() {
    memcached_client = new Memcached('localhost:11211', {retries: 10, retry: 10000, remove: false});

    console.log('IMA Paragenetic Modes Builder Start');
    client.watch(tube).onSuccess(function(data) {
        function resJob() {
            client.reserve().onSuccess(async function(job) {
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
                        /*
                          {
                            token: [token],
                            timestamp: [timestamp] // seconds since epoch UTC
                          }
                        */
                        // if token timestamp > 2 minutes old, get new token
                        if (token_object.timestamp < (Date.now() - 2 * 60 * 1000)) {
                            // Get new token and set timestamp
                            let token_obj = await getToken(record);
                            token = token_obj.token;
                        } else {
                            token = token_object.token;
                        }
                    } else {
                        // Set token
                        let token_obj = await getToken(record);
                        token = token_obj.token;
                    }







                    // Check Status of Job
                    // console.log(data.api_job_status_url + ' -- ' + data.tracked_job_id);
                    /*
                    let status_url = record.api_job_status_url + '/' + record.tracked_job_id + '/0';
                    let tracked_job = await apiCall(status_url, '', 'GET');
                    if(
                        tracked_job.error !== undefined
                        && ( tracked_job.error.code === 500
                            || tracked_job.error.code === 404)
                    ) {
                        throw Error('Job canceled.');
                    }

                     */

                    let record_url = record.base_url + '/api/v5/dataset/record/' + record.unique_id;

                    // Need to get token??
                    let record_data = await loadPage(record_url);
                    // console.log(record_data);

                    let content = '' +
                        'paragenetic_modes[\'' + record_data['records_' + record_data.database_uuid][0]['record_uuid'] + '\']={ "tags": "' +
                            await findValue(record.paragenetic_modes_record_map.tags_field_uuid, record_data) +
                        '"};\n';

                    // console.log('CONTENT: ' + content);
                    // console.log('writeFile: ' + record.base_path + record.pm_data + '.' + record.file_extension);
                    await appendFile( record.base_path + record.pm_data + '.' + record.file_extension, content);


                    /*
                        {
                           "user_email": "nate@opendatarepository.org",
                           "job": {
                               "tracked_job_id": 1866,
                               "random_key": "random_key_1866",
                               "job_order": 0,
                               "line_count": 1
                           }
                       }
                    */
                    let worker_job = {
                        'job': {
                            'tracked_job_id': record.tracked_job_id,
                            'random_key': 'IMA_PM_' + job.id,
                            'job_order': 0,
                            'line_count': 1
                        }
                    };
                    worker_job = await apiCall(record.api_worker_job_url, worker_job, 'POST');
                    // console.log('Worker Job: ', worker_job);

                    client.deleteJob(job.id).onSuccess(function(del_msg) {
                        // console.log('Deleted Success (' + Date.now() + ')');
                        resJob();
                    });
                }
                catch (e) {
                    // TODO need to put job as unfinished - maybe not due to errors
                    console.log('Error occurred: ', e);
                    client.deleteJob(job.id).onSuccess(function() {
                        // console.log('Deleted (' + Date.now() + '): ' , job);
                        console.log('Deleted due to Error (' + Date.now() + '): ' , job.id);
                        resJob();
                    });
                }
            });
        }
        resJob();
    });
}

async function writeFile(file_name, content) {
    try {
        fs.writeFileSync(file_name, content);
        // file written successfully
    } catch (err) {
        console.log(err);
    }
}

async function appendFile(file_name, content) {
    try {
        fs.appendFileSync(file_name, content);
        // file written successfully
    } catch (err) {
        console.log(err);
    }
}

function formatChemistry(str) {

    // No point running regexp if there's nothing in the string
    if (str === ' ')
        return str;

    // Apply the superscripts...
    str = str.replace(/_([^_]+)_/g, '<sub>$1</sub>');

    // Apply the superscripts...
    str = str.replace(/\^([^\^]+)\^/g, '<sup>$1</sup>');

    // Redo the boxes...
    while ( str.indexOf('[box]') !== -1 )
        str = str.replace(/\[box\]/, '&#9744;'); // <span style="border: 1px solid #333; font-size:7px;">&nbsp;&nbsp;&nbsp;</span>');

    return str;
    // str = str.replace(/'/g, "\\'");

}

async function loadPage(page_url) {
    return await apiCall(page_url, '', 'GET');
}

async function findValue(field_uuid, record) {
    /*
    if(
        record !== undefined
        && record['fields_' + record.template_uuid] !== undefined
        && record['fields_' + record.template_uuid].length > 0
    ) {
        let fields = record['fields_' + record.template_uuid];
        // Using V5 we can directly access the field

        for(let i = 0; i < fields.length; i++) {
            // console.log('Field: ', fields[i][Object.keys(fields[i])[0]]);
            // Fix to match v5 record format from API template system
            let the_field = fields[i][Object.keys(fields[i])[0]];
            if(the_field.template_field_uuid !== undefined && the_field.template_field_uuid === field_uuid) {
                if(
                    the_field.files !== undefined
                    && the_field.files[0] !== undefined
                    && the_field.files[0].href !== undefined
                ) {
                    // console.log('Getting file: ', the_field.files[0].href)
                    return the_field.files[0].href;
                }
                if(the_field.value !== undefined) {
                    return the_field.value.toString().replace(/'/g, "\\'");
                }
                else if(the_field.tags !== undefined) {
                    let output = '';
                    for(let j = 0; j < the_field.tags.length; j++) {
                        output += the_field.tags[j].id + ' ';
                    }
                    output = output.replace(/,\s$/, '');
                    return output;
                }
                else if(the_field.values !== undefined) {
                    let output = '';
                    for(let j = 0; j < the_field.values.length; j++) {
                        output += the_field.values[j].name + ', ';
                    }
                    output = output.replace(/,\s$/, '');
                    return output;
                }
                else {
                    return '';
                }
            }
            else if(the_field.field_uuid !== undefined && the_field.field_uuid === field_uuid) {
                if(
                    the_field.files !== undefined
                    && the_field.files[0] !== undefined
                    && the_field.files[0].href !== undefined
                ) {
                    // console.log('Getting file 2: ', the_field.files[0])
                    return the_field.files[0].href;
                }
                if(the_field.value !== undefined) {
                    return the_field.value.toString().replace(/'/g, "\\'");
                }
                else if(the_field.tags !== undefined) {
                    let output = '';
                    for(let j = 0; j < the_field.tags.length; j++) {
                        output += the_field.tags[j].id + ' ';
                    }
                    output = output.replace(/,\s$/, '');
                    return output.trim();
                }
                else if(the_field.values !== undefined) {
                    let output = '';
                    for(let j = 0; j < the_field.values.length; j++) {
                        output += the_field.values[j].name + ', ';
                    }
                    output = output.replace(/,\s$/, '');
                    return output.trim();
                }
                else {
                    return '';
                }
            }
        }
    }
     */
    if(
        record !== undefined
        && record['fields_' + record.database_uuid] !== undefined
        && record['fields_' + record.database_uuid].length > 0
    ) {
        let fields = record['fields_' + record.database_uuid];
        for(let i = 0; i < fields.length; i++) {
            let the_field = fields[i][Object.keys(fields[i])[0]];
            if(the_field.field_uuid !== undefined && the_field.field_uuid == field_uuid) {
                if(the_field.files !== undefined && the_field.files[0].href !== undefined) {
                    return the_field.files[0].href;
                }
                if(the_field.value !== undefined) {
                    return the_field.value.toString().replace(/'/g, "\\'");
                }
                else if(the_field.tags !== undefined) {
                    let output = '';
                    for(let j = 0; j < the_field.tags.length; j++) {
                        output += the_field.tags[j].id + ' ';
                    }
                    output = output.replace(/,\s$/, '');
                    return output.trim();
                }
                else if(the_field.values !== undefined) {
                    let output = '';
                    for(let j = 0; j < the_field.values.length; j++) {
                        output += the_field.values[j].name + ', ';
                    }
                    output = output.replace(/,\s$/, '');
                    return output.trim();
                }
                else {
                    return '';
                }
            }
            else if(the_field.field_uuid !== undefined && the_field.field_uuid == field_uuid) {
                if(the_field.files !== undefined && the_field.files[0].href !== undefined) {
                    return the_field.files[0].href;
                }
                if(the_field.value !== undefined) {
                    return the_field.value.toString().replace(/'/g, "\\'");
                }
                else if(the_field.tags !== undefined) {
                    let output = '';
                    for(let j = 0; j < the_field.tags.length; j++) {
                        output += the_field.tags[j].id + ' ';
                    }
                    output = output.replace(/,\s$/, '');
                    return output.trim();
                }
                else if(the_field.values !== undefined) {
                    let output = '';
                    for(let j = 0; j < the_field.values.length; j++) {
                        output += the_field.values[j].name + ', ';
                    }
                    output = output.replace(/,\s$/, '');
                    return output.trim();
                }
                else {
                    return '';
                }
            }
        }
    }
    if(
        record !== undefined
        && record['records_' + record.template_uuid] !== undefined
        && record['records_' + record.template_uuid].length > 0
    ) {
        for(let i = 0; i < record['records_' + record.template_uuid].length; i++) {
            let result = await findValue(field_uuid, record['records_' + record.template_uuid][i]);
            if(result !== '') {
                return result;
            }
        }
    }
    if(
        record !== undefined
        && record['records_' + record.database_uuid] !== undefined
        && record['records_' + record.database_uuid].length > 0
    ) {
        for(let i = 0; i < record['records_' + record.database_uuid].length; i++) {
            let result = await findValue(field_uuid, record['records_' + record.database_uuid][i]);
            if(result !== '') {
                return result;
            }
        }
    }
    return '';
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



app();
