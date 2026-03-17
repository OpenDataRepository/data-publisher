/* jshint esversion: 8 */

/**
 * HOM URL Checker
 *
 * Matches minerals from mineral_data.js against the Handbook of Mineralogy
 * PDF catalog (fetched from https://handbookofmineralogy.org/pdf-search/).
 *
 * Two-pass matching:
 *   1. Direct match: mineral display name (lowercased, HTML-stripped) matches
 *      a catalog entry exactly.
 *   2. Variant match: normalized name variants (stripped diacritics, removed
 *      suffixes, removed hyphens, etc.) match a catalog entry. These are
 *      flagged with "non-direct-match": true.
 *
 * Outputs (in hom_results/):
 *   valid_hom_urls.json     - all matched minerals (direct + variant)
 *   invalid_hom_urls.json   - minerals with no catalog match
 *   resolved_hom_urls.json  - only the variant matches (with strategy details)
 *   unresolved_hom_urls.json - same as invalid (no match at all)
 *   hom_pdf_catalog.json    - cached HOM catalog (refreshed if older than 24h)
 *   run_log.json            - timestamp and summary of last run
 *
 * Usage: node hom_url_checker.js [--force-refresh] [--help]
 *
 * Designed to run weekly. Only makes a single HTTP request to fetch the
 * catalog page; all matching is done locally.
 */

const https = require('https');
const fs = require('fs');
const path = require('path');

// -------------------------------------------------------
// --help
// -------------------------------------------------------
if (process.argv.includes('--help') || process.argv.includes('-h')) {
    console.log(`
HOM URL Checker

  Matches minerals from mineral_data.js against the Handbook of Mineralogy
  PDF catalog and produces JSON files mapping mineral names to PDF URLs.

Usage:
  node hom_url_checker.js [options]

Options:
  --force-refresh   Re-fetch the HOM PDF catalog from the web even if the
                    local cache (hom_pdf_catalog.json) is less than 24 hours old.
  --help, -h        Show this help message and exit.

Requirements:
  - mineral_data.js must exist at ../web/uploads/IMA/mineral_data.js
    (relative to this script). This file is produced by the IMA data builder.
  - Network access to https://handbookofmineralogy.org/pdf-search/ for
    fetching the PDF catalog (only on first run or when cache expires).

Matching:
  Two-pass matching is performed against the HOM catalog:
    1. Direct match   - mineral display name (lowercased, HTML-stripped)
                        matches a catalog entry exactly.
    2. Variant match  - normalized name variants (stripped diacritics,
                        removed suffixes/hyphens, etc.) match a catalog
                        entry. These are flagged with "non-direct-match": true.

Output files (in hom_results/):
  valid_hom_urls.json      All matched minerals (direct + variant)
  invalid_hom_urls.json    Minerals with no catalog match
  resolved_hom_urls.json   Only the variant matches (with strategy details)
  unresolved_hom_urls.json Same as invalid (no match at all)
  hom_pdf_catalog.json     Cached HOM catalog (refreshed if older than 24h)
  run_log.json             Timestamp and summary of last run

  valid_hom_urls.json is also copied to ../web/uploads/IMA/ for access
  by other programs.

Designed to run weekly via cron or manually as needed.
`);
    process.exit(0);
}

const BASE_PATH = path.resolve(__dirname, '..');
const MINERAL_DATA_PATH = path.join(BASE_PATH, 'web/uploads/IMA/mineral_data.js');
const RESULTS_DIR = path.join(__dirname, 'hom_results');
const VALID_FILE = path.join(RESULTS_DIR, 'valid_hom_urls.json');
const IMA_UPLOAD_DIR = path.join(BASE_PATH, 'web/uploads/IMA');
const VALID_FILE_COPY = path.join(IMA_UPLOAD_DIR, 'valid_hom_urls.json');
const INVALID_FILE = path.join(RESULTS_DIR, 'invalid_hom_urls.json');
const RESOLVED_FILE = path.join(RESULTS_DIR, 'resolved_hom_urls.json');
const UNRESOLVED_FILE = path.join(RESULTS_DIR, 'unresolved_hom_urls.json');
const CATALOG_FILE = path.join(RESULTS_DIR, 'hom_pdf_catalog.json');
const RUN_LOG_FILE = path.join(RESULTS_DIR, 'run_log.json');

const CATALOG_URL = 'https://handbookofmineralogy.org/pdf-search/';
const HOM_BASE_URL = 'https://www.handbookofmineralogy.org/pdfs/';

// -------------------------------------------------------
// Utility functions
// -------------------------------------------------------

/**
 * Strip diacritical marks from a string.
 * e.g., "achávalite" -> "achavalite"
 */
function stripDiacritics(str) {
    return str.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
}

/**
 * Normalize a mineral name for comparison:
 * lowercase, strip diacritics, strip HTML tags, remove apostrophes
 */
