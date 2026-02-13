/*
 * https://github.com/akeyboardlife/puppeteer-save-svg/blob/master/main.js
 */

const https = require('https');
const fs = require('fs');

const bs = require('nodestalker');
const client = bs.Client('127.0.0.1:11300');
const tube = 'odr_cell_params_record_builder';
const Memcached = require("memcached-promise");


let token = '';
let memcached_client;
let current_job_id = 0;

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

    console.log('Cell Params Record Builder Start');
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
                    let authors = '';
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
                        if(
                            ima_record !== undefined
                            && ima_record.record_uuid !== undefined
                            && await findValue(cp_map.a, record_data) !== ''
                    ) {

                            content += 'if(cellparams[\'' + ima_record.record_uuid + '\'] === undefined) { cellparams[\'' + ima_record.record_uuid + '\'] = {} };'
                            //content += 'if(cellparams[\'' + ima_record.record_uuid + '\'][\'' + record_data['record_uuid'] +'\'] === undefined) { cellparams[\'' +  ima_record.record_uuid + '\'][\'' + record_data['record_uuid'] +'\'] = new Array()};';
                            content += 'cellparams[\'' +
                                ima_record.record_uuid +
                                '\'][\'' + record_data['record_uuid'] +'\'] = "' +
                                // Source
                                'J|' +
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
                                await buildReference(cp_map, record_data, 'cell_params') + '|' +
                                // Cite Link 1
                                await getCitationLink(cp_map, record_data, 'literature') + '|' +
                                // Cite Link 2
                                await findValue(cp_map.cite_link2, record_data) + '|' +
                                // Status Notes Base64
                                Buffer.from(
                                    await findValue(cp_map.status_notes, record_data)
                                ).toString('base64') +
                            '";\n';
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

                        // "_record_metadata": {
                        // "_create_date": "2016-05-12 13:23:56",
                        // "_update_date": "2024-02-16 07:15:36",
                        // "_create_auth": "Tommy Yong",
                        // "_public_date": "2200-01-01 00:00:00"

                        /*
                          If we have any ima record here, it means a RRUFF
                          record exists.
                         */
                        if(
                            ima_record !== undefined
                            && ima_record.record_uuid !== undefined
                            && record_data._record_metadata !== undefined
                            && record_data._record_metadata._public_date !== undefined
                            && record_data._record_metadata._public_date !== "2200-01-01 00:00:00"
                        ) {
                            // Setting to true can create or overwrite this records status
                            content += 'rruff_record_exists[\'' + ima_record.record_uuid + '\'] = \'true\';';

                            // Files for building search interfaces
                            let mineral_ascii_name_uuid = 'a9d1d8a812ee000b8f477f07b775';
                            let minerals_with_rruff_content = '$rruff_mineral_names["' + ima_record.record_uuid + '"] = "' + sanitizeMineralName((await findValue(mineral_ascii_name_uuid, record_data)).toLowerCase()) + '";\n';
                            // Create mineral list for quick redirect
                            await appendFile(record.base_path + record.mineral_data + '_rruff.' + record.file_extension, minerals_with_rruff_content);
                        }
                        // TODO Add RRUFF ID to "rruff_record_exists" and
                        // ensure all RRUFF records get that value even if they don't
                        // have a PD alpha value.
                        if(
                            ima_record !== undefined
                            && ima_record.record_uuid !== undefined
                            && await findValue(pd_map.a, record_data) !== ''
                            && record_data._record_metadata._public_date !== undefined
                            && record_data._record_metadata._public_date !== "2200-01-01 00:00:00"
                        ) {
                            content += 'if(cellparams[\'' + ima_record.record_uuid + '\'] === undefined) { cellparams[\'' + ima_record.record_uuid + '\'] = {} };'
                            // content += 'if(cellparams[\'' + ima_record.record_uuid + '\'][\'' + record_data['record_uuid'] +'\'] === undefined) { cellparams[\'' + ima_record.record_uuid + '\'][\'' + record_data['record_uuid'] +'\'] = new Array()};';
                            content += 'cellparams[\'' +
                                ima_record.record_uuid +
                                // await findValue(pd_map.mineral_name, record_data) +
                                '\'][\'' + record_data['record_uuid'] +'\'] = "' +
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
                                '/' + await findValue(pd_map.rruff_id, record_data) + '|' +
                                // File/citation link 2
                                await findValue('', record_data) + '|' +
                                // Status Notes Base64
                                Buffer.from(
                                    await findValue(pd_map.rruff_locality, record_data)
                                ).toString('base64') +
                                '";\n';
                        }
                    }
                    /*
                      From AMCSD Records
                     */
                    else if(record.cell_params_type === 'amcsd') {
                        console.log('Processing AMCSD Record');
                        if(await findValue(amcsd_map.a, record_data) !== '') {
                            let amcsd_mineral_name = (await findValue(amcsd_map.mineral_name, record_data)).toLowerCase();

                            /*
                              If we have any ima record here, it means a RRUFF
                              record exists.
                             */
                            let ima_record = await findRecordByTemplateUUID(
                                record_data['records_' + amcsd_map.database_uuid],
                                cp_map.ima_template_uuid
                            );
                            if(
                                ima_record !== undefined
                                && ima_record.record_uuid !== undefined
                                && record_data._record_metadata !== undefined
                                && record_data._record_metadata._public_date !== undefined
                                && record_data._record_metadata._public_date !== "2200-01-01 00:00:00"
                            ) {
                                // Setting to true can create or overwrite this records status
                                content += 'amcsd_record_exists[\'' + ima_record.record_uuid + '\'] = \'true\';';


                                let mineral_ascii_name_uuid = 'a9d1d8a812ee000b8f477f07b775';
                                let minerals_with_amcsd_content = '$amcsd_mineral_names["' + ima_record.record_uuid + '"] = "' + sanitizeMineralName((await findValue(mineral_ascii_name_uuid, record_data)).toLowerCase()) + '";\n';
                                // Create mineral list for quick redirect
                                await appendFile(record.base_path + record.mineral_data + '_amcsd.' + record.file_extension, minerals_with_amcsd_content);
                            }

                            content += 'if(cellparams[\'' + amcsd_mineral_name + '\'] === undefined) { cellparams[\'' + amcsd_mineral_name + '\'] = {} };';
                            // content += 'if(cellparams[\'' + amcsd_mineral_name + '\'][\'' + record_data['record_uuid'] +'\'] === undefined) { cellparams[\'' + amcsd_mineral_name + '\'][\'' + record_data['record_uuid'] +'\'] = new Array()};';
                            content += 'cellparams[\'' +
                                amcsd_mineral_name +
                                '\'][\'' + record_data['record_uuid'] +'\'] = "' +
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
                                await buildReference(amcsd_map, record_data, 'amcsd') + '|' +
                                // File/citation link
                                await getCitationLink(amcsd_map, record_data, 'amcsd') + '|' +
                                // File/citation link 2
                                record_data['record_uuid'] + '|';
                                // Status Notes Base64
                            let status_notes = await findValue(amcsd_map.status_notes, record_data)
                            if(status_notes.length > 0) {
                                status_notes = 'Locality: ' + status_notes
                            }
                            content += Buffer.from(status_notes).toString('base64') + '";\n';


                            // Get the authors and append to authors file
                            let record_authors = await findValue(amcsd_map.amcsd_authors, record_data);
                            let author_array = [];
                            if(record_authors.length > 0) {
                                if(record_authors.match(/,/)) {
                                    author_array = record_authors.split(/,/);
                                    for(let i= 0; i < author_array.length; i++) {
                                        // In theory the "and " construct should only be present when'
                                        // multiple authors are found
                                        if(author_array[i].trim().match(/^and\s/)) {
                                            author_array[i] = author_array[i].trim().replace(/^and\s/,'');
                                        }
                                        author_array[i] = author_array[i].replace(/\\\\/, '\\');
                                        authors += 'array_push($author_names, \'' + author_array[i].trim() + '\');\n';
                                    }
                                }
                                else {
                                    author_array.push(record_authors.replace(/\\\\/, '\\'));
                                    authors += 'array_push($author_names, \'' + record_authors.trim() + '\');\n';
                                }
                            }
                        }
                    }

                    // console.log(content)
                    // console.log('writeFile: ' + record.base_path + record.cell_params + '.' + record.file_extension);
                    await appendFile( record.base_path + record.cell_params + '.' + record.file_extension, content);

                    // console.log('writeFile: ' + record.author_names_filename);
                    await appendFile( record.author_names_filename, authors);
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

