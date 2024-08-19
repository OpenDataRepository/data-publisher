/*
 * https://github.com/akeyboardlife/puppeteer-save-svg/blob/master/main.js
 */

const puppeteer = require('puppeteer');
const fs = require('fs');

const bs = require('nodestalker');
const client = bs.Client('127.0.0.1:11300');
const tube = 'odr_cell_params_record_builder';
let browser;
let token = '';
let current_job_id = 0;

function delay(time) {
    return new Promise(function(resolve) {
        setTimeout(resolve, time)
    });
}

async function app() {
    browser = await puppeteer.launch({headless:'new'});
    console.log('Cell Params Record Builder Start');
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
                        "ima_url":"\\/\\/www.rruff.net\\/odr_rruff",
                        "cell_params_url":"\\/\\/www.rruff.net\\/odr_rruff"
                     }
                     */

                    /*
                        path: ^/api/{version}/dataset/record/{record_uuid}
                     */
                    let record_url = record.base_url + '/api/v5/dataset/record/' + record.unique_id;
                    // console.log(record_url);

                    // Need to get token??
                    let record_data = await loadPage(record_url);
                    // console.log('CP record: ', record_data);

                    current_job_id = record_data.tracked_job_id;
                    // var cellparams=new Array();
                    // cellparams[0]="J|
                    // 4517|
                    // Abellaite|  Mineral Name
                    // NaPb_2_(CO_3_)_2_(OH)| IMA Chemistry
                    // | Measured Chemistry
                    // 5.260(2)|
                    // 5.260(2)|
                    // 13.463(5)|
                    // 90|
                    // 90|
                    // 120|
                    // 322.585|
                    // |
                    // |
                    // 2|
                    // 3mm|
                    // P31c|
                    // P|
                    // TWluZXJhbG9naWNhbCBNYWdhemluZSA4MCAoMjAxNikgMTk5LTIwNQ==|
                    // https://rruff.info/rruff_1.0/uploads/MM80_199.pdf|
                    // |
                    // VHlwZSBzYW1wbGUgZnJvbSBFdXJla2EgbWluZSwgQ2F0YWxvbmlhLCBTcGFpbg==";

                    let cp_map = record.cell_params_map;
                    let pd_map = record.powder_diffraction_map;
                    let amcsd_map = record.amcsd_record_map;
                    // console.log("AMCSD RECORD MAP")
                    // console.log(amcsd_map);
                    let content = '';
                    /*
                      From the Cell Parameters Database
                     */
                    if(record.cell_params_type === 'cell_params') {
                        console.log('Processing Cell Params Record');
                        // The array key is the IMA Mineral UUID
                        let ima_record = await findRecordByTemplateUUID(
                            record_data['records_' + cp_map.template_uuid],
                            cp_map.ima_template_uuid
                        );
                        if(ima_record !== undefined && ima_record.record_uuid !== undefined) {
                            content += 'cellparams[\'' +
                                ima_record.record_uuid +
                                '\'].push("' +
                                // Source
                                'CP|' +
                                // Cell Parameter ID
                                await findValue(cp_map.cell_parameter_id, record_data) + '|' +
                                // Mineral Name
                                await findValue(cp_map.mineral_name, record_data) + '|' +
                                // TODO Where does the chemistry come from
                                await findValue(cp_map.ima_chemistry, record_data) + '|' +
                                // TODO Chemistry 2
                                await findValue(cp_map.measured_chemistry, record_data) + '|' +
                                // a
                                await findValue(cp_map.a, record_data) + '|' +
                                // b
                                await findValue(cp_map.b, record_data) + '|' +
                                // c
                                await findValue(cp_map.c , record_data) + '|' +
                                // alpha
                                await findValue(cp_map.alpha, record_data) + '|' +
                                // beta
                                await findValue(cp_map.beta, record_data) + '|' +
                                // gamma
                                await findValue(cp_map.gamma, record_data) + '|' +
                                // Volume
                                await findValue(cp_map.volume, record_data) + '|' +
                                // Pressure
                                await findValue(cp_map.pressure, record_data) + '|' +
                                // Temperature
                                await findValue(cp_map.temperature, record_data) + '|' +
                                // Crystal System
                                await convertCrystalSystem(await findValue(cp_map.crystal_system, record_data)) + '|' +
                                // Point Group
                                await findValue(cp_map.point_group, record_data) + '|' +
                                // Space Group
                                await findValue(cp_map.space_group, record_data) + '|' +
                                // Lattice
                                await findValue(cp_map.lattice, record_data) + '|' +
                                // Cite_Text Reference
                                Buffer.from(
                                    await buildReference(cp_map, record_data)
                                ).toString('base64') + '|' +
                                // Cite Link 1
                                await findValue(cp_map.cite_link , record_data) + '|' +
                                // Cite Link 2
                                await findValue(cp_map.cite_link2, record_data) + '|' +
                                // Status Notes Base64
                                Buffer.from(
                                    await findValue(cp_map.status_notes, record_data)
                                ).toString('base64') +
                            '");\n';
                        }
                    }
                    /*
                      From RRUFF Powder Diffraction Records
                     */
                    else if(record.cell_params_type === 'powder_diffraction') {
                        // TODO We could have multiple PD Children on a single record
                        console.log('Processing Powder Diffraction Record');
                        let ima_record = await findRecordByTemplateUUID(
                            record_data['records_' + pd_map.template_uuid],
                            cp_map.ima_template_uuid
                        );
                        content += 'rruff_record_exists[\'' + ima_record.record_uuid + '\'] = \'true\';';
                        if(ima_record !== undefined && ima_record.record_uuid !== undefined) {
                            content += 'cellparams[\'' +
                                ima_record.record_uuid +
                                // await findValue(pd_map.mineral_name, record_data) +
                                '\'].push("' +
                                // Source
                                'R|' +
                                // Cell Parameter ID
                                '|' +
                                // Mineral Name
                                await findValue(pd_map.mineral_name, record_data) + '|' +
                                // TODO Where does the chemistry come from
                                await findValue(pd_map.ima_chemistry, record_data) + '|' +
                                // TODO Chemistry 2
                                await findValue(pd_map.measured_chemistry, record_data) + '|' +
                                // a
                                await findValue(pd_map.a, record_data) + '|' +
                                // b
                                await findValue(pd_map.b, record_data) + '|' +
                                // c
                                await findValue(pd_map.c, record_data) + '|' +
                                // alpha
                                await findValue(pd_map.alpha, record_data) + '|' +
                                // beta
                                await findValue(pd_map.beta, record_data) + '|' +
                                // gamma
                                await findValue(pd_map.gamma, record_data) + '|' +
                                // Volume
                                await findValue(pd_map.volume, record_data) + '|' +
                                // pressure
                                await findValue(pd_map.pressure, record_data) + '|' +
                                // Temperature
                                await findValue(pd_map.temperature, record_data) + '|' +
                                // Crystal System
                                await convertCrystalSystem(await findValue(pd_map.crystal_system, record_data)) + '|' +
                                // Point Group
                                await findValue(pd_map.point_group, record_data) + '|' +
                                // Space Group
                                await findValue(pd_map.space_group, record_data) + '|' +
                                // TODO Lattice?
                                await findValue(pd_map.lattice, record_data) + '|' +
                                // RRUFF Reference
                                Buffer.from(
                                    await findValue(pd_map.rruff_id, record_data)
                                ).toString('base64') + '|' +
                                // File/citation link
                                'https://www.rruff.net/' + await findValue(pd_map.rruff_id, record_data) + '|' +
                                // File/citation link 2
                                await findValue('', record_data) + '|' +
                                // Status Notes Base64
                                Buffer.from(
                                    'Locality: ' + await findValue(pd_map.status_notes, record_data)
                                ).toString('base64') +
                                '");\n';
                        }
                    }
                    /*
                      From AMCSD Records
                     */
                    else if(record.cell_params_type === 'amcsd') {
                        console.log('Processing AMCSD Record');
                        if(1) {
                            let amcsd_mineral_name = (await findValue(amcsd_map.mineral_name, record_data)).toLowerCase();

                            content += 'if(cellparams[\'' + amcsd_mineral_name + '\'] === undefined) { cellparams[\'' + amcsd_mineral_name + '\'] = new Array()};'
                            content += 'cellparams[\'' +
                                (await findValue(amcsd_map.mineral_name, record_data)).toLowerCase() +
                                '\'].push("' +
                                // Source
                                'A|' +
                                // Cell Parameter ID
                                '|' +
                                // Mineral Name
                                await findValue(amcsd_map.mineral_name, record_data) + '|' +
                                // TODO Where does the chemistry come from
                                await findValue(amcsd_map.ima_chemistry, record_data) + '|' +
                                // TODO Chemistry 2
                                await findValue(amcsd_map.measured_chemistry, record_data) + '|' +
                                // a
                                await findValue(amcsd_map.a, record_data) + '|' +
                                // b
                                await findValue(amcsd_map.b, record_data) + '|' +
                                // c
                                await findValue(amcsd_map.c , record_data) + '|' +
                                // alpha
                                await findValue(amcsd_map.alpha, record_data) + '|' +
                                // beta
                                await findValue(amcsd_map.beta, record_data) + '|' +
                                // gamma
                                await findValue(amcsd_map.gamma, record_data) + '|' +
                                // Volume
                                await findValue(amcsd_map.volume, record_data) + '|' +
                                // pressure
                                await findValue(amcsd_map.pressure, record_data) + '|' +
                                // Temperature
                                await findValue(amcsd_map.temperature, record_data) + '|' +
                                // Crystal System
                                await convertCrystalSystem(await findValue(amcsd_map.crystal_system, record_data)) + '|' +
                                // Point Group
                                await findValue(amcsd_map.point_group, record_data) + '|' +
                                // Space Group
                                await findValue(amcsd_map.space_group, record_data) + '|' +
                                // TODO Lattice?
                                await findValue(amcsd_map.lattice, record_data) + '|' +
                                // RRUFF Reference
                                Buffer.from(
                                    await buildReference(amcsd_map, record_data)
                                ).toString('base64')  + '|' +
                                // File/citation link
                                'https://www.rruff.net/' + await findValue(amcsd_map.cite_link, record_data) + '|' +
                                // File/citation link 2
                                await findValue('' , record_data) + '|' +
                                // Status Notes Base64
                                Buffer.from(
                                    'Locality: ' + await findValue(amcsd_map.status_notes, record_data)
                                ).toString('base64') +
                            '");\n';
                        }
                    }

                    // console.log(content)
                    // console.log('writeFile: ' + record.base_path + record.cell_params + '.' + record.file_extension);
                    await appendFile( record.base_path + record.cell_params + '.' + record.file_extension, content);

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
                            'random_key': 'CellParams_' + Math.floor(Math.random() * 99999999).toString(),
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
                    // console.log('Error occurred: ', e);
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

