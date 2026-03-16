/* jshint esversion: 8 */

/**
 * HOM URL Checker
 *
 * Reads mineral_data.js to get a list of mineral names, then checks if the
 * Handbook of Mineralogy (HOM) website has a valid PDF entry for each mineral.
 *
 * Makes no more than 1 request per second to avoid overloading the HOM server.
 *
 * Outputs:
 *   hom_results/valid_hom_urls.json   - minerals with valid HOM PDFs
 *   hom_results/invalid_hom_urls.json - minerals without valid HOM PDFs
 *   hom_results/progress.json         - tracks progress for resume support
 *
 * Usage: node hom_url_checker.js
 */

const https = require('https');
const http = require('http');
const fs = require('fs');
const path = require('path');

const BASE_PATH = path.resolve(__dirname, '..');
const MINERAL_DATA_PATH = path.join(BASE_PATH, 'web/uploads/IMA/mineral_data.js');
const RESULTS_DIR = path.join(__dirname, 'hom_results');
const VALID_FILE = path.join(RESULTS_DIR, 'valid_hom_urls.json');
const INVALID_FILE = path.join(RESULTS_DIR, 'invalid_hom_urls.json');
const PROGRESS_FILE = path.join(RESULTS_DIR, 'progress.json');

const HOM_BASE_URL = 'https://www.handbookofmineralogy.org/pdfs/';
const REQUEST_DELAY_MS = 1000; // 1 request per second

