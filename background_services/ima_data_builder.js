/* jshint esversion: 8 */

const https = require('https');
const fs = require('fs');

const bs = require('nodestalker');
const client = bs.Client('127.0.0.1:11300');
const tube = 'odr_ima_data_builder';

const record_client = bs.Client('127.0.0.1:11300');
const record_tube = 'odr_ima_record_builder';
const cell_params_record_client = bs.Client('127.0.0.1:11300');
const cell_params_record_tube = 'odr_cell_params_record_builder';
const references_record_client = bs.Client('127.0.0.1:11300');
const references_record_tube = 'odr_references_record_builder';
const ima_data_finisher_client = bs.Client('127.0.0.1:11300');
const ima_data_finisher_tube = 'odr_ima_data_finisher';
const paragenetic_modes_record_client = bs.Client('127.0.0.1:11300');
const paragenetic_modes_record_tube = 'odr_paragenetic_modes_record_builder';

let token = '';
function delay(time) {
    return new Promise(function(resolve) {
        setTimeout(resolve, time);
    });
}

async function app() {
    console.log('IMA Data Builder Start');
    client.watch(tube).onSuccess(function() {
        function resJob() {
            client.reserve().onSuccess(async function(job) {
                // console.log('Reserved (' + Date.now() + '): ' , job);

                try {
                    // console.log("THE JOB"  + job.data);
                    let data = JSON.parse(job.data);

                    console.log('Starting job: ' + job.id);
                    // console.log('Job Data: ', job);

                    /*
                       API TEST API TEST
                     */

                    // Login/get token
                    console.log('API URL: ', data.api_login_url);
                    let post_data = {
                        'username': data.api_user,
                        'password': data.api_key
                    };
                    let login_token = await apiCall(data.api_login_url, post_data, 'POST');
                    token = login_token.token;

                    // Create tracked Job

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
                            'job_type': 'ima_update',
                            'target_entity': 'ima_page',
                            'additional_data': tmp_file_extension,
                            'total': 99999999
                        }
                    };

                    // console.log('Creating Job:', data.api_create_job_url);
                    let tracked_job = await apiCall(data.api_create_job_url, new_job, 'POST');
                    // console.log('Tracked Job ID: ', tracked_job.id);


                    /*
                       API TEST API TEST
                     */
                    /*
                    {
                        "ima_uuid":"0f59b751673686197f49f4e117e9",
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
                    let basepath = '/home/rruff/data-publisher/';
                    if(data.ima_update_rebuild) {
                        // let stats = fs.statSync(basepath + '/web/uploads/IMA/mineral_data.js');
                        // Utillize Master Tag Data for update cycle as it is recreated every time
                        let stats = fs.statSync(basepath + '/web/uploads/IMA/master_tag_data.js');
                        let mtime = Date.parse(stats.mtime);
                        // Rework all the URLs - use file access time to generate timestamp
                        // data.ima_url
                        data.ima_url = data.ima_url.replace(/99999999/,mtime);
                        // data.cell_params_url
                        data.cell_params_url = data.cell_params_url.replace(/99999999/,mtime);
                        // data.powder_diffraction_url
                        data.powder_diffraction_url = data.powder_diffraction_url.replace(/99999999/,mtime);
                        // data.references_url
                        data.references_url = data.references_url.replace(/99999999/,mtime);
                        // data.amcsd_url
                        data.amcsd_url = data.amcsd_url.replace(/99999999/,mtime);
                        // data.paragenetic_modes_url
                        data.paragenetic_modes_url = data.paragenetic_modes_url.replace(/99999999/,mtime);
                    }


                    // Get IMA Records
                    let full_ima_record_data = await loadPage(data.full_ima_url);
                    console.log('FIMA RECORDS: ', full_ima_record_data.records.length);
                    let ima_record_data = await loadPage(data.ima_url);
                    console.log('IMA RECORDS: ', ima_record_data.records.length);
                    // Get Cell Params Records
                    let cell_params_record_data = await loadPage(data.cell_params_url);
                    console.log('CP RECORDS: ', cell_params_record_data.records.length);
                    // Get Powder Diffraction Records from RRUFF
                    let powder_diffraction_record_data = await loadPage(data.powder_diffraction_url);
                    console.log('PD RECORDS: ', powder_diffraction_record_data.records.length);
                    let paragenetic_modes_record_data = await loadPage(data.paragenetic_modes_url);
                    console.log('PM RECORDS: ', paragenetic_modes_record_data.records.length);

                    // Get reference list
                    console.log('REF: ' + data.references_url);
                    let reference_record_data = await loadPage(data.references_url);
                    console.log('REFERENCE RECORDS: ', reference_record_data.records.length);
                    // Get AMCSD Cell Parameters
                    console.log('REF: ' + data.amcsd_url);
                    let amcsd_record_data = await loadPage(data.amcsd_url);
                    console.log('AMCSD RECORDS: ', amcsd_record_data.records.length);


                    // Initialize temp files
                    let content = '';
                    if(!data.ima_update_rebuild) {
                        content = '' +
                            'var mineral_data_array = new Array();\n' +
                            'var mineral_keys = new Array();\n' +
                            'var mineral_name_keys = new Array();\n' +
                            'let minerals_by_name = [];\n';
                    }

                    // console.log('WriteFile Init');
                    let mineral_data_filename = basepath + data.mineral_data + '.' + tmp_file_extension;
                    console.log('writeFile: ' + basepath + data.mineral_data + '.' + tmp_file_extension);
                    await writeFile(mineral_data_filename, content);

                    if(!data.ima_update_rebuild) {
                        content = '<?php ' +
                            '$mineral_names = array();\n' +
                            '$mineral_names_lowercase = array();\n';
                    }
                    let mineral_data_include_filename = basepath + data.mineral_data + '_include.' + tmp_file_extension;
                    console.log('writeFile: ' + basepath + data.mineral_data + '_include.' + tmp_file_extension);
                    await writeFile(mineral_data_include_filename, content);

                    if(!data.ima_update_rebuild) {
                        content = '<?php ' +
                            '$author_names = array();\n\n';
                    }
                    let author_names_filename = basepath + data.mineral_data + '_authors.' + tmp_file_extension;
                    console.log('writeFile: ' + basepath + data.mineral_data + '_authors.' + tmp_file_extension);
                    await writeFile(author_names_filename, content);

                    // Initialize temp files
                    if(!data.ima_update_rebuild) {
                        content = 'var cellparams_range=new Array();';
                    }
                    await writeFile(basepath + data.cell_params_range + '.' + tmp_file_extension, content);

                    // Initialize temp files
                    if(!data.ima_update_rebuild) {
                        content = 'var sg_synonyms={';
                    }
                    await writeFile(basepath + data.cell_params_synonyms + '.' + tmp_file_extension, content);

                    let cell_params_filename = basepath + data.cell_params + '.' + tmp_file_extension;

                    // Initialize temp files [references]
                    if(!data.ima_update_rebuild) {
                        content = 'var references=new Array();';
                    }
                    let references_filename = basepath + data.references + '.' + tmp_file_extension;
                    await writeFile(references_filename, content);

                    if(!data.ima_update_rebuild) {
                        content = 'let paragenetic_modes = [];\n';
                    }

                    // console.log('WriteFile Init');
                    let paragenetic_modes_filename = basepath + data.pm_data + '.' + tmp_file_extension;
                    console.log('writeFile: ' + basepath + data.pm_data + '.' + tmp_file_extension);
                    await writeFile(paragenetic_modes_filename, content);

                    // Initialize master_tag_data
                    // TODO Should we always rebuild this?  Not intensive.
                    content = 'var master_tag_data = new Array();';
                    // Get IMA Template (for Tag Data)
                    // console.log('IMA Template: ' + data.ima_template_url)
                    let ima_record_template = await loadPage(data.ima_template_url);
                    let ima_record_map = data.ima_record_map;
                    let tag_data = [];
                    console.log("Build Tag Data (MASTER)");
                    await buildTagData(ima_record_map.ima_template_tags_uuid, ima_record_template, tag_data, 'master');
                    content += tag_data.join('');

                    let master_tag_data_filename = basepath + data.master_tag_data + '.' + tmp_file_extension;
                    await writeFile(master_tag_data_filename, content);

                    // Initialize master_tag_data
                    // TODO Should we always rebuild this?  Not intensive.
                    content = 'var pm_tag_data = new Array();';
                    // Get IMA Template (for Tag Data)
                    // console.log('IMA Template: ' + data.pm_template_url)
                    let pm_record_template = await loadPage(data.paragenetic_modes_template_url);
                    let pm_record_map = data.paragenetic_modes_record_map;
                    tag_data = [];
                    console.log("Build Tag Data (PM)");
                    await buildTagData(pm_record_map.tags_field_uuid, pm_record_template, tag_data, 'pm');
                    content += tag_data.join('');

                    let pm_tag_data_filename = basepath + data.pm_tag_data + '.' + tmp_file_extension;
                    await writeFile(pm_tag_data_filename, content);

                    let job_count = 0;
                    //
                    // Send jobs to IMA Tube
                    //
                    // let cell_params_headers = '';
                    // for(let i = 0; i < 1; i++) {
                    // for(let i = 0; i < 300; i++) {
                    for(let i = 0; i < full_ima_record_data.records.length; i++) {

                        let record = full_ima_record_data.records[i];
                        // console.log(record);
                        /*
                            'base_url' => $baseurl,
                            'ima_uuid' => $this->container->getParameter('ima_uuid'),
                            'cell_params_uuid' => $this->container->getParameter('cell_params_uuid'),
                            'mineral_data' => $this->container->getParameter('mineral_data'),
                            'cell_params' => $this->container->getParameter('cell_params'),
                            'cell_params_range' => $this->container->getParameter('cell_params_range'),
                            'cell_params_synonyms' => $this->container->getParameter('cell_params_synonyms'),
                            'tag_data' => $this->container->getParameter('tag_data')
                         */

                        record.mineral_index = i;
                        record.api_user = data.api_user;
                        record.api_key = data.api_key;
                        record.api_login_url = data.api_login_url;
                        record.api_worker_job_url = data.api_worker_job_url;
                        record.api_job_status_url = data.api_job_status_url;
                        record.tracked_job_id = tracked_job.id;
                        record.file_extension = tmp_file_extension;
                        record.base_path = basepath;
                        record.base_url = data.base_url;
                        record.cell_params_uuid = data.cell_params_uuid;
                        record.mineral_data = data.mineral_data;
                        record.cell_params = data.cell_params;
                        record.cell_params_range = data.cell_params_range;
                        record.cell_params_synonyms = data.cell_params_synonyms;
                        record.ima_record_map = data.ima_record_map;
                        record.amcsd_record_map = data.amcsd_record_map;
                        record.cell_params_map = data.cell_params_map;
                        record.powder_diffraction_map = data.powder_diffraction_map;


                        // Determine if we should process this record
                        let found = false;
                        for(let j= 0; j < ima_record_data.records.length; j++) {
                            if(ima_record_data.records[j].internal_id === record.internal_id) {
                                found = true;
                                break;
                            }
                        }
                        // console.log(record)
                        if(found) {
                            job_count++;
                            record_client.use(record_tube)
                                .onSuccess(
                                    () => {
                                        // console.log('Tube: ', tubeName);
                                        // console.log('Record: ', record);
                                        // TODO Build Full Record Here
                                        record_client.put(JSON.stringify(record)).onSuccess(
                                            (jobId) => {
                                                // console.log('IMA Record Job ID: ', jobId);
                                            }
                                        );
                                    }
                                );

                            // Creating arrays for the Cell Parameters records using IMA Mineral UniqueIDs
                            // console.log('Mineral Name Field: ' + data.cell_params_map.mineral_name + ' ' + record.template_uuid);
                            // This is moved to the cell params file
                            // cell_params_headers += 'if(cellparams[\'' + record.unique_id + '\'] === undefined) { cellparams[\'' + record.unique_id + '\'] = new Array()};'
                        }
                    }

                    // Write the Cell Parameters Array File
                    content = '';
                    if(!data.ima_update_rebuild) {
                        console.log('Data IMA UPDATE REBUILD: ', data.ima_update_rebuild);
                        content = 'let cellparams=new Array();';
                        content += 'let rruff_record_exists=new Array();';
                    }
                    // await writeFile(basepath + data.cell_params + '.' + tmp_file_extension, content + cell_params_headers);
                    await writeFile(basepath + data.cell_params + '.' + tmp_file_extension, content);

                    //
                    // Send jobs to Cell Params Tube
                    // Need to get Cell Params from IMA Record
                    // Need to get Cell Params from Powder Record
                    // Need to get Cell params from Cell Params DB?
                    //
                    // for(let i = 0; i < 1; i++) {
                    // for(let i = 0; i < 200; i++) {
                    for(let i = 0; i < cell_params_record_data.records.length; i++) {
                        let record = cell_params_record_data.records[i];
                        /*
                            'base_url' => $baseurl,
                            'ima_uuid' => $this->container->getParameter('ima_uuid'),
                            'cell_params_uuid' => $this->container->getParameter('cell_params_uuid'),
                            'mineral_data' => $this->container->getParameter('mineral_data'),
                            'cell_params' => $this->container->getParameter('cell_params'),
                            'cell_params_range' => $this->container->getParameter('cell_params_range'),
                            'cell_params_synonyms' => $this->container->getParameter('cell_params_synonyms'),
                            'tag_data' => $this->container->getParameter('tag_data')
                         */

                        record.cell_params_index = i;
                        record.cell_params_type = 'cell_params';
                        record.tracked_job_id = tracked_job.id;
                        record.api_user = data.api_user;
                        record.api_key = data.api_key;
                        record.api_login_url = data.api_login_url;
                        record.api_worker_job_url = data.api_worker_job_url;
                        record.author_names_filename = author_names_filename;
                        record.api_job_status_url = data.api_job_status_url;
                        record.file_extension = tmp_file_extension;
                        record.base_path = basepath;
                        record.base_url = data.base_url;
                        record.cell_params_uuid = data.cell_params_uuid;
                        record.mineral_data = data.mineral_data;
                        record.cell_params = data.cell_params;
                        record.cell_params_range = data.cell_params_range;
                        record.cell_params_synonyms = data.cell_params_synonyms;
                        record.ima_record_map = data.ima_record_map;
                        record.amcsd_record_map = data.amcsd_record_map;
                        record.cell_params_map = data.cell_params_map;
                        record.powder_diffraction_map = data.powder_diffraction_map;

                        job_count++;
                        cell_params_record_client.use(cell_params_record_tube)
                            .onSuccess(
                                () => {
                                    cell_params_record_client.put(JSON.stringify(record)).onSuccess(
                                        (jobId) => {
                                            console.log('CPDB Cell Parameters ID: ', jobId);
                                        }
                                    );
                                }
                            );
                    }


                    //
                    // Paragenetic Modes Data
                    //
                    // console.log('PM Records:', paragenetic_modes_record_data.records.length);
                    // for(let i = 0; i < 1; i++) {
                    // for(let i = 0; i < 20; i++) {
                    for(let i = 0; i <  paragenetic_modes_record_data.records.length; i++) {
                        let record = paragenetic_modes_record_data.records[i];
                        record.cell_params_index = i;
                        record.cell_params_type = 'powder_diffraction';
                        record.tracked_job_id = tracked_job.id;
                        record.api_user = data.api_user;
                        record.api_key = data.api_key;
                        record.api_login_url = data.api_login_url;
                        record.api_worker_job_url = data.api_worker_job_url;
                        record.api_job_status_url = data.api_job_status_url;
                        record.file_extension = tmp_file_extension;
                        record.base_path = basepath;
                        record.base_url = data.base_url;
                        record.cell_params_uuid = data.cell_params_uuid;
                        record.mineral_data = data.mineral_data;
                        record.cell_params = data.cell_params;
                        record.paragenetic_modes_uuid = data.paragenetic_modes_uuid;
                        record.pm_data = data.pm_data;
                        record.paragenetic_modes_record_map = data.paragenetic_modes_record_map;
                        record.cell_params_range = data.cell_params_range;
                        record.cell_params_synonyms = data.cell_params_synonyms;
                        record.ima_record_map = data.ima_record_map;
                        record.amcsd_record_map = data.amcsd_record_map;
                        record.cell_params_map = data.cell_params_map;
                        record.powder_diffraction_map = data.powder_diffraction_map;

                        job_count++;
                        paragenetic_modes_record_client.use(paragenetic_modes_record_tube)
                            .onSuccess(
                                () => {
                                    // console.log('Tube: ' , tubeName);
                                    // console.log('Record: ', record);
                                    // TODO Build Full Record Here
                                    paragenetic_modes_record_client.put(JSON.stringify(record)).onSuccess(
                                        (jobId) => {
                                            console.log('Paragenetic Modes Job ID: ', jobId);
                                        }
                                    );
                                }
                            );
                    }


                    //
                    // Send RRUFF Records to Cell Params Tube
                    // To extract Cell Params Data from Powder Diffraction Records
                    //
                    // console.log('PD Records:', powder_diffraction_record_data.records.length);
                    // for(let i = 0; i < 1; i++) {
                    // for(let i = 0; i < 200; i++) {
                    for(let i = 0; i <  powder_diffraction_record_data.records.length; i++) {
                        let record = powder_diffraction_record_data.records[i];
                        record.cell_params_index = i;
                        record.cell_params_type = 'powder_diffraction';
                        record.tracked_job_id = tracked_job.id;
                        record.api_user = data.api_user;
                        record.api_key = data.api_key;
                        record.api_login_url = data.api_login_url;
                        record.api_worker_job_url = data.api_worker_job_url;
                        record.api_job_status_url = data.api_job_status_url;
                        record.author_names_filename = author_names_filename;
                        record.file_extension = tmp_file_extension;
                        record.base_path = basepath;
                        record.base_url = data.base_url;
                        record.cell_params_uuid = data.cell_params_uuid;
                        record.mineral_data = data.mineral_data;
                        record.cell_params = data.cell_params;
                        record.cell_params_range = data.cell_params_range;
                        record.cell_params_synonyms = data.cell_params_synonyms;
                        record.ima_record_map = data.ima_record_map;
                        record.amcsd_record_map = data.amcsd_record_map;
                        record.cell_params_map = data.cell_params_map;
                        record.powder_diffraction_map = data.powder_diffraction_map;

                        job_count++;
                        cell_params_record_client.use(cell_params_record_tube)
                            .onSuccess(
                                () => {
                                    cell_params_record_client.put(JSON.stringify(record)).onSuccess(
                                        (jobId) => {
                                            console.log('RRUFF Cell Parameters Job ID: ', jobId);
                                        }
                                    );
                                }
                            );
                    }


                    // Get AMCSD Records & Send to Cell Params Tube
                    // TODO Implement IMA Lookup for AMCSD Cell Params
                    // for(let i = 0; i < 1; i++) {
                    // for(let i = 0; i < 200; i++) {
                    for(let i = 0; i <  amcsd_record_data.records.length; i++) {
                        let record = amcsd_record_data.records[i];
                        record.cell_params_index = i;
                        record.cell_params_type = 'amcsd';
                        record.tracked_job_id = tracked_job.id;
                        record.author_names_filename = author_names_filename;
                        record.api_user = data.api_user;
                        record.api_key = data.api_key;
                        record.api_login_url = data.api_login_url;
                        record.api_worker_job_url = data.api_worker_job_url;
                        record.api_job_status_url = data.api_job_status_url;
                        record.file_extension = tmp_file_extension;
                        record.base_path = basepath;
                        record.base_url = data.base_url;
                        record.cell_params_uuid = data.cell_params_uuid;
                        record.mineral_data = data.mineral_data;
                        record.cell_params = data.cell_params;
                        record.cell_params_range = data.cell_params_range;
                        record.cell_params_synonyms = data.cell_params_synonyms;
                        record.ima_record_map = data.ima_record_map;
                        record.amcsd_record_map = data.amcsd_record_map;
                        record.cell_params_map = data.cell_params_map;
                        record.powder_diffraction_map = data.powder_diffraction_map;

                        job_count++;
                        cell_params_record_client.use(cell_params_record_tube)
                            .onSuccess(
                                () => {
                                    cell_params_record_client.put(JSON.stringify(record)).onSuccess(
                                        (jobId) => {
                                            console.log('AMCSD Cell Parameters ID [internal_id]: ', record.internal_id);
                                            console.log('AMCSD Cell Parameters ID [unique_id]: ', record.unique_id);
                                            console.log('AMCSD Cell Parameters ID: ', jobId);
                                        }
                                    );
                                }
                            );
                    }


                    // Get References and Build List for References Tube
                    // for(let i = 0; i < 1; i++) {
                    // for(let i = 0; i < 200; i++) {
                    for(let i = 0; i <  reference_record_data.records.length; i++) {
                        let record = reference_record_data.records[i];
                        record.cell_params_index = i;
                        record.tracked_job_id = tracked_job.id;
                        record.api_user = data.api_user;
                        record.api_key = data.api_key;
                        record.api_login_url = data.api_login_url;
                        record.api_worker_job_url = data.api_worker_job_url;
                        record.api_job_status_url = data.api_job_status_url;
                        record.file_extension = tmp_file_extension;
                        record.base_path = basepath;
                        record.base_url = data.base_url;
                        record.cell_params_uuid = data.cell_params_uuid;
                        record.mineral_data = data.mineral_data;
                        record.cell_params = data.cell_params;
                        record.references = data.references;
                        record.cell_params_range = data.cell_params_range;
                        record.cell_params_synonyms = data.cell_params_synonyms;
                        record.cell_params_map = data.cell_params_map;
                        record.ima_record_map = data.ima_record_map;
                        record.reference_record_map = data.reference_record_map;
                        record.amcsd_record_map = data.amcsd_record_map;
                        record.powder_diffraction_map = data.powder_diffraction_map;

                        job_count++;
                        references_record_client.use(references_record_tube)
                            .onSuccess(
                                () => {
                                    references_record_client.put(
                                        JSON.stringify(record)
                                    ).onSuccess(
                                      (jobId) => {
                                          console.log('References Record Job ID: ', jobId);
                                      }
                                    );
                                }
                            );
                    }

                    // console.log('Job Count:', job_count);

                    // console.log('Updating Job:', data.api_create_job_url);
                    tracked_job.total = job_count;
                    tracked_job = await apiCall(data.api_create_job_url, { 'job':  tracked_job}, 'PUT');
                    // console.log('Tracked Job: ', tracked_job);


                    // Add job to finisher tube
                    let record = {};
                    record.ima_update_rebuild = data.ima_update_rebuild;
                    record.mineral_data_filename = mineral_data_filename;
                    record.mineral_data_include_filename = mineral_data_include_filename;
                    record.author_names_filename = author_names_filename;
                    record.cell_params_filename = cell_params_filename;
                    record.paragenetic_modes_filename = paragenetic_modes_filename;
                    record.references_filename = references_filename;
                    record.master_tag_data_filename = master_tag_data_filename;
                    record.pm_tag_data_filename = pm_tag_data_filename;
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
                    record.cell_params_uuid = data.cell_params_uuid;
                    record.mineral_data = data.mineral_data;
                    record.cell_params = data.cell_params;
                    record.pm_data = data.pm_data;
                    record.cell_params_range = data.cell_params_range;
                    record.cell_params_synonyms = data.cell_params_synonyms;
                    record.ima_record_map = data.ima_record_map;
                    record.amcsd_record_map = data.amcsd_record_map;
                    record.cell_params_map = data.cell_params_map;
                    record.powder_diffraction_map = data.powder_diffraction_map;

                    ima_data_finisher_client.use(ima_data_finisher_tube)
                        .onSuccess(
                            () => {
                                ima_data_finisher_client.put(JSON.stringify(record))
                                    .onSuccess(
                                        (jobId) => {
                                            console.log('IMA Record Job ID: ', jobId);
                                        }
                                );
                            }
                        );


                    // throw Error('Break for debugging.');

                    client.deleteJob(job.id).onSuccess(function() {
                        // console.log('Deleted (' + Date.now() + '): ' , job);
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

async function writeFile(file_name, content) {
    try {
        fs.writeFileSync(file_name, content);
        // file written successfully
    } catch (err) {
        console.log(err);
    }
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


async function findValue(field_uuid, record) {
    if(
        record['fields_' + record.template_uuid] !== undefined
        && record['fields_' + record.template_uuid].length > 0
    ) {
        let fields = record['fields_' + record.template_uuid];
        for(let i = 0; i < fields.length; i++) {
            if(fields[i].template_field_uuid !== undefined
                && fields[i].template_field_uuid === field_uuid) {
                if(
                    fields[i].files !== undefined
                    && fields[i].files[0] !== undefined
                    && fields[i].files[0].href !== undefined
                ) {
                    // console.log('Getting file: ', fields[i].files[0])
                    return fields[i].files[0].href;
                }
                if(fields[i].value !== undefined) {
                    return fields[i].value.toString().replace(/'/g, "\\'");
                }
                else if(fields[i].values !== undefined) {
                    let output = '';
                    for(let j = 0; j < fields[i].values.length; j++) {
                        output += fields[i].values[j].name + ', ';
                    }
                    output = output.replace(/,\s$/, '');
                    return output;
                }
                else {
                    return '';
                }
            }
            else if(fields[i].field_uuid !== undefined && fields[i].field_uuid === field_uuid) {
                if(
                    fields[i].files !== undefined
                    && fields[i].files[0] !== undefined
                    && fields[i].files[0].href !== undefined
                ) {
                    // console.log('Getting file 2: ', fields[i].files[0])
                    return fields[i].files[0].href;
                }
                if(fields[i].value !== undefined) {
                    return fields[i].value.toString().replace(/'/g, "\\'");
                }
                else if(fields[i].values !== undefined) {
                    let output = '';
                    for(let j = 0; j < fields[i].values.length; j++) {
                        output += fields[i].values[j].name + ', ';
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
            if(fields[i].template_field_uuid !== undefined &&
                fields[i].template_field_uuid === field_uuid) {
                if(fields[i].files !== undefined && fields[i].files[0].href !== undefined) {
                    return fields[i].files[0].href;
                }
                if(fields[i].value !== undefined) {
                    return fields[i].value.toString().replace(/'/g, "\\'");
                }
                else if(fields[i].values !== undefined) {
                    let output = '';
                    for(let j = 0; j < fields[i].values.length; j++) {
                        output += fields[i].values[j].name + ', ';
                    }
                    output = output.replace(/,\s$/, '');
                    return output;
                }
                else {
                    return '';
                }
            }
            else if(fields[i].field_uuid !== undefined && fields[i].field_uuid === field_uuid) {
                if(fields[i].files !== undefined && fields[i].files[0].href !== undefined) {
                    return fields[i].files[0].href;
                }
                if(fields[i].value !== undefined) {
                    return fields[i].value.toString().replace(/'/g, "\\'");
                }
                else if(fields[i].values !== undefined) {
                    let output = '';
                    for(let j = 0; j < fields[i].values.length; j++) {
                        output += fields[i].values[j].name + ', ';
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

async function buildTagTree(tagTree, tag_data, parent_tag, tag_type) {
    let stub = "master_tag_data";
    if(tag_type !== "master") {
        stub = "pm_tag_data";
    }
    // console.log('Tag Data Length: ', tag_data.length);
    for(let x in tagTree) {
       // console.log('Adding tag')
       let tag = tagTree[x];
       let tag_string = stub + '[' + tag.id + '] = "' + tag.id + '||' + tag.name + '|| ||mineral||1||' + tag.name;
       if(parent_tag !== null && parent_tag.id !== undefined) {
           tag_string += '||' + parent_tag.id;
       }
       else {
           tag_string += '||0';
       }
       if(tag.display_order !== undefined) {
           tag_string +=  '||' + tag.display_order + '";\n';
       }
       else {
           tag_string +=  '||0";\n';
       }
       // console.log(tag_string);
       tag_data.push(tag_string);

       if(tag.tags !== undefined) {
           // console.log('Child tags found');
           await buildTagTree(tag.tags, tag_data, tag, tag_type);
       }
    }
    // console.log('Tag Data XX', tag_data);
}

async function buildTagData(field_uuid, record, tag_data, tag_type) {
    // console.log('Build Tag Data: ' + field_uuid);
    let fields = [];
    if(record['fields'] !== undefined) {
        fields = record['fields'];
        // console.log('Fields found: ' + fields.length)
    }
    if(
        record['fields_' + record.template_uuid] !== undefined
        && record['fields_' + record.template_uuid].length > 0
    ) {
        fields = record['fields_' + record.template_uuid];
        // console.log('Fields found 2: ' + fields.length)
    }
    if(
        record['fields_' + record.record_uuid] !== undefined
        && record['fields_' + record.record_uuid].length > 0
    ) {
        fields = record['fields_' + record.record_uuid];
        // console.log('Fields found 3: ' + fields.length)
    }

    if(fields.length > 0) {
        for(let i = 0; i < fields.length; i++) {
            // console.log('Fields traversal: ', i)
            let key = Object.keys(fields[i])[0]
            if(
                fields[i][key].template_field_uuid !== undefined
                && fields[i][key].template_field_uuid === field_uuid
            ) {
                // console.log('Field found by template uuid');
                if(fields[i][key].tags !== undefined) {
                    // console.log('Tags found')
                    await buildTagTree(fields[i][key].tags, tag_data, null, tag_type);
                    // console.log('TAG DATA 1: ', tag_data);
                }
            }
            else if(
                fields[i][key].field_uuid !== undefined
                && fields[i][key].field_uuid === field_uuid
            ) {
                // console.log('Field found by field uuid');
                if(fields[i][key].tags !== undefined) {
                    // console.log('Tags found')
                    await buildTagTree(fields[i][key].tags, tag_data, null, tag_type);
                    // console.log('TAG DATA 2: ', tag_data);
                }
            }
        }
    }
    let child_records = [];
    if(record['related_databases'] !== undefined) {
        child_records = record['related_databases'];
        // console.log("Child Records Found: " + child_records.length);
    }
    if(
        record['records_' + record.template_uuid] !== undefined
        && record['records_' + record.template_uuid].length > 0
    ) {
        child_records = record['records_' + record.template_uuid]
        // console.log("Child Records Found 2: " + child_records.length);
    }
    if(
        record['records_' + record.record_uuid] !== undefined
        && record['records_' + record.record_uuid].length > 0
    ) {
        child_records = record['records_' + record.record_uuid]
        // console.log("Child Records Found 3: " + child_records.length);
    }

    if(child_records.length > 0) {
        for(let i = 0; i < child_records.length; i++) {
            // console.log('Traversing child records: ', i);
            await buildTagData(field_uuid, child_records[i], tag_data, tag_type);
        }
    }
}

app();
