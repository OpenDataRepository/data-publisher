/*
 * https://github.com/akeyboardlife/puppeteer-save-svg/blob/master/main.js
 */

const puppeteer = require('puppeteer');
const fs = require('fs');

const bs = require('nodestalker');
const client = bs.Client('127.0.0.1:11300');
const tube = 'odr_references_record_builder';
const Memcached = require("memcached-promise");


let browser;
let memcached_client;
let token = '';

function delay(time) {
    return new Promise(function(resolve) {
        setTimeout(resolve, time);
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
    browser = await puppeteer.launch({headless:'new'});
    memcached_client = new Memcached('localhost:11211', {retries: 10, retry: 10000, remove: false});

    console.log('References Record Builder Start');
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
                    /*
                    {
                        'base_url' => $baseurl,
                        "ima_uuid":"0f59b751673686197f49f4e117e9",
                        "mineral_index": <1023>,
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

                    /*
                        path: ^/api/{version}/dataset/record/{record_uuid}
                     */
                    let record_url = record.base_url + '/api/v5/dataset/record/' + record.unique_id;
                    // console.log(record_url);

                    // Need to get token??
                    let record_data = await loadPage(record_url);
                    // console.log(record_data);

                    // throw Error('Break for debug.');


                    // Aarden H M, Gittins J (1974) Hiortdahlite from Kipawa River, Villedieu Township Temiscaming County, Québec, Canada. The Canadian Mineralogist 12, 241-247
                    // authors: 'af88b9e5d2680ac5bf6dc4fc34cd'
                    // article_title: 'dbf1b342812f12c1b750b036b201'
                    // journal: 'b1e0f7899c4fe89fe3813251c8e0'
                    // year: '889ce6e0f61474112e1fe8adf9da'
                    // volume: '5efacf0643a1e8fda456e9b87e10'
                    // pages: '98d09e2bcc2de65a4025a1eed271'
                    // reference_id: '72a950a4705a83547020834a1ce8'

                    let reference_id = await findValue(record.reference_record_map.reference_id, record_data);
                    let reference_record = {
                        'reference_id': await findValue(record.reference_record_map.reference_id, record_data),
                        'author': await findValue(record.reference_record_map.authors, record_data),
                        'year': await findValue(record.reference_record_map.year, record_data),
                        'article_title': await findValue(record.reference_record_map.article_title, record_data),
                        'journal': await findValue(record.reference_record_map.journal, record_data),
                        'pages': await findValue(record.reference_record_map.pages, record_data),
                        'volume': await findValue(record.reference_record_map.volume, record_data),
                        'record_uuid': record_data.record_uuid,
                        'internal_id': record_data.internal_id
                    };

                    /*
                    Buffer.from(
                        await findValue(record.ima_record_map.status_notes , record_data)
                    ).toString('base64') + '||' +
                     */

                    let content = 'references[' + reference_id + '] = ' + JSON.stringify(reference_record) + ';';
                    // console.log(content)
                    // console.log('writeFile: ' + record.base_path + record.references + '.' + record.file_extension);
                    await appendFile( record.base_path + record.references + '.' + record.file_extension, content);

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
                            'random_key': 'References_' + Math.floor(Math.random() * 99999999).toString(),
                            'job_order': 0,
                            'line_count': 1
                        }
                    };
                    worker_job = await apiCall(record.api_worker_job_url, worker_job, 'POST');
                    // console.log('Worker Job: ', worker_job);

                    client.deleteJob(job.id).onSuccess(function(del_msg) {
                        resJob();
                    });
                }
                catch (e) {
                    // TODO need to put job as unfinished - maybe not due to errors
                    console.log('Error occurred: ', e);
                    client.deleteJob(job.id).onSuccess(function(del_msg) {
                        // console.log('Deleted (' + Date.now() + '): ' , job);
                        console.log('Deleted (' + Date.now() + '): ' , job.id);
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

async function loadPage(page_url) {
    return await apiCall(page_url, '', 'GET');
}

async function findValue(field_uuid, record) {
    if(
        record['fields_' + record.template_uuid] !== undefined
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
                    // console.log('Getting file: ', the_field.files[0])
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
        }
    }
    if(
        record['fields_' + record.record_uuid] !== undefined
        && record['fields_' + record.record_uuid].length > 0
    ) {
        let fields = record['fields_' + record.record_uuid];
        for(let i = 0; i < fields.length; i++) {
            let the_field = fields[i][Object.keys(fields[i])[0]];
            if(the_field.template_field_uuid !== undefined && the_field.template_field_uuid == field_uuid) {
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
        }
    }
    if(
        record['records_' + record.template_uuid] !== undefined
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
        record['records_' + record.record_uuid] !== undefined
        && record['records_' + record.record_uuid].length > 0
    ) {
        for(let i = 0; i < record['records_' + record.record_uuid].length; i++) {
            let result = await findValue(field_uuid, record['records_' + record.record_uuid][i]);
            if(result !== '') {
                return result;
            }
        }
    }
    return '';
}


async function apiCall(api_url, post_data, method) {
    // console.log('API Call: ', api_url);
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

async function appendFile(file_name, content) {
    try {
        fs.appendFileSync(file_name, content);
        // file written successfully
    } catch (err) {
        console.log(err);
    }
}


app();
