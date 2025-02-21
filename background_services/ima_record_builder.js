/*
 * https://github.com/akeyboardlife/puppeteer-save-svg/blob/master/main.js
 */

const puppeteer = require('puppeteer');
const fs = require('fs');

const bs = require('nodestalker');
const client = bs.Client('127.0.0.1:11300');
const tube = 'odr_ima_record_builder';
let browser;
let token = '';

function delay(time) {
    return new Promise(function(resolve) {
        setTimeout(resolve, time)
    });
}

async function app() {
    browser = await puppeteer.launch({headless:'new'});
    console.log('IMA Record Builder Start');
    client.watch(tube).onSuccess(function(data) {
        function resJob() {
            client.reserve().onSuccess(async function(job) {
                // console.log('Reserved (' + Date.now() + '): ' , job);

                try {
                    let record = JSON.parse(job.data);

                    console.log('Starting job: ' + job.id);

                    // Login/get token
                    // console.log('API URL: ', record.api_login_url);
                    let post_data = {
                        'username': record.api_user,
                        'password': record.api_key
                    };
                    let login_token = await apiCall(record.api_login_url, post_data, 'POST');
                    token = login_token.token;
                    // console.log('Login Token: ', login_token.token);

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
                    // let record_url = record.base_url + '/odr/api/v5/dataset/record/' + record.unique_id;
                    let record_url = record.base_url + '/api/v5/dataset/record/' + record.unique_id;
                    // console.log(record_url);

                    // Need to get token??
                    let record_data = await loadPage(record_url);
                    // console.log(record_data);

                    /*
                    'Abellaite
                    ||Abellaite
                    ||NaPb<sup>2+</sup><sub>2</sub>(CO<sub>3</sub>)<sub>2</sub>(OH)
                    ||NaPb<sub>2</sub>(CO<sub>3</sub>)<sub>2</sub>(OH)
                    ||
                    ||
                    ||1
                    ||Na Pb C O H
                    ||785 765 786 90 236 173 766 813 895 893 1132 1135 1000001 1000008
                    ||18519 18520 19655 19668 19924
                    ||
                    ||
                    ||NaPb^2+^_2_(CO_3_)_2_(OH)
                    ||NaPb_2_(CO_3_)_2_(OH)
                    ||
                    ||
                    ||6482
                    ||SWLDocOxZXotSW5zYSBKLCBFbHZpcmEgSiBKLCBMbG92ZXQgWCwgUMOpcmV6LUNhbm8gSiwgT3Jpb2xzIE4sIEJ1c3F1ZXRzLU1hc8OzIE0sIEhlcm7DoW5kZXogUyAoMjAxNykgQWJlbGxhaXRlLCBOYVBiXzJfKENPXzNfKV8yXyhPSCksIGEgbmV3IHN1cGVyZ2VuZSBtaW5lcmFsIGZyb20gdGhlIEV1cmVrYSBtaW5lLCBMbGVpZGEgcHJvdmluY2UsIENhdGFsb25pYSwgU3BhaW4uIEV1cm9wZWFuIEpvdXJuYWwgb2YgTWluZXJhbG9neSAyOSwgOTE1LTkyMg==:::
                    ||Na Pb C O H
                    ||IMA2014-111
                    ||Abellaite
                    ||Spain
                    ||2014
                    ||Na Pb^2+ C O H
                    ||Abe
                    ||Abe
                    ||HOM File';

                     */

                    // minerals_by_name[0]={
                    // name:"Abellaite",
                    // id:"6482"
                    // };
                    // mineral_keys['6482']=
                    // 'Abellaite';
                    // mineral_data_array['6482']=
                    // 'Abellaite||
                    // Abellaite||
                    // NaPb<sup>2+</sup><sub>2</sub>(CO<sub>3</sub>)<sub>2</sub>(OH)
                    // ||NaPb<sub>2</sub>(CO<sub>3</sub>)<sub>2</sub>(OH)
                    // || // Has HOM
                    // ||
                    // ||1 // Has AMCSD
                    // ||Na Pb C O H
                    // TAG Data: ||785 765 786 90 236 173 766 813 895 893 1132 1135 1000001 1000008 Tags???
                    // References? ||18519 18520 19655 19668 19924
                    // || has RRUFF Record
                    // ||
                    // ||NaPb^2+^_2_(CO_3_)_2_(OH)
                    // ||NaPb_2_(CO_3_)_2_(OH)
                    // ||
                    // ||
                    // ||6482
                    // ||SWLDocOxZXotSW5zYSBKLCBFbHZpcmEgSiBKLCBMbG92ZXQgWCwgUMOpcmV6LUNhbm8gSiwgT3Jpb2xzIE4sIEJ1c3F1ZXRzLU1hc8OzIE0sIEhlcm7DoW5kZXogUyAoMjAxNykgQWJlbGxhaXRlLCBOYVBiXzJfKENPXzNfKV8yXyhPSCksIGEgbmV3IHN1cGVyZ2VuZSBtaW5lcmFsIGZyb20gdGhlIEV1cmVrYSBtaW5lLCBMbGVpZGEgcHJvdmluY2UsIENhdGFsb25pYSwgU3BhaW4uIEV1cm9wZWFuIEpvdXJuYWwgb2YgTWluZXJhbG9neSAyOSwgOTE1LTkyMg==:::
                    // ||Na Pb C O H
                    // ||IMA2014-111
                    // ||Abellaite
                    // ||Spain
                    // ||2014
                    // ||Na Pb^2+ C O H
                    // ||Abe
                    // ||Abe';
                    let content = '' +
                        'minerals_by_name[' + record.mineral_index + ']={name:"' +
                            // Mineral Name
                            await findValue(record.cell_params_map.mineral_name , record_data) +
                            '",id:"' +
                            // Mineral ID
                             record_data.record_uuid +
                        '"};';

                    content += '' +
                        'mineral_keys[' +
                            // Mineral ID
                            '\'' + record_data.record_uuid + '\'' +
                        ']=\'' +
                            // Mineral Name
                            await findValue(record.cell_params_map.mineral_name , record_data) +
                        '\';';

                    content += '' +
                        'mineral_name_keys[\'' +
                            // await findValue('5b8394b6683f3714786a2dbde9b4' , record_data) +
                            // Mineral Name
                            await findValue(record.cell_params_map.mineral_name , record_data) +
                        '\']=\'' +
                            // Mineral ID
                            record_data.record_uuid +
                        '\';';

                    content += '' +
                        'mineral_data_array[' +
                            // Mineral ID
                            '\'' + record_data.record_uuid + '\'' +
                        ']=\'' +
                        // Mineral Name -- 0
                        await findValue(record.cell_params_map.mineral_name , record_data) + '||' +
                        // Mineral Display Name -- 1
                        await findValue(record.cell_params_map.mineral_name , record_data) + '||' +
                        // Ideal IMA Formula (html) -- 2
                        formatChemistry(await findValue(record.ima_record_map.rruff_formula , record_data)) + '||' +
                        // RRUFF Formula (html) -- 3
                        formatChemistry(await findValue(record.cell_params_map.ima_chemistry , record_data)) + '||' +
                        // Has HOM -- 4
                        await findValue(record.ima_record_map.hom_file, record_data) + '||' +
                        // await findValue('' , record_data) + '||' +
                        // <empty> -- 5
                        await findValue('' , record_data) + '||' +
                        // Has AMCSD -- 6
                        await findValue('' , record_data) + '||' +
                        // Chemistry Elements -- 7
                        await findValue(record.cell_params_map.chemistry_elements , record_data) + '||' +
                        // Tag Data -- 8
                        await findValue(record.ima_record_map.tag_data , record_data) + '||' +
                        // References -- 9
                        await findReferences(record, record_data) + '||' +
                        // IMA Number -- 10
                        await findValue('' , record_data) + '||' +
                        // RRUFF IDs -- 11
                        await findValue('' , record_data) + '||' +
                        // Ideal IMA Formula (raw) -- 12
                        await findValue(record.cell_params_map.ima_chemistry , record_data) + '||' +
                        // RRUFF Formula (raw) -- 13
                        await findValue(record.ima_record_map.rruff_formula , record_data) + '||' +
                        // -- 14
                        await findValue('' , record_data) + '||' +
                        // -- 15
                        await findValue('' , record_data) + '||' +
                        // Mineral ID -- 16
                        await findValue(record.ima_record_map.mineral_id , record_data) + '||' +
                        // Status Notes Base64 -- 17
                        // TODO Make this build status notes array
                        Buffer.from(
                            await buildStatusNotes(record.ima_record_map, record_data)
                        ).toString('base64') + '||' +
                        // Chemistry Elements -- 18
                        await findValue('' , record_data) + '||' +
                        // IMA Number -- 19
                        await findValue(record.ima_record_map.ima_number , record_data) + '||' +
                        // Mineral Name -- 20
                        await findValue(record.cell_params_map.mineral_name , record_data) + '||' +
                        // Type Locality Country -- 21
                        await findValue(record.ima_record_map.type_locality_country , record_data) + '||' +
                        // Year First Published -- 22
                        await findValue(record.ima_record_map.year_first_published , record_data) + '||' +
                        // Valence Elements -- 23
                        await findValue(record.ima_record_map.valence_elements , record_data) + '||' +
                        // Mineral Display Abbreviation -- 24
                        await findValue(record.ima_record_map.mineral_display_abbreviation , record_data) + '||' +
                        // Mineral UUID -- 25
                        record_data.record_uuid +
                        '\';\n';

                    // console.log(content)
                    // console.log('writeFile: ' + record.base_path + record.mineral_data + '.' + record.file_extension);
                    await appendFile( record.base_path + record.mineral_data + '.' + record.file_extension, content);


                    content = '$mineral_names[] = "' + await findValue(record.cell_params_map.mineral_name , record_data) + '";\n';
                    content += '$mineral_names_lowercase[] = "' + (await findValue(record.cell_params_map.mineral_name, record_data)).toLowerCase() + '";\n';
                    // Create mineral list for quick redirect
                    await appendFile( record.base_path + record.mineral_data + '_include.' + record.file_extension, content);

                    /*
                    {
                        internal_id: 25599,
                        unique_id: 'a5dddfa3632906b0976c9d43ac84',
                        external_id: 6482,
                        record_name: 'Abellaite'
                     }
                    */
                        // Push job into queue for completion
                        // Creat random key for temp file
                        // Count records for job


                    // Get List of IMA Records

                    // Post Record UUID, new ID, and total # to
                    // Builder queue
                    // Builder queue also builds cell param data
                    // If record id == max id - overwrite old file

                    // console.log('Updating Job Count:', data.api_worker_job_url);
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
                            'random_key': 'IMARecord_' + Math.floor(Math.random() * 99999999).toString(),
                            'job_order': 0,
                            'line_count': 1
                        }
                    };
                    worker_job = await apiCall(record.api_worker_job_url, worker_job, 'POST');
                    // console.log('Worker Job: ', worker_job);

                    client.deleteJob(job.id).onSuccess(function(del_msg) {
                        // console.log('Deleted (' + Date.now() + '): ' , job);
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

/**
 * Build status notes by finding Status Notes Child Record
 * and parsing for appropriate values
 */
async function buildStatusNotes(ima_record_map, record) {
    try {
        // console.log('Checking for status notes v2');
        let status_notes = '';
       // Parse child records to see if a status notes record is found
        // Only checking by template UUID - may
        if(
            record !== undefined
            && record['records_' + record.template_uuid] !== undefined
            && record['records_' + record.template_uuid].length > 0
        ) {
            let counter = 0;
            for(let i = 0; i < record['records_' + record.template_uuid].length; i++) {
                // If we have a status notes record, start looking for fields
                // display order, status_notes_field, reference...
                // records_block = records_[this_status_notes_dt]

                let child_record = record['records_' + record.template_uuid][i];
                if(child_record.template_uuid === ima_record_map.status_notes_dt_uuid) {
                    // console.log('Status notes record found')
                    if(counter > 0) {
                        // Status Notes Array Divider
                        status_notes += '^*^';
                    }
                    // This should be a status notes record
                    status_notes += await findValue(ima_record_map.status_notes_display_order, child_record);
                    status_notes += '~~';
                    status_notes += await findValue(ima_record_map.status_notes_field, child_record);
                    status_notes += '~~';
                    // Find child reference id
                    if(
                        child_record['records_' + child_record.template_uuid] !== undefined
                        && child_record['records_' + child_record.template_uuid].length > 0
                    ) {
                        // console.log('Checking for status notes reference record');
                        for (let j = 0; j < child_record['records_' + child_record.template_uuid].length; j++) {
                            // console.log('Status notes reference record found');
                            let reference_record = child_record['records_' + child_record.template_uuid][j];
                            if(reference_record.template_uuid === ima_record_map.status_notes_reference_uuid) {
                                status_notes += await findValue(ima_record_map.status_notes_reference_id, reference_record);
                            }
                        }
                    }
                    counter++;
                }
            }
        }
        // console.log('Status NOTE: ' + status_notes);
        return status_notes;
    }
    catch (e) {
        console.log('Status NOTE ERROR: ' + e);
        return ''
    }
}

async function findValue(field_uuid, record) {
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
    if(
        record !== undefined
        && record['fields_' + record.record_uuid] !== undefined
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
        && record['records_' + record.record_uuid] !== undefined
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

async function findReferences(record, record_data) {
    // console.log('Find Reference: ')
    let references_list = '';
    for(let i = 0; i < record_data['records_' + record.ima_record_map.ima_template_uuid].length; i++) {
        let record_obj = record_data['records_' + record.ima_record_map.ima_template_uuid][i];
        // console.log('Record: ', record_obj);
        record_obj = {
           ...record_obj,
           "template_uuid": record.ima_record_map.reference_template_uuid
        };
        references_list += await findValue(record.ima_record_map.reference_id , record_obj) + ' ';
    }
    return references_list.trim();
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



app();
