/*
 * https://github.com/akeyboardlife/puppeteer-save-svg/blob/master/main.js
 */

/* jshint esversion: 8 */

const puppeteer = require('puppeteer');
const fs = require('fs');

const bs = require('nodestalker');
const client = bs.Client('127.0.0.1:11300');
const tube = 'odr_ima_data_builder';

const record_client = bs.Client('127.0.0.1:11300');
const record_tube = 'odr_ima_record_builder';
const cell_params_record_client = bs.Client('127.0.0.1:11300');
const cell_params_record_tube = 'odr_cell_params_record_builder';
let browser;

function delay(time) {
    return new Promise(function(resolve) {
        setTimeout(resolve, time)
    });
}

async function app() {
    browser = await puppeteer.launch({headless:'new'});
    console.log('IMA Data Builder Start');
    client.watch(tube).onSuccess(function(tubeName) {
        function resJob() {
            client.reserve().onSuccess(async function(job) {
                // console.log('Reserved (' + Date.now() + '): ' , job);

                try {
                    let data = JSON.parse(job.data)

                    console.log('Starting job: ' + job.id)
                    // console.log('Job Data: ', job)


                    /*
                    {
                        "ima_uuid":"0f59b751673686197f49f4e117e9",
                        "cell_params_uuid":"a85a97461686ef3dfe77e14e2209",
                        "mineral_data":"web\\/uploads\\/mineral_data.js",
                        "cell_params":"web\\/uploads\\/cell_params.js",
                        "cell_params_range":"web\\/uploads\\/cell_params_range.js",
                        "cell_params_synonyms":"web\\/uploads\\/cell_params_synonyms.js",
                        "tag_data":"web\\/uploads\\/master_tag_data.js",
                        "ima_url":"\\/\\/beta.rruff.net\\/odr_rruff",
                        "cell_params_url":"\\/\\/beta.rruff.net\\/odr_rruff"
                     }
                     */

                    console.log(data.ima_url);
                    // Get IMA Records
                    let ima_record_data = await loadPage(data.ima_url);
                    // Get Cell Params Records
                    let cell_params_record_data = await loadPage(data.cell_params_url);
                    // Get Powder Diffraction Records
                    console.log(data.powder_diffraction_url + "\n");
                    let powder_diffraction_record_data = await loadPage(data.powder_diffraction_url);

                    // Initialize extension for temp files
                    let tmp_file_extension = Date.now();

                    let basepath = '/home/rruff/data-publisher/';
                    // Initialize temp files
                    let content = '' +
                        'var mineral_data_array = new Array();\n' +
                        'var mineral_keys = new Array();\n' +
                        'var mineral_name_keys = new Array();\n' +
                        'let minerals_by_name = [];\n';

                    // console.log('WriteFile Init');
                    console.log('writeFile: ' + basepath + data.mineral_data + '.' + tmp_file_extension);
                    await writeFile(basepath + data.mineral_data + '.' + tmp_file_extension, content);

                    // Initialize temp files
                    content = 'var cellparams=new Array();';
                    await writeFile(basepath + data.cell_params + '.' + tmp_file_extension, content);

                    // Initialize temp files
                    content = 'var cellparams_range=new Array();';
                    await writeFile(basepath + data.cell_params_range + '.' + tmp_file_extension, content);

                    // Initialize temp files
                    content = 'var sg_synonyms={';
                    await writeFile(basepath + data.cell_params_synonyms + '.' + tmp_file_extension, content);

                    // Initialize master_tag_data
                    content = 'var master_tag_data = new Array();'
                    // Get IMA Template (for Tag Data)
                    // console.log('IMA Template: ' + data.ima_template_url)
                    let ima_record_template = await loadPage(data.ima_template_url);
                    let ima_record_map = data.ima_record_map;
                    let tag_array = await buildTagData(ima_record_map.ima_template_tags_uuid, ima_record_template);
                    content += tag_array;

                    await writeFile(basepath + data.master_tag_data + '.' + tmp_file_extension, content);

                    throw Error('Break for debugging.');
                    //
                    // Send jobs to IMA Tube
                    //
                    let cell_params_headers = '';
                    for(let i = 0; i < 10; i++) {
                    // for(let i = 0; i < 100; i++) {
                    // for(let i = 0; i < ima_record_data.records.length; i++) {
                        let record = ima_record_data['records'][i];
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
                        record.file_extension = tmp_file_extension;
                        record.base_path = basepath;
                        record.base_url = data.base_url;
                        record.cell_params_uuid = data.cell_params_uuid;
                        record.mineral_data = data.mineral_data;
                        record.cell_params = data.cell_params;
                        record.cell_params_range = data.cell_params_range;
                        record.cell_params_synonyms = data.cell_params_synonyms;
                        record.cell_params_map = data.cell_params_map;
                        record.powder_diffraction_map = data.powder_diffraction_map;

                        // console.log(record)
                        record_client.use(record_tube)
                            .onSuccess(
                                (tubeName) => {
                                    // console.log('Tube: ', tubeName);
                                    // console.log('Record: ', record);
                                    // TODO Build Full Record Here
                                    record_client.put(JSON.stringify(record)).onSuccess(
                                        (jobId) => {
                                            console.log('IMA Record Job ID: ', jobId);
                                        }
                                    );
                                }
                            );

                        // Creating arrays for the Cell Parameters records using IMA Mineral UniqueIDs
                        // console.log('Mineral Name Field: ' + data.cell_params_map.mineral_name + ' ' + record.template_uuid);
                        // console.log('Record: ', record);
                        // cell_params_headers += "cellparams['" + await findValue(data.cell_params_map.mineral_name, record) + "'] = [];\n";
                        cell_params_headers += "cellparams['" + record.unique_id + "'] = [];\n";
                    }

                    // Write the Cell Parameters Array File
                    content = 'var cellparams=new Array();\n';
                    await writeFile(basepath + data.cell_params + '.' + tmp_file_extension, content + cell_params_headers);

                    //
                    // Send jobs to Cell Params Tube
                    //
                    for(let i = 0; i < 10; i++) {
                    // for(let i = 0; i < 100; i++) {
                    // for(let i = 0; i < cell_params_record_data.records.length; i++) {
                        let record = cell_params_record_data['records'][i];
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
                        record.file_extension = tmp_file_extension;
                        record.base_path = basepath;
                        record.base_url = data.base_url;
                        record.cell_params_uuid = data.cell_params_uuid;
                        record.mineral_data = data.mineral_data;
                        record.cell_params = data.cell_params;
                        record.cell_params_range = data.cell_params_range;
                        record.cell_params_synonyms = data.cell_params_synonyms;
                        record.cell_params_map = data.cell_params_map;
                        record.powder_diffraction_map = data.powder_diffraction_map;

                        cell_params_record_client.use(cell_params_record_tube)
                            .onSuccess(
                                (tubeName) => {
                                    // console.log('Tube: ' , tubeName);
                                    // console.log('Record: ', record);
                                    // TODO Build Full Record Here
                                    cell_params_record_client.put(JSON.stringify(record)).onSuccess(
                                        (jobId) => {
                                            console.log('Cell ParamsJob ID: ', jobId);
                                        }
                                    );
                                }
                            );

                    }


                    //
                    // Send to Powder Diffraction Tube
                    //
                    // console.log('PD Records:', powder_diffraction_record_data.records.length);
                    for(let i = 0; i < 30; i++) {
                    // for(let i = 0; i < 300; i++) {
                    // for(let i = 0; i <  powder_diffraction_record_data.records.length; i++) {
                        let record = powder_diffraction_record_data.records[i];
                        record.cell_params_index = i;
                        record.file_extension = tmp_file_extension;
                        record.base_path = basepath;
                        record.base_url = data.base_url;
                        record.cell_params_uuid = data.cell_params_uuid;
                        record.mineral_data = data.mineral_data;
                        record.cell_params = data.cell_params;
                        record.cell_params_range = data.cell_params_range;
                        record.cell_params_synonyms = data.cell_params_synonyms;
                        record.cell_params_map = data.cell_params_map;
                        record.powder_diffraction_map = data.powder_diffraction_map;

                        cell_params_record_client.use(cell_params_record_tube)
                            .onSuccess(
                                (tubeName) => {
                                    // console.log('Tube: ' , tubeName);
                                    // console.log('Record: ', record);
                                    // TODO Build Full Record Here
                                    cell_params_record_client.put(JSON.stringify(record)).onSuccess(
                                        (jobId) => {
                                            console.log('Powder Diffraction Job ID: ', jobId);
                                        }
                                    );
                                }
                            );

                    }
                    // Get List of IMA Records

                    /*
                    "records": [
                        {
                            "internal_id": 25599,
                            "unique_id": "a5dddfa3632906b0976c9d43ac84",
                            "external_id": 6482,
                            "record_name": "Abellaite"
                        },
                        {
                            "internal_id": 20213,
                            "unique_id": "10ec8c0342004c1a205bd47f65e6",
                            "external_id": 777,
                            "record_name": "Abelsonite"
                        },
                        {
                            "internal_id": 20214,
                            "unique_id": "e725d6e1173deb9ccc368fac1295",
                            "external_id": 778,
                            "record_name": "Abenakiite-(Ce)"
                        },
                        {
                            "internal_id": 20215,
                            "unique_id": "d063c1139d82a5e94ac26931756a",
                            "external_id": 779,
                            "record_name": "Abernathyite"
                        },
                        {
                            "internal_id": 20216,
                            "unique_id": "8f1a2edeb46360b95767adfc2097",
                            "external_id": 780,
                            "record_name": "Abhurite"
                        },

                     */

                    // Post Record UUID, new ID, and total # to
                    // Builder queue
                    // Builder queue also builds cell param data
                    // If record id == max id - overwrite old file

                    client.deleteJob(job.id).onSuccess(function(del_msg) {
                        // console.log('Deleted (' + Date.now() + '): ' , job);
                        resJob();
                    });
                }
                catch (e) {
                    // TODO need to put job as unfinished - maybe not due to errors
                    console.log('Error occurred: ', e);
                    client.deleteJob(job.id).onSuccess(function(del_msg) {
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

async function loadPage(page_url) {
    // configure folder and http url path
    try {
        const page = await browser.newPage();
        page.on('console', message =>
            console.log(`${message.type().substr(0, 3).toUpperCase()} ${message.text()}`)
        );

        await page.goto('https:' + page_url);

        //I would leave this here as a fail safe
        await page.content();

        innerText = await page.evaluate(() =>  {
            return JSON.parse(document.querySelector("body").innerText);
        });

        // console.log("innerText now contains the JSON");
        // console.log(innerText);

        await page.close();
        return innerText;

    } catch (err) {
        console.error('Error thrown');
        throw(err);
    }
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

async function buildTagTree(tagTree, tag_data, parent_tag) {
    for(let x in tagTree) {
       console.log('Adding tag')
       let tag = tagTree[x];
       let tag_string = tag.id + '||' + tag.name + '|| ||mineral||1||' + tag.name;
       if(parent_tag !== null && parent_tag.id !== undefined) {
           tag_string += '||' + parent_tag.id;
       }
       else {
           tag_string += '||0\n';
       }
       console.log(tag_string);
       tag_data += tag_string;

       if(tag.tags !== undefined) {
           console.log('Child tags found');
           await buildTagTree(tag.tags, tag_data, tag)
       }
    }
}

async function buildTagData(field_uuid, record) {
    console.log('Build Tag Data: ' + field_uuid);
    let fields = [];
    if(record['fields'] !== undefined) {
        fields = record['fields'];
        console.log('Fields found: ' + fields.length)
    }
    if(
        record['fields_' + record.template_uuid] !== undefined
        && record['fields_' + record.template_uuid].length > 0
    ) {
        fields = record['fields_' + record.template_uuid];
        console.log('Fields found 2: ' + fields.length)
    }
    if(
        record['fields_' + record.record_uuid] !== undefined
        && record['fields_' + record.record_uuid].length > 0
    ) {
        fields = record['fields_' + record.record_uuid];
        console.log('Fields found 3: ' + fields.length)
    }

    if(fields.length > 0) {
        for(let i = 0; i < fields.length; i++) {
            console.log('Fields traversal: ', i)
            let key = Object.keys(fields[i])[0]
            console.log(fields[i] + ' ' + key);
            if(fields[i][key].template_field_uuid !== undefined
                && fields[i][key].template_field_uuid === field_uuid
            ) {
                console.log('Field found by template uuid');
                if(fields[i][key].tags !== undefined) {
                    console.log('Tags found')
                    let tag_data = '';
                    await buildTagTree(fields[i][key].tags, tag_data, null);
                    console.log('TAG DATA: ', tag_data)
                    return tag_data;
                }
            }
            else if(fields[i][key].field_uuid !== undefined && fields[i][key].field_uuid === field_uuid) {
                console.log('Field found by field uuid');
                if(fields[i][key].tags !== undefined) {
                    console.log('Tags found')
                    let tag_data = '';
                    await buildTagTree(fields[i][key].tags, tag_data, null);
                    console.log('TAG DATA: ', tag_data)
                    return tag_data;
                }
            }
        }
    }
    let child_records = [];
    if(record['related_databases'] !== undefined) {
        child_records = record['related_databases'];
        console.log("Child Records Found: " + child_records.length);
    }
    if(
        record['records_' + record.template_uuid] !== undefined
        && record['records_' + record.template_uuid].length > 0
    ) {
        child_records = record['records_' + record.template_uuid]
        console.log("Child Records Found 2: " + child_records.length);
    }
    if(
        record['records_' + record.record_uuid] !== undefined
        && record['records_' + record.record_uuid].length > 0
    ) {
        child_records = record['records_' + record.record_uuid]
        console.log("Child Records Found 3: " + child_records.length);
    }

    if(child_records.length > 0) {
        for(let i = 0; i < child_records.length; i++) {
            console.log('Traversing child records: ', i);
            let result = await buildTagData(field_uuid, child_records[i]);
            if(result !== '') {
                output += result;
            }
        }
    }
    return output;

}

app();
