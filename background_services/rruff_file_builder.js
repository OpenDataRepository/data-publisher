/*
 * https://github.com/akeyboardlife/puppeteer-save-svg/blob/master/main.js
 */
const puppeteer = require('puppeteer');
const fs = require('fs');
const fg = require('fast-glob');
const path = require('path');

const { exec } = require('child_process');


const bs = require('nodestalker');
const client = bs.Client('127.0.0.1:11300');
const tube = 'odr_rruff_file_builder';
const Memcached = require("memcached-promise");


let browser;
let memcached_client;
let token = '';
let base_fs_path = '/home/rruff/data-publisher/app/rruff_files';
let base_we_path = '/home/rruff/data-publisher/web/zipped_data_files';

function delay(time) {
    return new Promise(function (resolve) {
        setTimeout(resolve, time)
    });
}

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

async function app() {
    browser = await puppeteer.launch({headless: 'new'});
    memcached_client = new Memcached('localhost:11211', {retries: 10, retry: 10000, remove: false});

    console.log('RRUFF FILE Builder Start');
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

                    // Only process if record UUID is in this set:
                    // Process Sample Images for All Records
                    await processSampleImages(record_data);

                    // If found print record data
                    for(let i = 0; i < record_data['records_' + record_data.template_uuid].length; i++) {

                        let child_record = record_data['records_' + record_data.template_uuid][i];
                        // console.log('Child Record: ', child_record.template_uuid);
                        let record = undefined;

                        // RAMAN FILES
                        if(child_record !== undefined
                            && child_record.template_uuid === template_uuids['raman_child']
                        ) {
                            // Maybe do a search here?
                            let raman_record = child_record;
                            // console.log('RAMAN Record found');
                            if(raman_record['records_' + raman_record.template_uuid] !== undefined) {
                                for(let j= 0; j < raman_record['records_' + raman_record.template_uuid] .length; j++) {
                                    // console.log('RAMAN Child Record: ', raman_record['records_' + raman_record.template_uuid][j].template_uuid);
                                    if(raman_record['records_' + raman_record.template_uuid][j].template_uuid === template_uuids['raman_file_record']) {
                                        // Maybe do a search here?
                                        // console.log('RAMAN Record found');
                                        let raman_file_record = raman_record['records_' + raman_record.template_uuid][j];
                                        await processRamanFiles(raman_file_record);
                                    }
                                }
                            }
                        }

                        //  BROAD SCAN RAMAN FILES
                        if(child_record !== undefined
                            && child_record.template_uuid === template_uuids['broad_scan_raman_child']
                        ) {
                            // Maybe do a search here?
                            let raman_record = child_record;
                            console.log('BROAD SCAN RAMAN Record found');
                            if(raman_record['records_' + raman_record.template_uuid] !== undefined) {
                                for(let j= 0; j < raman_record['records_' + raman_record.template_uuid] .length; j++) {
                                    console.log('BROAD SCAN RAMAN Child Record: ', raman_record['records_' + raman_record.template_uuid][j].template_uuid);
                                    if(raman_record['records_' + raman_record.template_uuid][j].template_uuid === template_uuids['raman_broad_scan_file_record']) {
                                        // Maybe do a search here?
                                        // console.log('RAMAN Record found');
                                        let raman_file_record = raman_record['records_' + raman_record.template_uuid][j];
                                        await processRamanFiles(raman_file_record);
                                    }
                                }
                            }
                        }

                        // Powder Files
                        record = await findRecordByTemplateUUID(
                            child_record['records_' + child_record.template_uuid],
                            template_uuids['powder_child']
                        );
                        await processFiles(record);

                        // Chemistry Files
                        if(child_record.template_uuid === template_uuids['chemistry_child']) {
                            // console.log('Chemistry Child Record: ', child_record.template_uuid);
                            await processFiles(child_record);
                        }

                        // Infrared Files
                        record = await findRecordByTemplateUUID(
                            child_record['records_' + child_record.template_uuid],
                            template_uuids['infrared_child']
                        );
                        await processFiles(record);
                    }

                    let worker_job = {
                        'job': {
                            'tracked_job_id': record.tracked_job_id,
                            'random_key': 'RRUFF_FILE_' + Math.floor(Math.random() * 99999999).toString(),
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

let template_uuids = [];

/*
    RRUFF Image UUID
 */
template_uuids['sample_image'] = 'b571f4738325b415195f432f9625';

/*
    Chemistry Related UUIDs
 */
template_uuids['chemistry_child'] = '73fd47e51616fde8f5e631cada3e';
template_uuids['microprobe_data'] = 'b4869fd388927e4e914f6110a892';

/*
    Powder Diffraction Related UIUDs
 */
template_uuids['powder_child'] = '0deb8003323a661950be48c857bb';
template_uuids['dif_file'] = 'eeef0e5f2639f24107384feeeff7';
template_uuids['cell_data'] = '694056249e7e85dd72a013583526';
template_uuids['cell_output_data'] = 'da3ff31e42afa697698b1efb5bfb';
template_uuids['xy_processed'] = '3939ba3db557ba05d45746f95caf';
template_uuids['xy_raw'] = '092cd44d3be544beda329b002884';
template_uuids['infrared_child'] = 'da0a4296f349ba9b2999e594a311';
template_uuids['infrared_raw'] = '9980ce944fc65a5622e4b0ef6f12';
// template_uuids['xy_processed'] = '092cd44d3be544beda329b002884';

/*
    RAMAN Related UUIDs
 */
template_uuids['raman_child'] = 'a11404296e003a3c5677fe5c5c71';
template_uuids['raman_file_record'] = 'd5634cdabb04b3e771c072afe5b4';
template_uuids['raw_raman_file'] = '9cf77fe1d4068f96c1f7b182bab2';
template_uuids['processed_raman_file'] = '87c62d2fb2edf24842de7eba2106';

template_uuids['broad_scan_raman_child'] = '6a525a1868d7eef32e30e586dc74';
template_uuids['raman_broad_scan_file_record'] = '6a2ac522a7798625593023855b40';
template_uuids['raw_broad_scan_raman_file'] = '1478c4188a1dca0e817aa820243e';
template_uuids['processed_broad_scan_raman_file'] = 'dd805d174cda22a92f1238ae16d5';

async function processSampleImages(child_record) {
    for (let i = 0; i < child_record['fields_' + child_record.template_uuid].length; i++) {
        let child_field = child_record['fields_' + child_record.template_uuid][i];
        let key = Object.keys(child_field)[0];
        // console.log('Child Field: ', child_field[key].template_field_uuid);

        // Search stubs
        // let stubs = [];
        let directory_files = [];
        // Files that should be present
        let valid_files = [];
        if(child_field[key].template_field_uuid === template_uuids['sample_image']) {
            let files = child_field[key].files;
            let file_updated = child_field[key].files[0]._file_metadata._create_date;

            for(let j = 0; j < files.length; j++) {
                // Check quality
                // TODO Determine if we need to check public date
                if(
                    files[j]._file_metadata._quality > 0
                    && files[j].parent_image_id === undefined
                ) {
                    // This is a quality image
                    // console.log('QUALITY FILE: ', files[j].file_uuid);
                    let image_folder = base_fs_path + '/rruff_good_images';
                    if(files[j].original_name.match(files[j].file_uuid)) {
                        let image_stub = files[j].original_name.split(files[j].file_uuid)[0].replace(/([\(\)])/, '\\$1');
                        let image_files_search = image_folder + '/' + image_stub + '*';
                        // console.log('Search for images: ', image_folder + '/' + image_stub + '*');
                        // stubs.push(image_folder + '/' + image_stub);
                        const entries = fg.sync([image_files_search], { dot: false });
                        // console.log('Entries: ', entries);
                        directory_files.push(...entries)

                        // This is a valid file
                        let new_file = image_folder + '/' + files[j].original_name;
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
                            const downloadPath = path.resolve(image_folder); // Ensure this directory exists or create it
                            if (fs.existsSync(downloadPath)){
                                console.log('Directory found: ', downloadPath);
                                try {
                                    console.log('Download file: ', files[j].href);
                                    // await downloadFile(files[j].href, image_folder);
                                    await wgetFile(files[j].href, new_file);
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
            }

            // Delete images that aren't supposed to be there
            for(let j= 0; j < directory_files.length; j++) {
                // console.log('Validating file: ', directory_files[j]);
                if(valid_files.indexOf(directory_files[j]) === -1) {
                    // Delete this file
                    // console.log('Deleting file: ', directory_files[j]);
                    fs.unlinkSync(directory_files[j]);
                }
            }
        }
    }
}

// [DIR]	infrared/	2025-05-18 00:30	-
// Processed.zip	2025-05-18 00:30	13M
// RAW.zip

async function processFiles(record) {
    // console.log('\n\n\n\nPROCESS FILES: ', record.template_uuid);
    if(record !== undefined) {
        // console.log('\n\n\n\nFound Record UUID: ', record)
        // console.log('\n\nDone\n\n\n\n');
        let file_types = [
            'dif_file',
            'cell_data',
            'cell_output_data',
            'xy_raw',
            'xy_processed',
            'infrared_raw',
            'microprobe_data'
            // 'infrared_processed'
        ]

        let directory_files = [];
        // Files that should be present
        let valid_files = [];
        // Process all types
        for(let i = 0; i < file_types.length; i++) {
            let file_type = file_types[i];
            let file = undefined;
            let field = await findFieldByTemplateUUID(
                record['fields_' + record.template_uuid],
                template_uuids[file_type]
            );

            // Skip if field not found
            if(field === undefined) {
                // console.log('Field not found: ' + template_uuids[file_type] + ' ' + record.record_uuid);
                continue;
            }

            // console.log('File Field: [' + file_type + ']', field.files[0].original_name);
            // console.log('File Quality: [' + file_type + ']', field.files[0]._file_metadata._quality);
            file = field.files[0];

            if(file !== undefined) {
                let file_uuid = field.files[0].file_uuid;
                // console.log('File UUID: ', file_uuid);
                let file_name = field.files[0].original_name;
                // console.log('File Name: ', file_name);
                let file_url = field.files[0].href;
                // console.log('File URL: ', file_url);
                let file_quality = field.files[0]._file_metadata._quality;
                // console.log('File Quality: ', file_quality);
                let file_updated = field.files[0]._file_metadata._create_date;

                // Determine Directory Name
                let output_file_directory = '';
                switch (file_type) {
                    case 'dif_file':
                        output_file_directory = 'powder/dif';
                        break;
                    case 'cell_data':
                        output_file_directory = 'powder/refinement_data';
                        break;
                    case 'cell_output_data':
                        output_file_directory = 'powder/refinement_output_data';
                        break;
                    case 'xy_processed':
                        output_file_directory = 'powder/xy_processed';
                        break;
                    case 'xy_raw':
                        output_file_directory = 'powder/xy_raw';
                        break;
                    case 'infrared_processed':
                        output_file_directory = 'infrared/processed';
                        break;
                    case 'infrared_raw':
                        output_file_directory = 'infrared/raw';
                        break;
                    case 'microprobe_data':
                        if(file_name.match(/pdf$/)) {
                            output_file_directory = 'chemistry/reference_pdf';
                        }
                        else {
                            output_file_directory = 'chemistry/microprobe_data';
                        }
                        break;
                }

                let folder = base_fs_path + '/' + output_file_directory;
                if(file_name.match(file_uuid)) {
                    let stub = file_name.split(file_uuid)[0].replace(/([\(\)])/, '\\$1');
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
}


async function processRamanFiles(file_record) {
    if(file_record === undefined) {
        // console.log('File Record is undefined');
        return
    }
    // Search stubs
    let directory_files = [];
    // Files that should be present
    let valid_files = [];

    console.log('Child Record Match: ', file_record.template_uuid);
    if (
        file_record.template_uuid === template_uuids['raman_file_record']
        || file_record.template_uuid === template_uuids['raman_broad_scan_file_record']
    ) {
        console.log('File Record Match: ', file_record.template_uuid);
        // This is a RAMAN Record
        for (let k = 0; k < file_record['fields_' + file_record.template_uuid].length; k++) {
            let child_field = file_record['fields_' + file_record.template_uuid][k];
            let key = Object.keys(child_field)[0];
            // console.log('Child Field: ', child_field[key].template_field_uuid);

            /*
                Quality
                -1 - ignore
                0 - unrated
                1 - poor
                2 - fair
                3 - excellent
             */

            let file = undefined;
            let file_type = '';
            if (child_field[key].template_field_uuid === template_uuids['raw_raman_file']) {
                // RAW File Field
                console.log('RAW File Field: ', child_field[key].files[0].original_name);
                console.log('RAW File Quality: ', child_field[key].files[0]._file_metadata._quality);
                file = child_field[key].files[0];
            } else if (child_field[key].template_field_uuid === template_uuids['processed_raman_file']) {
                // Processed File Field
                console.log('Processed File Field: ', child_field[key].files[0].original_name);
                console.log('Processed File Quality: ', child_field[key].files[0]._file_metadata._quality);
                file = child_field[key].files[0];
            } else if (child_field[key].template_field_uuid === template_uuids['processed_broad_scan_raman_file']) {
                // Processed File Field
                console.log('Processed BROAD SCAN File Field: ', child_field[key].files[0].original_name);
                console.log('Processed BROAD SCAN File Quality: ', child_field[key].files[0]._file_metadata._quality);
                file = child_field[key].files[0];
                file_type = 'broad_scan';
            } else if (child_field[key].template_field_uuid === template_uuids['raw_broad_scan_raman_file']) {
                // Raw File Field
                console.log('Raw BROAD SCAN File Field: ', child_field[key].files[0].original_name);
                console.log('Raw BROAD SCAN File Quality: ', child_field[key].files[0]._file_metadata._quality);
                file = child_field[key].files[0];
                file_type = 'broad_scan';
            }

            if (file !== undefined) {
                let file_uuid = child_field[key].files[0].file_uuid;
                console.log('File UUID: ', file_uuid);
                let file_name = child_field[key].files[0].original_name;
                console.log('File Name: ', file_name);
                let file_url = child_field[key].files[0].href;
                console.log('File URL: ', file_url);
                let file_quality = child_field[key].files[0]._file_metadata._quality;
                console.log('File Quality: ', file_quality);
                let file_updated = child_field[key].files[0]._file_metadata._create_date;


                // Determine Directory Name
                let output_file_directory = '';
                if (file_type === 'broad_scan') {
                    // console.log('File Name: ', file_name);
                    // console.log('Broad Scan File');
                    output_file_directory = 'lr-raman';
                } else {
                    switch (file_quality) {
                        case '-1':
                            // Ignore
                            output_file_directory = 'ignore';
                            break;
                        case '0':
                            // Unrated
                            output_file_directory = 'unrated';
                            break;
                        case '1':
                            // Poor
                            output_file_directory = 'poor';
                            break;
                        case '2':
                            // Fair
                            output_file_directory = 'fair';
                            break;
                        case '3':
                            // Excellent
                            output_file_directory = 'excellent';
                            break;
                    }

                    if (file_name.match(/______Raman/)) {
                        output_file_directory += '_unoriented';
                    } else if (file_name.match(/unoriented/)) {
                        // Unoriented
                        output_file_directory += '_unoriented';
                    } else {
                        // Oriented
                        output_file_directory += '_oriented';
                    }
                }

                /*
                    [DIR]	raman/	2025-05-18 03:32	-
                        LR-Raman.zip	2025-05-18 03:32	219M
                        excellent_oriented.zip	2025-05-18 02:34	75M
                        excellent_unoriented..>	2025-05-18 02:33	221M
                        fair_oriented.zip	2025-05-18 02:34	617K
                        fair_unoriented.zip	2025-05-18 02:34	57M
                        ignore_unoriented.zip	2025-05-18 02:35	1.5M
                        poor_oriented.zip	2025-05-18 02:35	20K
                        poor_unoriented.zip	2025-05-18 02:35	33M
                        unrated_oriented.zip	2025-05-18 02:35	39M
                        unrated_unoriented.zip
                 */

                let image_folder = base_fs_path + '/raman/' + output_file_directory;
                if (file_name.match(file_uuid)) {
                    let image_stub = file_name.split(file_uuid)[0].replace(/([\(\)])/, '\\$1');
                    let image_files_search = image_folder + '/' + image_stub + '*';
                    // console.log('Search for images: ', image_folder + '/' + image_stub + '*');
                    // stubs.push(image_folder + '/' + image_stub);
                    const entries = fg.sync([image_files_search], {dot: false});
                    // console.log('Entries: ', entries);
                    directory_files.push(...entries)

                    // This is a valid file
                    let new_file = image_folder + '/' + file_name;
                    valid_files.push(new_file);

                    let found = false;
                    for (let k = 0; k < entries.length; k++) {
                        if (entries[k] === new_file) {
                            // Check if file exists and is up to date
                            found = isFileUpToDate(entries[k], file_updated);
                        }
                    }

                    if (!found) {
                        // Download and write file
                        const downloadPath = path.resolve(image_folder); // Ensure this directory exists or create it
                        if (fs.existsSync(downloadPath)) {
                            console.log('Directory found: ', downloadPath);
                            try {
                                console.log('Download file: ', file_url);
                                await wgetFile(file_url, new_file);
                            } catch (e) {
                                console.log('Error downloading file: ', e);
                            }
                        } else {
                            console.log('Directory not found: ', downloadPath);
                        }
                    }
                }
            }
        }
    }

    // console.log('Valid Files: ', valid_files);
    // Delete images that aren't supposed to be there
    // TODO - need to search all directories for stubs and delete not-valid
    // TODO - this won't work - need to find deleted files with separate query
    for (let i = 0; i < directory_files.length; i++) {
        // console.log('Validating file: ', directory_files[i]);
        if (valid_files.indexOf(directory_files[i]) === -1) {
            // Delete this file
            // console.log('Deleting file: ', directory_files[i]);
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
    try {
        const page = await browser.newPage();
        page.on('console', message =>
            console.log(`${message.type().substr(0, 3).toUpperCase()} ${message.text()}`)
        );

        // Allows you to intercept a request; must appear before
        // your first page.goto()
        await page.setRequestInterception(true);

        // Use bearer token if it is set.
        if (token !== '') {
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
                headers: {...interceptedRequest.headers(), "content-type": "application/json"}
            };

            if (post_data !== '') {
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


async function findFieldByTemplateUUID (fields, target_uuid){
    if(fields === undefined) return undefined;
    for(let i = 0; i < fields.length; i++) {
        let child_field = fields[i]

        let key = Object.keys(child_field)[0];
        // console.log('Child Field: ', child_field[key].template_field_uuid);

        if (child_field[key].template_field_uuid === target_uuid) {
            return child_field[key];
        }
    }
}

async function findRecordByTemplateUUID (records, target_uuid){
    // console.log('Find Record By Template UUID: ', target_uuid);
    if(records === undefined) {
        // console.log('Records undefined');
        return undefined;
    }
    for(let i = 0; i < records.length; i++) {
        // console.log('Record Finder UUID: ' + records[i].template_uuid + ' ' + target_uuid)
        if(records[i].template_uuid === target_uuid) {
            // console.log('Found UUID: ', records[i].template_uuid)
            return records[i];
        }
        else if(records[i]['records_' + records[i].template_uuid] !== undefined) {
            let record = await findRecordByTemplateUUID(records[i]['records_' + records[i].template_uuid], target_uuid);
            if(record !== undefined) {
                return record;
            }
        }
    }
    return undefined
}


app();