/**
 * Restricts search to top-level linked/child records.  Ensures that
 * citation link comes from the "main" reference and not from a reference
 * tied to another child record.
 *
 * @param data_map
 * @param record
 * @returns {Promise<string|*|string>}
 */
async function getCitationLink(data_map, record, mode) {
    let reference_record = '';

    if(mode !== 'amcsd') {
        reference_record = await findRecordByTemplateUUID(record['records_' + data_map.template_uuid], data_map.reference_uuid);
    }
    // Hack for AMCSD References
    else if(mode === 'amcsd') {
        // console.log('TUUID: ' + data_map.template_uuid)
        // console.log('DBUUID: ' + data_map.database_uuid)
        // console.log('REFUUID: ' + data_map.reference_uuid)
        reference_record = await findRecordByTemplateUUID(record['records_' + data_map.database_uuid], data_map.reference_uuid);
        // reference_record = await findRecordByTemplateUUID(record['records_' + data_map.template_uuid], data_map.reference_uuid);
        // console.log('REF RECORD: ', reference_record)
        // reference_record = await findRecordByTemplateUUID(record['records_' + data_map.database_uuid], data_map.reference_uuid);
    }
    if(reference_record !== null) {
        // console.log('Reference Found');
        return await findValue(data_map.cite_link, reference_record, mode)
    }
    return '';
}