async function convertCrystalSystem(cp_value) {
    let crystal_systems_map = {
        'cubic': 1,
        'hexagonal': 2,
        'tetragonal': 3,
        'orthorhombic': 4,
        'monoclinic': 5,
        'triclinic': 6,
        'amorphous': 7,
        'unknown': 8,
        'rhombohedral': 9,
        'isometric': 10
    };
    for(const key in crystal_systems_map) {
        if(cp_value.toString().toLowerCase() === key) {
            return crystal_systems_map[key];
        }
    }
    return 0;
}

async function writeFile(file_name, content) {
    try {
        fs.writeFileSync(file_name, content);
        // file written successfully
    } catch (err) {
        console.log(err);
    }
}

async function buildReference(data_map, record) {
    // Only look in the first level for actual reference records
    // record['records_' + data_map.template_uuid']
    // Get Appropriate Record
    // console.log('record[\'records_\'' + data_map.template_uuid)
    let reference_record = await findRecordByTemplateUUID(record['records_' + data_map.template_uuid], data_map.reference_uuid);
    // Hack for AMCSD References
    if(reference_record === undefined) {
        reference_record = await findRecordByTemplateUUID(record['records_' + data_map.database_uuid], data_map.reference_uuid);
    }
    // console.log('Target UUID: ', data_map.reference_uuid)
    if(reference_record !== null) {
        let ref = await findValue(data_map.cite_text_journal, reference_record) + ' ' +
            await findValue(data_map.cite_text_volume, reference_record) + ' (' +
            await findValue(data_map.cite_text_year, reference_record) + ') ' +
            await findValue(data_map.cite_text_pages, reference_record);
        return ref;
    }
    return '';
}

