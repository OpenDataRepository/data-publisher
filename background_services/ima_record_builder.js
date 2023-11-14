/*
 * https://github.com/akeyboardlife/puppeteer-save-svg/blob/master/main.js
 */

const puppeteer = require('puppeteer');
const fs = require('fs');

const bs = require('nodestalker');
const client = bs.Client('127.0.0.1:11300');
const tube = 'odr_ima_record_builder';
let browser;

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
                    // console.log(record_data);

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
                    // ||
                    // ||
                    // ||1
                    // ||Na Pb C O H
                    // TAG Data: ||785 765 786 90 236 173 766 813 895 893 1132 1135 1000001 1000008 Tags???
                    // WHAT is this?? ||18519 18520 19655 19668 19924
                    // ||
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
                            await findValue('15ecaaaa9bebc84862bc45523aab' , record_data) +
                        '",id:"' +
                            // Mineral ID
                            // await findValue('5b8394b6683f3714786a2dbde9b4' , record_data) +
                             record_data.record_uuid +
                        '"};';

                    content += '' +
                        'mineral_keys[' +
                            // Mineral ID
                            '\'' + record_data.record_uuid + '\'' +
                            // await findValue('5b8394b6683f3714786a2dbde9b4' , record_data) +
                        ']=\'' +
                            // Mineral Name
                            await findValue('15ecaaaa9bebc84862bc45523aab' , record_data) +
                        '\';';

                    content += '' +
                        'mineral_name_keys[\'' +
                            // await findValue('5b8394b6683f3714786a2dbde9b4' , record_data) +
                            // Mineral Name
                            await findValue('15ecaaaa9bebc84862bc45523aab' , record_data) +
                        '\']=\'' +
                            // Mineral ID
                            record_data.record_uuid +
                        '\';';

                    content += '' +
                        'mineral_data_array[' +
                            // Mineral ID
                            '\'' + record_data.record_uuid + '\'' +
                            // await findValue('5b8394b6683f3714786a2dbde9b4' , record_data) +
                        ']=\'' +
                        // Mineral Name
                        await findValue('15ecaaaa9bebc84862bc45523aab' , record_data) + '||' +
                        // Mineral Display Name
                        await findValue('f756a7e5caac372fffc51e63d380' , record_data) + '||' +
                        // Ideal IMA Formula (html)
                        formatChemistry(await findValue('46a794d871c38c924e85ae4b9e21' , record_data)) + '||' +
                        // RRUFF Formula (html)
                        formatChemistry(await findValue('95ed4300139930bdce915d72cb4f' , record_data)) + '||' +
                        // <empty>
                        await findValue('' , record_data) + '||' +
                        // <empty>
                        await findValue('' , record_data) + '||' +
                        // <unknown>
                        await findValue('' , record_data) + '||' +
                        // Chemistry Elements
                        await findValue('f5c20d29acd83ccb3fce86cd1f6f' , record_data) + '||' +
                        // Tag Data
                        await findValue('a3c5ec7e195e9126b4ece76eed51' , record_data) + '||' +
                        // <unknown>
                        await findValue('' , record_data) + '||' +
                        // <empty>
                        await findValue('' , record_data) + '||' +
                        // <empty>
                        await findValue('' , record_data) + '||' +
                        // Ideal IMA Formula (raw)
                        await findValue('46a794d871c38c924e85ae4b9e21' , record_data) + '||' +
                        // RRUFF Formula (raw)
                        await findValue('95ed4300139930bdce915d72cb4f' , record_data) + '||' +
                        // <empty>
                        await findValue('' , record_data) + '||' +
                        // <empty>
                        await findValue('' , record_data) + '||' +
                        // Mineral ID
                        await findValue('5b8394b6683f3714786a2dbde9b4' , record_data) + '||' +
                        // Status Notes Base64
                        Buffer.from(
                            await findValue('cd59394d60904fc702a96a8ab6b0' , record_data)
                        ).toString('base64') + '||' +
                        // Chemistry Elements
                        await findValue('' , record_data) + '||' +
                        // IMA Number
                        await findValue('be5d90b42f5616a373fd3fa73526' , record_data) + '||' +
                        // Mineral Name
                        await findValue('15ecaaaa9bebc84862bc45523aab' , record_data) + '||' +
                        // Type Locality Country
                        await findValue('0da35a7e99e4f45e4dfcc1a3f938' , record_data) + '||' +
                        // Year First Published
                        await findValue('655f1d215f4a47c285b9c57907aa' , record_data) + '||' +
                        // Valence Elements
                        await findValue('7e748addd1c79fa45a281838f2f8' , record_data) + '||' +
                        // Mineral Display Abbreviation
                        await findValue('968802c020d5cc648c85c36ec506' , record_data) + '||' +
                        // Mineral Display Abbreviation
                        await findValue('968802c020d5cc648c85c36ec506' , record_data) + '||' +
                        // Mineral UUID
                        record_data.record_uuid +
                        '\';\n';

                    // console.log(content)
                    console.log('writeFile: ' + record.base_path + record.mineral_data + '.' + record.file_extension);
                    await appendFile( record.base_path + record.mineral_data + '.' + record.file_extension, content);



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

async function findValueDep(field_uuid, record) {
    for(let i = 0; i < record.length; i++) {
        if(record[i].template_field_uuid !== undefined && record[i].template_field_uuid == field_uuid) {
            if(record[i].value !== undefined) {
                return record[i].value.toString().replace(/'/g, "\\'");
            }
            else if(record[i].values !== undefined) {
                let output = '';
                for(let j = 0; j < record[i].values.length; j++) {
                    output += record[i].values[j].name + ', ';
                }
                output = output.replace(/,\s$/, '');
                return output;
            }
            else {
                return '';
            }
        }
        else if(record[i].field_uuid !== undefined && record[i].field_uuid == field_uuid) {
            if(record[i].value !== undefined) {
                return record[i].value.toString().replace(/'/g, "\\'");
            }
            else if(record[i].values !== undefined) {
                let output = '';
                for(let j = 0; j < record[i].values.length; j++) {
                    output += record[i].values[j].name + ', ';
                }
                output = output.replace(/,\s$/, '');
                return output;
            }
            else {
                return '';
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
                else if(fields[i].tags !== undefined) {
                    let output = '';
                    for(let j = 0; j < fields[i].tags.length; j++) {
                        output += fields[i].tags[j].id + ' ';
                    }
                    output = output.replace(/,\s$/, '');
                    return output;
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
                else if(fields[i].tags !== undefined) {
                    let output = '';
                    for(let j = 0; j < fields[i].tags.length; j++) {
                        output += fields[i].tags[j].id + ' ';
                    }
                    output = output.replace(/,\s$/, '');
                    return output;
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
                else if(fields[i].tags !== undefined) {
                    let output = '';
                    for(let j = 0; j < fields[i].tags.length; j++) {
                        output += fields[i].tags[j].id + ' ';
                    }
                    output = output.replace(/,\s$/, '');
                    return output;
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
                else if(fields[i].tags !== undefined) {
                    let output = '';
                    for(let j = 0; j < fields[i].tags.length; j++) {
                        output += fields[i].tags[j].id + ' ';
                    }
                    output = output.replace(/,\s$/, '');
                    return output;
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


app();