/**
 * Restricts search to top-level linked/child records.  Ensures that
 * reference record comes from directly linked child record or linked
 * record.  Prevents accidentally pulling reference from a child record
 * such as a IMA mineral record or similar.
 *
 * @param data_map
 * @param record
 * @returns {Promise<string>}
 */
async function buildReference(data_map, record, mode) {
    // Only look in the first level for actual reference records
    // record['records_' + data_map.template_uuid']
    // Get Appropriate Record
    // console.log('record[\'records_\'' + data_map.template_uuid)
    let reference_record = null;
    // Hack for AMCSD References ==> This is probably wrong....
    if(mode === 'amcsd') {
        reference_record = await findRecordByTemplateUUID(record['records_' + data_map.database_uuid], data_map.reference_uuid);
    }
    else {
        reference_record = await findRecordByTemplateUUID(record['records_' + data_map.template_uuid], data_map.reference_uuid);
    }
    // console.log('Target UUID: ', data_map.reference_uuid)
    if(reference_record !== null) {
        // console.log('Reference Found');
        let ref = await findValue(data_map.cite_text_journal, reference_record) + ' ';
        ref += await findValue(data_map.cite_text_volume, reference_record) + ' ';
        let cite_text_year =  await findValue(data_map.cite_text_year, reference_record);
        if(cite_text_year !== undefined && cite_text_year.length > 0) {
            ref += ' (' + cite_text_year + ') ';
        }
        ref += await findValue(data_map.cite_text_pages, reference_record);

        // console.log('REF: ' + ref);
        return Buffer.from(ref).toString('base64');
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

async function findValue(field_uuid, record, mode) {
    if(mode === undefined) mode = 'normal';
    // console.log('FIND VALUE: ' + field_uuid);
    if(field_uuid === '') return '';
    if(record === undefined) return '';
    // console.log('FIND VALUE 222: ', field_uuid);
    if(
        record.template_uuid !== undefined
        && record['fields_' + record.template_uuid] !== undefined
        && record['fields_' + record.template_uuid].length > 0
    ) {
        // console.log('jjjj')
        // console.log('Record Modified', record._record_metadata._update_date);
        let fields = record['fields_' + record.template_uuid];
        for(let i = 0; i < fields.length; i++) {
            let current_field = fields[i][Object.keys(fields[i])[0]];
            if(
                current_field.template_field_uuid !== undefined
                && current_field.template_field_uuid === field_uuid
            ) {
                // console.log('aaa', current_field.template_field_uuid)
                // console.log('aaa', current_field.id)
                // console.log('aaa', current_field.files)
                // Either have files
                if(
                    current_field.files !== undefined
                    && current_field.files[0] !== undefined
                    && current_field.files[0].href !== undefined
                ) {
                    // console.log('bbbaaaa')
                    // console.log('Getting file: ', current_field.files[0])
                    return current_field.files[0].href;
                }

                // Or values....
                if(current_field.value !== undefined) {
                    // console.log('bbbcccc')
                    return current_field.value.toString().replace(/'/g, "\\'");
                }
                else if(current_field.values !== undefined) {
                    // console.log('bbbdddd')
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
                    // // console.log('Getting file 2: ', current_field.files[0])
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
            // console.log('XXXXXXXXXXXXXXXXXXXXX: ' + record.database_uuid);
            // console.log(current_field.field_uuid + ' -- ' + field_uuid);
            // console.log(current_field.template_field_uuid + ' -- ' + field_uuid);
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
                    // console.log('333')
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
                    // console.log('777')
                    return output;
                }
                else {
                    // console.log('888')
                    return '';
                }
            }
        }
    }
    // console.log('*************************')
    if(
        record.template_uuid !== undefined
        && record['records_' + record.template_uuid] !== undefined
        && record['records_' + record.template_uuid].length > 0
    ) {
        // console.log('YYYYYYYYYYYy')
        for(let i = 0; i < record['records_' + record.template_uuid].length; i++) {
            // console.log("CCCCCCCCCCCCCCCCC")
            let result = await findValue(field_uuid, record['records_' + record.template_uuid][i]);
            if(result !== '') {
                return result;
            }
        }
    }
    // console.log('######################')
    if(
        record.record_uuid !== undefined
        && record['records_' + record.record_uuid] !== undefined
        && record['records_' + record.record_uuid].length > 0
    ) {
        // console.log('YYYYYYYYYYYy')
        for(let i = 0; i < record['records_' + record.record_uuid].length; i++) {
            // console.log("DDDDDDDDDDDDDDDDDDDD")
            let result = await findValue(field_uuid, record['records_' + record.record_uuid][i]);
            if(result !== '') {
                return result;
            }
        }
    }
    // console.log('&&&&&&&&&&&&&&&&&&&&&&&&&&')
    if(
        record.database_uuid !== undefined
        && record['records_' + record.database_uuid] !== undefined
        && record['records_' + record.database_uuid].length > 0
    ) {
        // console.log('YYYYYYYYYYYy')
        for(let i = 0; i < record['records_' + record.database_uuid].length; i++) {
            // console.log("DDDDDDDDDDDDDDDDDDDD")
            let result = await findValue(field_uuid, record['records_' + record.database_uuid][i]);
            if(result !== '') {
                return result;
            }
        }
    }
    // console.log('^^^^^^^^^^^^^^^^^^^^^^^^^^^^^')
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

function sanitizeMineralName(mineral_name) {
    mineral_name = mineral_name.replace('<sup>','');
    mineral_name = mineral_name.replace('<\\sup>','');
    mineral_name = mineral_name.replace('<sub>','');
    mineral_name = mineral_name.replace('<\\sub>','');
    mineral_name = mineral_name.replace('<i>','');
    mineral_name = mineral_name.replace('<\\i>','');
    return mineral_name
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


app();