function delay(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

/**
 * Parse mineral_data.js and extract mineral names and display names.
 * Returns an array of { name, displayName, url } objects.
 */
function parseMineralData(filePath) {
    const content = fs.readFileSync(filePath, 'utf8');
    const minerals = [];

    // Match each minerals_by_name entry to get the mineral name
    // Then extract the mineral_data_array entry to get the display name (field index 1)
    const byNameRegex = /minerals_by_name\[\d+\]\s*=\s*\{\s*name\s*:\s*"([^"]+)"\s*,\s*id\s*:\s*"([^"]+)"\s*\}/g;
    let match;

    // Build a map of id -> data string
    const dataArrayRegex = /mineral_data_array\['([^']+)'\]\s*=\s*'([^']*)'/g;
    const dataMap = {};
    let dataMatch;
    while ((dataMatch = dataArrayRegex.exec(content)) !== null) {
        dataMap[dataMatch[1]] = dataMatch[2];
    }

    while ((match = byNameRegex.exec(content)) !== null) {
        const name = match[1];
        const id = match[2];
        const dataStr = dataMap[id];

        if (dataStr) {
            const fields = dataStr.split('||');
            // Field 1 is the display name (may contain HTML)
            const displayName = fields[1] || name;

            // Construct HOM URL using the same logic as displayHOM() in mineral_list.js:
            // lowercase, strip HTML tags, strip apostrophes
            let homName = displayName.toLowerCase();
            homName = homName.replace(/<([^>]+)>/g, '');
            homName = homName.replace(/'/g, '');

            const url = HOM_BASE_URL + homName + '.pdf';
            minerals.push({ name, displayName, homName, url });
        }
    }

    return minerals;
}

/**
 * Check if a URL returns a valid PDF (not a 404).
 * Uses HEAD request first; returns { valid, status, contentType }.
 */
function checkUrl(url) {
    return new Promise((resolve) => {
        const proto = url.startsWith('https') ? https : http;
        const req = proto.request(url, { method: 'HEAD', timeout: 15000 }, (res) => {
            resolve({
                valid: res.statusCode === 200,
                status: res.statusCode,
                contentType: res.headers['content-type'] || ''
            });
        });

        req.on('error', (err) => {
            resolve({ valid: false, status: 0, contentType: '', error: err.message });
        });

        req.on('timeout', () => {
            req.destroy();
            resolve({ valid: false, status: 0, contentType: '', error: 'timeout' });
        });

        req.end();
    });
}

/**
 * Load previous progress for resume support.
 */
function loadProgress() {
    let validResults = [];
    let invalidResults = [];
    let checkedNames = new Set();

    if (fs.existsSync(PROGRESS_FILE)) {
        try {
            const progress = JSON.parse(fs.readFileSync(PROGRESS_FILE, 'utf8'));
            checkedNames = new Set(progress.checkedNames || []);
        } catch (e) {
            console.log('Could not read progress file, starting fresh.');
        }
    }

    if (fs.existsSync(VALID_FILE)) {
        try {
            validResults = JSON.parse(fs.readFileSync(VALID_FILE, 'utf8'));
        } catch (e) {
            validResults = [];
        }
    }

    if (fs.existsSync(INVALID_FILE)) {
        try {
            invalidResults = JSON.parse(fs.readFileSync(INVALID_FILE, 'utf8'));
        } catch (e) {
            invalidResults = [];
        }
    }

    return { validResults, invalidResults, checkedNames };
}

/**
 * Save current progress.
 */
function saveProgress(validResults, invalidResults, checkedNames) {
    fs.writeFileSync(VALID_FILE, JSON.stringify(validResults, null, 2));
    fs.writeFileSync(INVALID_FILE, JSON.stringify(invalidResults, null, 2));
    fs.writeFileSync(PROGRESS_FILE, JSON.stringify({
        checkedNames: Array.from(checkedNames),
        lastUpdated: new Date().toISOString()
    }));
}

async function main() {
    console.log('HOM URL Checker - Starting');
    console.log('Reading mineral data from:', MINERAL_DATA_PATH);

    // Parse minerals
    const minerals = parseMineralData(MINERAL_DATA_PATH);
    console.log('Found ' + minerals.length + ' minerals');

    // Ensure results directory exists
    if (!fs.existsSync(RESULTS_DIR)) {
        fs.mkdirSync(RESULTS_DIR, { recursive: true });
    }

    // Load previous progress
    let { validResults, invalidResults, checkedNames } = loadProgress();
    const alreadyChecked = checkedNames.size;
    if (alreadyChecked > 0) {
        console.log('Resuming: ' + alreadyChecked + ' minerals already checked (' +
            validResults.length + ' valid, ' + invalidResults.length + ' invalid)');
    }

    // Filter to unchecked minerals
    const toCheck = minerals.filter(m => !checkedNames.has(m.name));
    console.log('Checking ' + toCheck.length + ' remaining minerals...');
    console.log('Rate limit: 1 request per second');
    console.log('');

    let count = 0;
    const total = toCheck.length;
    const saveInterval = 50; // Save progress every 50 minerals

    for (const mineral of toCheck) {
        count++;
        const result = await checkUrl(mineral.url);

        if (result.valid) {
            validResults.push({
                name: mineral.name,
                displayName: mineral.displayName,
                homName: mineral.homName,
                url: mineral.url
            });
            console.log('[' + count + '/' + total + '] VALID: ' + mineral.name + ' -> ' + mineral.url);
        } else {
            invalidResults.push({
                name: mineral.name,
                displayName: mineral.displayName,
                homName: mineral.homName,
                url: mineral.url,
                status: result.status,
                error: result.error || null
            });
            console.log('[' + count + '/' + total + '] INVALID (' + result.status + '): ' + mineral.name);
        }

        checkedNames.add(mineral.name);

        // Save progress periodically
        if (count % saveInterval === 0) {
            saveProgress(validResults, invalidResults, checkedNames);
            console.log('  -- Progress saved (' + (alreadyChecked + count) + '/' + minerals.length + ' total) --');
        }

        // Rate limiting - wait before next request
        if (count < total) {
            await delay(REQUEST_DELAY_MS);
        }
    }

    // Final save
    saveProgress(validResults, invalidResults, checkedNames);

    console.log('');
    console.log('=== Complete ===');
    console.log('Total minerals: ' + minerals.length);
    console.log('Valid HOM URLs: ' + validResults.length);
    console.log('Invalid HOM URLs: ' + invalidResults.length);
    console.log('Results saved to: ' + RESULTS_DIR);
}

main().catch(err => {
    console.error('Fatal error:', err);
    process.exit(1);
});