function normalizeName(name) {
    let n = name.toLowerCase();
    n = n.replace(/<([^>]+)>/g, '');
    n = stripDiacritics(n);
    n = n.replace(/'/g, '');
    return n.trim();
}

/**
 * Generate multiple comparison keys for a mineral name.
 * Returns an array of normalized variant strings.
 */
function generateKeys(name) {
    const base = normalizeName(name);
    const keys = [base];

    // Remove element suffix: "abenakiite-(ce)" -> "abenakiite"
    const noSuffix = base.replace(/\s*-\s*\([^)]*\)\s*$/, '');
    if (noSuffix !== base) keys.push(noSuffix);

    // Remove all hyphens: "aegirine-augite" -> "aegirineaugite"
    const noHyphens = base.replace(/-/g, '');
    if (noHyphens !== base) keys.push(noHyphens);

    // Remove suffix then hyphens
    const noSuffixNoHyphens = noSuffix.replace(/-/g, '');
    if (noSuffixNoHyphens !== base && noSuffixNoHyphens !== noSuffix && noSuffixNoHyphens !== noHyphens) {
        keys.push(noSuffixNoHyphens);
    }

    // Remove all parenthetical content
    const noParens = base.replace(/\([^)]*\)/g, '').replace(/\s+/g, ' ').replace(/-$/, '').trim();
    if (noParens !== base && keys.indexOf(noParens) === -1) keys.push(noParens);

    // Remove spaces
    const noSpaces = base.replace(/\s+/g, '');
    if (noSpaces !== base && keys.indexOf(noSpaces) === -1) keys.push(noSpaces);

    return keys;
}

// -------------------------------------------------------
// Parse mineral_data.js
// -------------------------------------------------------
function parseMineralData(filePath) {
    const content = fs.readFileSync(filePath, 'utf8');
    const minerals = [];

    const byNameRegex = /minerals_by_name\[\d+\]\s*=\s*\{\s*name\s*:\s*"([^"]+)"\s*,\s*id\s*:\s*"([^"]+)"\s*\}/g;
    const dataArrayRegex = /mineral_data_array\['([^']+)'\]\s*=\s*'([^']*)'/g;
    const dataMap = {};
    let dataMatch;
    while ((dataMatch = dataArrayRegex.exec(content)) !== null) {
        dataMap[dataMatch[1]] = dataMatch[2];
    }

    let match;
    while ((match = byNameRegex.exec(content)) !== null) {
        const name = match[1];
        const id = match[2];
        const dataStr = dataMap[id];

        if (dataStr) {
            const fields = dataStr.split('||');
            const displayName = fields[1] || name;

            // Same logic as displayHOM() in mineral_list.js
            let homName = displayName.toLowerCase();
            homName = homName.replace(/<([^>]+)>/g, '');
            homName = homName.replace(/'/g, '');

            const url = HOM_BASE_URL + homName + '.pdf';
            minerals.push({ name, id, displayName, homName, url });
        }
    }
    return minerals;
}

// -------------------------------------------------------
// Fetch the HOM PDF catalog
// -------------------------------------------------------

function fetchPage(url) {
    return new Promise((resolve, reject) => {
        https.get(url, (res) => {
            if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
                return fetchPage(res.headers.location).then(resolve).catch(reject);
            }
            let data = '';
            res.on('data', chunk => data += chunk);
            res.on('end', () => resolve(data));
        }).on('error', reject);
    });
}

async function fetchCatalog(forceRefresh) {
    if (!forceRefresh && fs.existsSync(CATALOG_FILE)) {
        const stats = fs.statSync(CATALOG_FILE);
        const ageHours = (Date.now() - stats.mtimeMs) / (1000 * 60 * 60);
        if (ageHours < 24) {
            console.log('Using cached catalog (age: ' + ageHours.toFixed(1) + ' hours)');
            return JSON.parse(fs.readFileSync(CATALOG_FILE, 'utf8'));
        }
    }

    console.log('Fetching PDF catalog from ' + CATALOG_URL + '...');
    const html = await fetchPage(CATALOG_URL);

    // Parse <li><a href='...'>name</a></li> entries from the pdf-list UL
    const regex = /<li><a href='([^']*)' target='_blank'>\s*([^<]*)<\/a><\/li>/g;
    const entries = [];
    let match;
    while ((match = regex.exec(html)) !== null) {
        entries.push({
            url: match[1].trim(),
            name: match[2].trim()
        });
    }

    if (entries.length === 0) {
        throw new Error('Failed to parse any PDF entries from the catalog page. The page structure may have changed.');
    }

    console.log('Fetched ' + entries.length + ' PDF entries from catalog');
    fs.writeFileSync(CATALOG_FILE, JSON.stringify(entries, null, 2));
    return entries;
}

// -------------------------------------------------------
// Matching
// -------------------------------------------------------

