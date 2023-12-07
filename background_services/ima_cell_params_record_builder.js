/*
 * https://github.com/akeyboardlife/puppeteer-save-svg/blob/master/main.js
 */

const puppeteer = require('puppeteer');
const fs = require('fs');

const bs = require('nodestalker');
const client = bs.Client('127.0.0.1:11300');
const tube = 'odr_cell_params_record_builder';
let browser;

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
                    let record = JSON.parse(job.data)

                    console.log('Starting job: ' + job.id)

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
                        "ima_url":"\\/\\/beta.rruff.net\\/odr_rruff",
                        "cell_params_url":"\\/\\/beta.rruff.net\\/odr_rruff"
                     }
                     */

                    /*
                        path: ^/api/{version}/dataset/record/{record_uuid}
                     */
                    let record_url = record.base_url + '/odr/api/v4/dataset/record/' + record.unique_id;
                    console.log(record_url);

                    // Need to get token??
                    let record_data = await loadPage(record_url);
                    console.log(record_data.template_uuid);

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
                    let content = '';
                    // From the Cell Parameters Database
                    if(record_data.template_uuid === cp_map.template_uuid) {
                        console.log('Processing Cell Params Record');
                        // The array key is the IMA Mineral UUID
                        let ima_record = await findRecordByTemplateUUID(record_data['records_' + cp_map.template_uuid],cp_map.ima_template_uuid);
                        content += 'cellparams[\'' +
                            ima_record.record_uuid +
                            // await findValue(cp_map.mineral_name, record_data) +
                            '\'].push("' +
                            // Source
                            await 'R|' +
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
                    // From RRUFF Powder Diffraction Records
                    else if(record_data.template_uuid === pd_map.template_uuid) {
                        // TODO We could have multiple PD Children on a single record
                        // console.log('Processing Powder Diffraction Record: ', record_data);
                        console.log('Processing Powder Diffraction Record');
                        let ima_record = await findRecordByTemplateUUID(record_data['records_' + pd_map.template_uuid],cp_map.ima_template_uuid);
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
                            await findValue(pd_map.c , record_data) + '|' +
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
                            ).toString('base64')  + '|' +
                            // File/citation link
                            'https://beta.rruff.net/' + await findValue(pd_map.rruff_id, record_data) + '|' +
                            // File/citation link 2
                            await findValue('' , record_data) + '|' +
                            // Status Notes Base64
                            Buffer.from(
                                'Locality: ' + await findValue(pd_map.status_notes, record_data)
                            ).toString('base64') +
                        '");\n';
                    }

                    // console.log(content)
                    console.log('writeFile: ' + record.base_path + record.cell_params + '.' + record.file_extension);
                    await appendFile( record.base_path + record.cell_params + '.' + record.file_extension, content);

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
    for(let i = 0; i < records.length; i++) {
        // console.log('Record UUID: ' + records[i].template_uuid + ' ' + target_uuid)
        if(records[i].template_uuid === target_uuid) {
            // console.log(records[i].record_uuid)
            return records[i];
        }
    }
    return null
}

async function findValue(field_uuid, record) {
    if(
        record['fields_' + record.template_uuid] !== undefined
        && record['fields_' + record.template_uuid].length > 0
    ) {
        let fields = record['fields_' + record.template_uuid];
        for(let i = 0; i < fields.length; i++) {
            if(fields[i].template_field_uuid !== undefined && fields[i].template_field_uuid === field_uuid) {
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
            if(fields[i].template_field_uuid !== undefined && fields[i].template_field_uuid == field_uuid) {
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
            else if(fields[i].field_uuid !== undefined && fields[i].field_uuid == field_uuid) {
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

app();