async function findRecordByTemplateUUID (records, target_uuid){
    if(records === undefined) {
        return undefined;
    }
    for(let i = 0; i < records.length; i++) {
        // console.log('Record UUID: ' + records[i].template_uuid + ' ' + target_uuid)
        if(records[i].template_uuid === target_uuid) {
            // console.log(records[i].record_uuid)
            return records[i];
        }
    }
    return undefined
}
async function findRecordByDatabaseUUID (records, target_uuid){
    if(records === undefined) {
        return undefined;
    }
    for(let i = 0; i < records.length; i++) {
        // console.log('Record UUID: ' + records[i].template_uuid + ' ' + target_uuid)
        if(records[i].database_uuid === target_uuid) {
            // console.log(records[i].record_uuid)
            return records[i];
        }
    }
    return undefined
}

async function findValue(field_uuid, record) {
    // console.log('FIND VALUE: ' + field_uuid);
    if(field_uuid === '') return '';
    if(record === undefined) return '';
    // console.log('FIND VALUE 222');
    if(
        record.template_uuid !== undefined
        && record['fields_' + record.template_uuid] !== undefined
        && record['fields_' + record.template_uuid].length > 0
    ) {
        // console.log('jjjj')
        let fields = record['fields_' + record.template_uuid];
        for(let i = 0; i < fields.length; i++) {
            let current_field = fields[i][Object.keys(fields[i])[0]];
            if(
                current_field.template_field_uuid !== undefined
                && current_field.template_field_uuid === field_uuid
            ) {
                // console.log('aaa')
                if(
                    current_field.files !== undefined
                    && current_field.files[0] !== undefined
                    && current_field.files[0].href !== undefined
                ) {
                    // console.log('Getting file: ', current_field.files[0])
                    return current_field.files[0].href;
                }
                if(current_field.value !== undefined) {
                    // console.log('bbb')
                    return current_field.value.toString().replace(/'/g, "\\'");
                }
                else if(current_field.values !== undefined) {
                    let output = '';
                    for(let j = 0; j < current_field.values.length; j++) {
                        output += current_field.values[j].name + ', ';
                    }
                    output = output.replace(/,\s$/, '');
                    // console.log('ccc')
                    return output;
                }
                else {
                    // console.log('ddd')
                    return '';
                }
            }
            else if(current_field.field_uuid !== undefined && current_field.field_uuid === field_uuid) {
                // console.log('eee')
                if(
                    current_field.files !== undefined
                    && current_field.files[0] !== undefined
                    && current_field.files[0].href !== undefined
                ) {
                    // // // console.log('Getting file 2: ', current_field.files[0])
                    // console.log('fff')
                    return current_field.files[0].href;
                }
                if(current_field.value !== undefined) {
                    // console.log('ggg')
                    return current_field.value.toString().replace(/'/g, "\\'");
                }
                else if(current_field.values !== undefined) {
                    let output = '';
                    for(let j = 0; j < current_field.values.length; j++) {
                        output += current_field.values[j].name + ', ';
                    }
                    output = output.replace(/,\s$/, '');
                    // console.log('hhh')
                    return output;
                }
                else {
                    // console.log('iii')
                    return '';
                }
            }
        }
    }
    // console.log('ahdfjdjhfj')
    if(
        record.database_uuid !== undefined
        && record['fields_' + record.database_uuid] !== undefined
        && record['fields_' + record.database_uuid].length > 0
    ) {
        // console.log("BBBBBBBBBBBBBBBBBBBBBBB")
        let fields = record['fields_' + record.database_uuid];
        for(let i = 0; i < fields.length; i++) {
            let current_field = fields[i][Object.keys(fields[i])[0]];
            // console.log('XXXXXXXXXXXXXXXXXXXXX')
            // console.log(current_field.field_uuid + ' -- ' + field_uuid);
            if(current_field.template_field_uuid !== undefined && current_field.template_field_uuid === field_uuid) {
                if(current_field.files !== undefined && current_field.files[0].href !== undefined) {
                    // console.log('111')
                    return current_field.files[0].href;
                }
                if(current_field.value !== undefined) {
                    // console.log('222')
                    return current_field.value.toString().replace(/'/g, "\\'");
                }
                else if(current_field.values !== undefined) {
                    // console.log('333')
                    let output = '';
                    for(let j = 0; j < current_field.values.length; j++) {
                        output += current_field.values[j].name + ', ';
                    }
                    output = output.replace(/,\s$/, '');
                    // // console.log('333')
                    return output;
                }
                else {
                    // console.log('444')
                    return '';
                }
            }
            else if(current_field.field_uuid !== undefined && current_field.field_uuid === field_uuid) {
                // console.log('AJSJSJSJJSSJJS')
                if(current_field.files !== undefined && current_field.files[0].href !== undefined) {
                    // console.log('555')
                    return current_field.files[0].href;
                }
                if(current_field.value !== undefined) {
                    // console.log('666')
                    return current_field.value.toString().replace(/'/g, "\\'");
                }
                else if(current_field.values !== undefined) {
                    // console.log('777')
                    let output = '';
                    for(let j = 0; j < current_field.values.length; j++) {
                        output += current_field.values[j].name + ', ';
                    }
                    output = output.replace(/,\s$/, '');
                    // // console.log('777')
                    return output;
                }
                else {
                    // console.log('888')
                    return '';
                }
            }
        }
    }
    if(
        record.template_uuid !== undefined
        && record['records_' + record.template_uuid] !== undefined
        && record['records_' + record.template_uuid].length > 0
    ) {
        for(let i = 0; i < record['records_' + record.template_uuid].length; i++) {
            // console.log("CCCCCCCCCCCCCCCCC")
            let result = await findValue(field_uuid, record['records_' + record.template_uuid][i]);
            if(result !== '') {
                return result;
            }
        }
    }
    if(
        record.record_uuid !== undefined
        && record['records_' + record.record_uuid] !== undefined
        && record['records_' + record.record_uuid].length > 0
    ) {
        for(let i = 0; i < record['records_' + record.record_uuid].length; i++) {
            // console.log("DDDDDDDDDDDDDDDDDDDD")
            let result = await findValue(field_uuid, record['records_' + record.record_uuid][i]);
            if(result !== '') {
                return result;
            }
        }
    }
    return '';
}

async function appendFile(file_name, content) {
    try {
        fs.appendFileSync(file_name, content);
        // file written successfully
    } catch (err) {
        console.log(err);
    }
}

function formatChemistry(formula) {

    // $sub = preg_quote($sub);
    let sub = '_';
    let str = formula.replaceAll(new RegExp(sub + '([^' + sub + ']+)' + sub, 'g'), '<sub>$1</sub>');

    // Apply the superscripts...
    let sup = '^';
    str = str.replaceAll(new RegExp(sup + '([^' + sup + ']+)' + sup, 'g'), '<sup>$1</sup>');

    // Escape single quotes
    str = str.replace(/'/g, "\\'");

    return str.replace(/\[box\]/g, '<span style="border: 1px solid #333; font-size:7px;">&nbsp;&nbsp;&nbsp;</span>');

}


async function loadPage(page_url) {
    return await apiCall(page_url, '', 'GET');
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


app();