function buildCatalogIndex(catalog) {
    const exactMap = {};
    for (const entry of catalog) {
        const normalized = normalizeName(entry.name);
        exactMap[normalized] = entry;
    }
    return exactMap;
}

// -------------------------------------------------------
// Main
// -------------------------------------------------------

async function main() {
    const forceRefresh = process.argv.includes('--force-refresh');

    console.log('HOM URL Checker - Starting');
    console.log('');

    // Ensure results directory exists
    if (!fs.existsSync(RESULTS_DIR)) {
        fs.mkdirSync(RESULTS_DIR, { recursive: true });
    }

    // Step 1: Parse mineral data
    console.log('Reading mineral data from: ' + MINERAL_DATA_PATH);
    const minerals = parseMineralData(MINERAL_DATA_PATH);
    console.log('Found ' + minerals.length + ' minerals');

    // Step 2: Fetch/load catalog
    const catalog = await fetchCatalog(forceRefresh);
    console.log('Catalog size: ' + catalog.length + ' PDFs');
    console.log('');

    // Build lookup indexes
    const catalogIndex = buildCatalogIndex(catalog);  // normalizeName -> entry
    const catalogByLower = {};  // raw lowercase name -> entry
    for (const entry of catalog) {
        catalogByLower[entry.name.trim().toLowerCase()] = entry;
    }

    // Step 3: Match all minerals
    const validResults = [];
    const invalidResults = [];
    const resolvedResults = [];

    for (const mineral of minerals) {
        // Pass 1: Direct match (raw homName matches catalog name exactly)
        if (catalogByLower[mineral.homName]) {
            const entry = catalogByLower[mineral.homName];
            validResults.push({
                id: mineral.id,
                name: mineral.name,
                displayName: mineral.displayName,
                homName: mineral.homName,
                url: entry.url
            });
            continue;
        }

        // Pass 2: Variant match using normalized keys
        const keys = generateKeys(mineral.homName);
        let matched = false;

        for (const key of keys) {
            if (catalogIndex[key]) {
                const entry = catalogIndex[key];
                validResults.push({
                    id: mineral.id,
                    name: mineral.name,
                    displayName: mineral.displayName,
                    homName: entry.name,
                    url: entry.url,
                    'non-direct-match': true
                });
                resolvedResults.push({
                    id: mineral.id,
                    name: mineral.name,
                    displayName: mineral.displayName,
                    originalHomName: mineral.homName,
                    originalUrl: mineral.url,
                    matchedName: entry.name,
                    matchedUrl: entry.url,
                    strategy: 'exact_variant (' + key + ')'
                });
                matched = true;
                break;
            }
        }

        if (!matched) {
            invalidResults.push({
                id: mineral.id,
                name: mineral.name,
                displayName: mineral.displayName,
                homName: mineral.homName,
                url: mineral.url
            });
        }
    }

    // Step 4: Save results
    fs.writeFileSync(VALID_FILE, JSON.stringify(validResults, null, 2));
    fs.writeFileSync(INVALID_FILE, JSON.stringify(invalidResults, null, 2));
    fs.writeFileSync(RESOLVED_FILE, JSON.stringify(resolvedResults, null, 2));
    fs.writeFileSync(UNRESOLVED_FILE, JSON.stringify(invalidResults, null, 2));

    const runLog = {
        timestamp: new Date().toISOString(),
        totalMinerals: minerals.length,
        catalogSize: catalog.length,
        directMatches: validResults.filter(v => !v['non-direct-match']).length,
        variantMatches: resolvedResults.length,
        totalMatched: validResults.length,
        unmatched: invalidResults.length
    };
    fs.writeFileSync(RUN_LOG_FILE, JSON.stringify(runLog, null, 2));

    // Copy valid_hom_urls.json to web/uploads/IMA/ for access by other programs
    fs.copyFileSync(VALID_FILE, VALID_FILE_COPY);

    // Summary
    console.log('=== Results ===');
    console.log('Total minerals:    ' + minerals.length);
    console.log('HOM catalog size:  ' + catalog.length);
    console.log('Direct matches:    ' + runLog.directMatches);
    console.log('Variant matches:   ' + runLog.variantMatches + ' (non-direct-match)');
    console.log('Total matched:     ' + runLog.totalMatched + ' (' +
        (100 * runLog.totalMatched / minerals.length).toFixed(1) + '%)');
    console.log('Unmatched:         ' + runLog.unmatched);
    console.log('');
    console.log('Output files:');
    console.log('  ' + VALID_FILE);
    console.log('  ' + INVALID_FILE);
    console.log('  ' + RESOLVED_FILE);
    console.log('  ' + UNRESOLVED_FILE);
    console.log('  ' + CATALOG_FILE);
    console.log('  ' + RUN_LOG_FILE);
}

main().catch(err => {
    console.error('Fatal error:', err);
    process.exit(1);
});
