/**
 * ODR Statistics Dashboard
 * Uses Plotly.js for visualizations
 */

console.log('ODRStatisticsDashboard: Module loading...');

window.ODRStatisticsDashboard = (function() {
    'use strict';

    console.log('ODRStatisticsDashboard: IIFE executing');

    let datatypes = [];
    let apiBaseUrl = ''; // Base URL for API calls (e.g., //beta.rruff.net/odr)
    let currentRange = 30; // Default 30 days
    let selectedDatatypes = [];
    let includeBots = false;
    let startDate = null;
    let endDate = null;

    /**
     * Initialize the dashboard
     * @param {Array} datatypesData - Array of datatype objects
     * @param {string} baseUrl - Base URL for API calls (e.g., //beta.rruff.net/odr)
     */
    function init(datatypesData, baseUrl) {
        console.log('ODRStatisticsDashboard.init() called');
        console.log('  datatypesData:', datatypesData);
        console.log('  baseUrl:', baseUrl);

        datatypes = datatypesData || [];
        apiBaseUrl = baseUrl || '';

        console.log('  Calling setupEventListeners()');
        setupEventListeners();

        console.log('  Calling setDefaultDates()');
        setDefaultDates();

        console.log('  Calling loadDashboardData()');
        loadDashboardData();

        console.log('ODRStatisticsDashboard.init() complete');
    }

    /**
     * Set default date range (last 30 days)
     */
    function setDefaultDates() {
        const now = new Date();
        endDate = formatDate(now);

        const start = new Date();
        start.setDate(start.getDate() - currentRange);
        startDate = formatDate(start);

        document.getElementById('start-date').value = startDate;
        document.getElementById('end-date').value = endDate;
    }

    /**
     * Format date as YYYY-MM-DD
     */
    function formatDate(date) {
        return date.toISOString().split('T')[0];
    }

    /**
     * Setup event listeners
     */
    function setupEventListeners() {
        console.log('ODRStatisticsDashboard: Setting up event listeners');

        // Quick range buttons
        const rangeBtns = document.querySelectorAll('.range-btn');
        console.log('  Found', rangeBtns.length, '.range-btn elements');
        rangeBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.range-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');

                const range = this.getAttribute('data-range');

                if (range === 'custom') {
                    document.getElementById('custom-date-range').style.display = 'flex';
                    document.getElementById('custom-date-range-end').style.display = 'flex';
                } else {
                    document.getElementById('custom-date-range').style.display = 'none';
                    document.getElementById('custom-date-range-end').style.display = 'none';

                    currentRange = parseInt(range);
                    setDefaultDates();
                    loadDashboardData();
                }
            });
        });

        // Custom date inputs
        const startDateElem = document.getElementById('start-date');
        console.log('  start-date element:', startDateElem ? 'FOUND' : 'NOT FOUND');
        if (startDateElem) {
            startDateElem.addEventListener('change', function() {
            startDate = this.value;
                if (document.querySelector('.range-btn[data-range="custom"]').classList.contains('active')) {
                    loadDashboardData();
                }
            });
        }

        const endDateElem = document.getElementById('end-date');
        console.log('  end-date element:', endDateElem ? 'FOUND' : 'NOT FOUND');
        if (endDateElem) {
            endDateElem.addEventListener('change', function() {
            endDate = this.value;
                if (document.querySelector('.range-btn[data-range="custom"]').classList.contains('active')) {
                    loadDashboardData();
                }
            });
        }

        // Datatype filter
        const datatypeFilterElem = document.getElementById('datatype-filter');
        console.log('  datatype-filter element:', datatypeFilterElem ? 'FOUND' : 'NOT FOUND');
        if (datatypeFilterElem) {
            datatypeFilterElem.addEventListener('change', function() {
            const options = this.selectedOptions;
            const allOption = this.querySelector('option[value=""]');

            // Check if "All Data Types" was just selected
            let allSelected = false;
            for (let i = 0; i < options.length; i++) {
                if (options[i].value === '') {
                    allSelected = true;
                    break;
                }
            }

            if (allSelected) {
                // If "All" is selected, deselect everything else and keep only "All"
                for (let i = 0; i < this.options.length; i++) {
                    this.options[i].selected = (this.options[i].value === '');
                }
                selectedDatatypes = [];
            } else {
                // Deselect "All" if specific datatypes are selected
                if (allOption) {
                    allOption.selected = false;
                }
                selectedDatatypes = [];
                for (let i = 0; i < options.length; i++) {
                    if (options[i].value !== '') {
                        selectedDatatypes.push(parseInt(options[i].value));
                    }
                }

                // If nothing is selected, select "All" by default
                if (selectedDatatypes.length === 0 && allOption) {
                    allOption.selected = true;
                }
            }

            loadDashboardData();
            });
        }

        // Bot traffic checkbox
        const includeBotsElem = document.getElementById('include-bots');
        console.log('  include-bots element:', includeBotsElem ? 'FOUND' : 'NOT FOUND');
        if (includeBotsElem) {
            includeBotsElem.addEventListener('change', function() {
                includeBots = this.checked;
                loadDashboardData();
            });
        }

        // Refresh button
        const refreshBtnElem = document.getElementById('refresh-btn');
        console.log('  refresh-btn element:', refreshBtnElem ? 'FOUND' : 'NOT FOUND');
        if (refreshBtnElem) {
            refreshBtnElem.addEventListener('click', function() {
                loadDashboardData();
            });
        }

        console.log('ODRStatisticsDashboard: Event listeners setup complete');
    }

    /**
     * Load all dashboard data
     */
    async function loadDashboardData() {
        console.log('ODRStatisticsDashboard: loadDashboardData() called');
        console.log('  Date range:', startDate, 'to', endDate);
        console.log('  Include bots:', includeBots);
        console.log('  Selected datatypes:', selectedDatatypes);

        try {
            showLoading();

            // Fetch summary data
            console.log('  Fetching summary data...');
            const summaryData = await fetchSummaryData();
            console.log('  Summary data received:', summaryData);

            console.log('  Updating summary cards...');
            updateSummaryCards(summaryData);

            // Render charts
            console.log('  Rendering timeline chart...');
            renderTimelineChart(summaryData);

            console.log('  Rendering geographic chart...');
            renderGeographicChart(summaryData);

            console.log('  Rendering traffic source chart...');
            renderTrafficSourceChart(summaryData);

            console.log('  Rendering datatype chart...');
            renderDatatypeChart(summaryData);

            hideLoading();
            console.log('ODRStatisticsDashboard: loadDashboardData() complete');
        } catch (error) {
            console.error('ODRStatisticsDashboard ERROR loading dashboard data:', error);
            console.error('  Error stack:', error.stack);
            showError('Failed to load dashboard data. Please try again.');
            hideLoading();
        }
    }

    /**
     * Fetch summary data from API
     */
    async function fetchSummaryData() {
        const params = new URLSearchParams({
            start_date: startDate,
            end_date: endDate,
            include_bots: includeBots ? '1' : '0'
        });

        if (selectedDatatypes.length > 0) {
            params.append('datatype_ids', selectedDatatypes.join(','));
        }

        // Use apiBaseUrl for the fetch URL (e.g., //beta.rruff.net/odr/statistics/summary)
        const url = apiBaseUrl + '/statistics/summary?' + params.toString();
        console.log('ODRStatisticsDashboard: Fetching from URL:', url);

        const response = await fetch(url);
        console.log('ODRStatisticsDashboard: Response status:', response.status, response.statusText);

        if (!response.ok) {
            throw new Error('API request failed: ' + response.status + ' ' + response.statusText);
        }

        const data = await response.json();
        console.log('ODRStatisticsDashboard: Response data keys:', Object.keys(data));
        return data;
    }

    /**
     * Update summary cards
     */
    function updateSummaryCards(data) {
        document.getElementById('total-views').textContent = formatNumber(data.total_views || 0);
        document.getElementById('total-downloads').textContent = formatNumber(data.total_downloads || 0);
        document.getElementById('search-views').textContent = formatNumber(data.search_result_views || 0);
        document.getElementById('unique-countries').textContent = data.unique_countries || 0;
    }

    /**
     * Render timeline chart
     */
    function renderTimelineChart(data) {
        if (!data.timeline || data.timeline.length === 0) {
            document.getElementById('timeline-chart').innerHTML = '<p style="text-align:center;color:#666;">No data available</p>';
            return;
        }

        const dates = data.timeline.map(d => d.date);
        const views = data.timeline.map(d => d.view_count);
        const downloads = data.timeline.map(d => d.download_count);

        const traces = [
            {
                x: dates,
                y: views,
                name: 'Views',
                type: 'scatter',
                mode: 'lines+markers',
                line: { color: '#2E86DE', width: 3 },
                marker: { size: 6 }
            },
            {
                x: dates,
                y: downloads,
                name: 'Downloads',
                type: 'scatter',
                mode: 'lines+markers',
                line: { color: '#10AC84', width: 3 },
                marker: { size: 6 }
            }
        ];

        const layout = {
            margin: { t: 10, r: 10, b: 50, l: 60 },
            xaxis: {
                title: 'Date',
                showgrid: true,
                gridcolor: '#f0f0f0'
            },
            yaxis: {
                title: 'Count',
                showgrid: true,
                gridcolor: '#f0f0f0'
            },
            hovermode: 'x unified',
            showlegend: true,
            legend: {
                orientation: 'h',
                y: -0.2
            }
        };

        Plotly.newPlot('timeline-chart', traces, layout, {responsive: true});
    }

    /**
     * Map country names/codes to ISO 3166-1 alpha-3 codes for Plotly choropleth
     * Includes common variations, 2-letter codes, and alternate names
     */
    function getCountryCode(countryName) {
        if (!countryName) return null;

        // Normalize input - trim and handle case
        var normalized = countryName.trim();

        var countryCodes = {
            // A
            'AF': 'AFG', 'AFG': 'AFG', 'Afghanistan': 'AFG',
            'AL': 'ALB', 'ALB': 'ALB', 'Albania': 'ALB',
            'DZ': 'DZA', 'DZA': 'DZA', 'Algeria': 'DZA',
            'AD': 'AND', 'AND': 'AND', 'Andorra': 'AND',
            'AO': 'AGO', 'AGO': 'AGO', 'Angola': 'AGO',
            'AR': 'ARG', 'ARG': 'ARG', 'Argentina': 'ARG',
            'AM': 'ARM', 'ARM': 'ARM', 'Armenia': 'ARM',
            'AU': 'AUS', 'AUS': 'AUS', 'Australia': 'AUS',
            'AT': 'AUT', 'AUT': 'AUT', 'Austria': 'AUT',
            'AZ': 'AZE', 'AZE': 'AZE', 'Azerbaijan': 'AZE',
            // B
            'BS': 'BHS', 'BHS': 'BHS', 'Bahamas': 'BHS',
            'BH': 'BHR', 'BHR': 'BHR', 'Bahrain': 'BHR',
            'BD': 'BGD', 'BGD': 'BGD', 'Bangladesh': 'BGD',
            'BY': 'BLR', 'BLR': 'BLR', 'Belarus': 'BLR',
            'BE': 'BEL', 'BEL': 'BEL', 'Belgium': 'BEL',
            'BZ': 'BLZ', 'BLZ': 'BLZ', 'Belize': 'BLZ',
            'BJ': 'BEN', 'BEN': 'BEN', 'Benin': 'BEN',
            'BT': 'BTN', 'BTN': 'BTN', 'Bhutan': 'BTN',
            'BO': 'BOL', 'BOL': 'BOL', 'Bolivia': 'BOL', 'Bolivia, Plurinational State of': 'BOL',
            'BA': 'BIH', 'BIH': 'BIH', 'Bosnia and Herzegovina': 'BIH', 'Bosnia': 'BIH',
            'BW': 'BWA', 'BWA': 'BWA', 'Botswana': 'BWA',
            'BR': 'BRA', 'BRA': 'BRA', 'Brazil': 'BRA',
            'BN': 'BRN', 'BRN': 'BRN', 'Brunei': 'BRN', 'Brunei Darussalam': 'BRN',
            'BG': 'BGR', 'BGR': 'BGR', 'Bulgaria': 'BGR',
            'BF': 'BFA', 'BFA': 'BFA', 'Burkina Faso': 'BFA',
            'BI': 'BDI', 'BDI': 'BDI', 'Burundi': 'BDI',
            // C
            'KH': 'KHM', 'KHM': 'KHM', 'Cambodia': 'KHM',
            'CM': 'CMR', 'CMR': 'CMR', 'Cameroon': 'CMR',
            'CA': 'CAN', 'CAN': 'CAN', 'Canada': 'CAN',
            'CF': 'CAF', 'CAF': 'CAF', 'Central African Republic': 'CAF',
            'TD': 'TCD', 'TCD': 'TCD', 'Chad': 'TCD',
            'CL': 'CHL', 'CHL': 'CHL', 'Chile': 'CHL',
            'CN': 'CHN', 'CHN': 'CHN', 'China': 'CHN',
            'CO': 'COL', 'COL': 'COL', 'Colombia': 'COL',
            'CG': 'COG', 'COG': 'COG', 'Congo': 'COG', 'Republic of the Congo': 'COG',
            'CD': 'COD', 'COD': 'COD', 'Democratic Republic of the Congo': 'COD', 'Congo, Democratic Republic': 'COD',
            'CR': 'CRI', 'CRI': 'CRI', 'Costa Rica': 'CRI',
            'HR': 'HRV', 'HRV': 'HRV', 'Croatia': 'HRV',
            'CU': 'CUB', 'CUB': 'CUB', 'Cuba': 'CUB',
            'CY': 'CYP', 'CYP': 'CYP', 'Cyprus': 'CYP',
            'CZ': 'CZE', 'CZE': 'CZE', 'Czech Republic': 'CZE', 'Czechia': 'CZE',
            'CI': 'CIV', 'CIV': 'CIV', 'Ivory Coast': 'CIV', "Cote d'Ivoire": 'CIV', "Côte d'Ivoire": 'CIV',
            // D
            'DK': 'DNK', 'DNK': 'DNK', 'Denmark': 'DNK',
            'DJ': 'DJI', 'DJI': 'DJI', 'Djibouti': 'DJI',
            'DO': 'DOM', 'DOM': 'DOM', 'Dominican Republic': 'DOM',
            // E
            'EC': 'ECU', 'ECU': 'ECU', 'Ecuador': 'ECU',
            'EG': 'EGY', 'EGY': 'EGY', 'Egypt': 'EGY',
            'SV': 'SLV', 'SLV': 'SLV', 'El Salvador': 'SLV',
            'EE': 'EST', 'EST': 'EST', 'Estonia': 'EST',
            'ET': 'ETH', 'ETH': 'ETH', 'Ethiopia': 'ETH',
            // F
            'FJ': 'FJI', 'FJI': 'FJI', 'Fiji': 'FJI',
            'FI': 'FIN', 'FIN': 'FIN', 'Finland': 'FIN',
            'FR': 'FRA', 'FRA': 'FRA', 'France': 'FRA',
            // G
            'GA': 'GAB', 'GAB': 'GAB', 'Gabon': 'GAB',
            'GM': 'GMB', 'GMB': 'GMB', 'Gambia': 'GMB',
            'GE': 'GEO', 'GEO': 'GEO', 'Georgia': 'GEO',
            'DE': 'DEU', 'DEU': 'DEU', 'Germany': 'DEU',
            'GH': 'GHA', 'GHA': 'GHA', 'Ghana': 'GHA',
            'GR': 'GRC', 'GRC': 'GRC', 'Greece': 'GRC',
            'GT': 'GTM', 'GTM': 'GTM', 'Guatemala': 'GTM',
            'GN': 'GIN', 'GIN': 'GIN', 'Guinea': 'GIN',
            // H
            'HT': 'HTI', 'HTI': 'HTI', 'Haiti': 'HTI',
            'HN': 'HND', 'HND': 'HND', 'Honduras': 'HND',
            'HK': 'HKG', 'HKG': 'HKG', 'Hong Kong': 'HKG',
            'HU': 'HUN', 'HUN': 'HUN', 'Hungary': 'HUN',
            // I
            'IS': 'ISL', 'ISL': 'ISL', 'Iceland': 'ISL',
            'IN': 'IND', 'IND': 'IND', 'India': 'IND',
            'ID': 'IDN', 'IDN': 'IDN', 'Indonesia': 'IDN',
            'IR': 'IRN', 'IRN': 'IRN', 'Iran': 'IRN', 'Iran, Islamic Republic of': 'IRN',
            'IQ': 'IRQ', 'IRQ': 'IRQ', 'Iraq': 'IRQ',
            'IE': 'IRL', 'IRL': 'IRL', 'Ireland': 'IRL',
            'IL': 'ISR', 'ISR': 'ISR', 'Israel': 'ISR',
            'IT': 'ITA', 'ITA': 'ITA', 'Italy': 'ITA',
            // J
            'JM': 'JAM', 'JAM': 'JAM', 'Jamaica': 'JAM',
            'JP': 'JPN', 'JPN': 'JPN', 'Japan': 'JPN',
            'JO': 'JOR', 'JOR': 'JOR', 'Jordan': 'JOR',
            // K
            'KZ': 'KAZ', 'KAZ': 'KAZ', 'Kazakhstan': 'KAZ',
            'KE': 'KEN', 'KEN': 'KEN', 'Kenya': 'KEN',
            'XK': 'XKX', 'XKX': 'XKX', 'Kosovo': 'XKX',
            'KW': 'KWT', 'KWT': 'KWT', 'Kuwait': 'KWT',
            'KG': 'KGZ', 'KGZ': 'KGZ', 'Kyrgyzstan': 'KGZ',
            'KR': 'KOR', 'KOR': 'KOR', 'South Korea': 'KOR', 'Korea': 'KOR', 'Korea, Republic of': 'KOR', 'Republic of Korea': 'KOR',
            'KP': 'PRK', 'PRK': 'PRK', 'North Korea': 'PRK', "Korea, Democratic People's Republic of": 'PRK',
            // L
            'LA': 'LAO', 'LAO': 'LAO', 'Laos': 'LAO', "Lao People's Democratic Republic": 'LAO',
            'LV': 'LVA', 'LVA': 'LVA', 'Latvia': 'LVA',
            'LB': 'LBN', 'LBN': 'LBN', 'Lebanon': 'LBN',
            'LR': 'LBR', 'LBR': 'LBR', 'Liberia': 'LBR',
            'LY': 'LBY', 'LBY': 'LBY', 'Libya': 'LBY',
            'LT': 'LTU', 'LTU': 'LTU', 'Lithuania': 'LTU',
            'LU': 'LUX', 'LUX': 'LUX', 'Luxembourg': 'LUX',
            // M
            'MK': 'MKD', 'MKD': 'MKD', 'Macedonia': 'MKD', 'North Macedonia': 'MKD', 'Republic of North Macedonia': 'MKD',
            'MG': 'MDG', 'MDG': 'MDG', 'Madagascar': 'MDG',
            'MW': 'MWI', 'MWI': 'MWI', 'Malawi': 'MWI',
            'MY': 'MYS', 'MYS': 'MYS', 'Malaysia': 'MYS',
            'ML': 'MLI', 'MLI': 'MLI', 'Mali': 'MLI',
            'MT': 'MLT', 'MLT': 'MLT', 'Malta': 'MLT',
            'MR': 'MRT', 'MRT': 'MRT', 'Mauritania': 'MRT',
            'MU': 'MUS', 'MUS': 'MUS', 'Mauritius': 'MUS',
            'MX': 'MEX', 'MEX': 'MEX', 'Mexico': 'MEX',
            'MD': 'MDA', 'MDA': 'MDA', 'Moldova': 'MDA', 'Moldova, Republic of': 'MDA',
            'MN': 'MNG', 'MNG': 'MNG', 'Mongolia': 'MNG',
            'ME': 'MNE', 'MNE': 'MNE', 'Montenegro': 'MNE',
            'MA': 'MAR', 'MAR': 'MAR', 'Morocco': 'MAR',
            'MZ': 'MOZ', 'MOZ': 'MOZ', 'Mozambique': 'MOZ',
            'MM': 'MMR', 'MMR': 'MMR', 'Myanmar': 'MMR', 'Burma': 'MMR',
            // N
            'NA': 'NAM', 'NAM': 'NAM', 'Namibia': 'NAM',
            'NP': 'NPL', 'NPL': 'NPL', 'Nepal': 'NPL',
            'NL': 'NLD', 'NLD': 'NLD', 'Netherlands': 'NLD', 'The Netherlands': 'NLD',
            'NZ': 'NZL', 'NZL': 'NZL', 'New Zealand': 'NZL',
            'NI': 'NIC', 'NIC': 'NIC', 'Nicaragua': 'NIC',
            'NE': 'NER', 'NER': 'NER', 'Niger': 'NER',
            'NG': 'NGA', 'NGA': 'NGA', 'Nigeria': 'NGA',
            'NO': 'NOR', 'NOR': 'NOR', 'Norway': 'NOR',
            // O
            'OM': 'OMN', 'OMN': 'OMN', 'Oman': 'OMN',
            // P
            'PK': 'PAK', 'PAK': 'PAK', 'Pakistan': 'PAK',
            'PS': 'PSE', 'PSE': 'PSE', 'Palestine': 'PSE', 'Palestinian Territory': 'PSE',
            'PA': 'PAN', 'PAN': 'PAN', 'Panama': 'PAN',
            'PG': 'PNG', 'PNG': 'PNG', 'Papua New Guinea': 'PNG',
            'PY': 'PRY', 'PRY': 'PRY', 'Paraguay': 'PRY',
            'PE': 'PER', 'PER': 'PER', 'Peru': 'PER',
            'PH': 'PHL', 'PHL': 'PHL', 'Philippines': 'PHL',
            'PL': 'POL', 'POL': 'POL', 'Poland': 'POL',
            'PT': 'PRT', 'PRT': 'PRT', 'Portugal': 'PRT',
            'PR': 'PRI', 'PRI': 'PRI', 'Puerto Rico': 'PRI',
            // Q
            'QA': 'QAT', 'QAT': 'QAT', 'Qatar': 'QAT',
            // R
            'RO': 'ROU', 'ROU': 'ROU', 'Romania': 'ROU',
            'RU': 'RUS', 'RUS': 'RUS', 'Russia': 'RUS', 'Russian Federation': 'RUS',
            'RW': 'RWA', 'RWA': 'RWA', 'Rwanda': 'RWA',
            // S
            'SA': 'SAU', 'SAU': 'SAU', 'Saudi Arabia': 'SAU',
            'SN': 'SEN', 'SEN': 'SEN', 'Senegal': 'SEN',
            'RS': 'SRB', 'SRB': 'SRB', 'Serbia': 'SRB',
            'SL': 'SLE', 'SLE': 'SLE', 'Sierra Leone': 'SLE',
            'SG': 'SGP', 'SGP': 'SGP', 'Singapore': 'SGP',
            'SK': 'SVK', 'SVK': 'SVK', 'Slovakia': 'SVK',
            'SI': 'SVN', 'SVN': 'SVN', 'Slovenia': 'SVN',
            'SO': 'SOM', 'SOM': 'SOM', 'Somalia': 'SOM',
            'ZA': 'ZAF', 'ZAF': 'ZAF', 'South Africa': 'ZAF',
            'ES': 'ESP', 'ESP': 'ESP', 'Spain': 'ESP',
            'LK': 'LKA', 'LKA': 'LKA', 'Sri Lanka': 'LKA',
            'SD': 'SDN', 'SDN': 'SDN', 'Sudan': 'SDN',
            'SE': 'SWE', 'SWE': 'SWE', 'Sweden': 'SWE',
            'CH': 'CHE', 'CHE': 'CHE', 'Switzerland': 'CHE',
            'SY': 'SYR', 'SYR': 'SYR', 'Syria': 'SYR', 'Syrian Arab Republic': 'SYR',
            // T
            'TW': 'TWN', 'TWN': 'TWN', 'Taiwan': 'TWN', 'Taiwan, Province of China': 'TWN',
            'TJ': 'TJK', 'TJK': 'TJK', 'Tajikistan': 'TJK',
            'TZ': 'TZA', 'TZA': 'TZA', 'Tanzania': 'TZA', 'Tanzania, United Republic of': 'TZA',
            'TH': 'THA', 'THA': 'THA', 'Thailand': 'THA',
            'TG': 'TGO', 'TGO': 'TGO', 'Togo': 'TGO',
            'TT': 'TTO', 'TTO': 'TTO', 'Trinidad and Tobago': 'TTO',
            'TN': 'TUN', 'TUN': 'TUN', 'Tunisia': 'TUN',
            'TR': 'TUR', 'TUR': 'TUR', 'Turkey': 'TUR', 'Türkiye': 'TUR',
            'TM': 'TKM', 'TKM': 'TKM', 'Turkmenistan': 'TKM',
            // U
            'UG': 'UGA', 'UGA': 'UGA', 'Uganda': 'UGA',
            'UA': 'UKR', 'UKR': 'UKR', 'Ukraine': 'UKR',
            'AE': 'ARE', 'ARE': 'ARE', 'United Arab Emirates': 'ARE', 'UAE': 'ARE',
            'GB': 'GBR', 'GBR': 'GBR', 'United Kingdom': 'GBR', 'UK': 'GBR', 'Great Britain': 'GBR', 'England': 'GBR',
            'US': 'USA', 'USA': 'USA', 'United States': 'USA', 'United States of America': 'USA',
            'UY': 'URY', 'URY': 'URY', 'Uruguay': 'URY',
            'UZ': 'UZB', 'UZB': 'UZB', 'Uzbekistan': 'UZB',
            // V
            'VE': 'VEN', 'VEN': 'VEN', 'Venezuela': 'VEN', 'Venezuela, Bolivarian Republic of': 'VEN',
            'VN': 'VNM', 'VNM': 'VNM', 'Vietnam': 'VNM', 'Viet Nam': 'VNM',
            // Y
            'YE': 'YEM', 'YEM': 'YEM', 'Yemen': 'YEM',
            // Z
            'ZM': 'ZMB', 'ZMB': 'ZMB', 'Zambia': 'ZMB',
            'ZW': 'ZWE', 'ZWE': 'ZWE', 'Zimbabwe': 'ZWE'
        };

        // Try exact match first
        if (countryCodes[normalized]) {
            return countryCodes[normalized];
        }

        // Try uppercase (for 2-letter codes)
        var upper = normalized.toUpperCase();
        if (countryCodes[upper]) {
            return countryCodes[upper];
        }

        // Log unmatched countries for debugging
        console.log('Unmatched country: "' + countryName + '"');
        return null;
    }

    /**
     * Render geographic distribution chart as a world map
     */
    function renderGeographicChart(data) {
        if (!data.geographic || Object.keys(data.geographic).length === 0) {
            document.getElementById('geographic-chart').innerHTML = '<p style="text-align:center;color:#666;">No geographic data available</p>';
            return;
        }

        // Process country data
        var locations = [];
        var totals = [];
        var hoverTexts = [];
        var countryNames = [];

        var entries = Object.entries(data.geographic);
        for (var i = 0; i < entries.length; i++) {
            var country = entries[i][0];
            var stats = entries[i][1];
            if (!country || country === 'Unknown' || country === '') continue;

            var code = getCountryCode(country);
            if (!code) continue;

            var views = stats.view_count || 0;
            var downloads = stats.download_count || 0;
            var total = views + downloads;

            locations.push(code);
            countryNames.push(country);
            totals.push(total);
            hoverTexts.push(
                '<b>' + country + '</b><br>' +
                'Total: ' + formatNumber(total) + '<br>' +
                'Views: ' + formatNumber(views) + '<br>' +
                'Downloads: ' + formatNumber(downloads)
            );
        }

        // Use linear scale from min to max for better color gradient visibility
        var minTotal = Math.min.apply(null, totals) || 0;
        var maxTotal = Math.max.apply(null, totals) || 1;

        var trace = {
            type: 'choropleth',
            locationmode: 'ISO-3',
            locations: locations,
            z: totals,
            text: hoverTexts,
            hoverinfo: 'text',
            colorscale: [
                [0, '#ffffcc'],      // Very light yellow - minimum traffic
                [0.15, '#c7e9b4'],   // Light green
                [0.3, '#7fcdbb'],    // Teal
                [0.45, '#41b6c4'],   // Cyan
                [0.6, '#1d91c0'],    // Light blue
                [0.75, '#225ea8'],   // Medium blue
                [0.9, '#253494'],    // Dark blue
                [1, '#081d58']       // Very dark blue - maximum traffic
            ],
            zmin: minTotal,
            zmax: maxTotal,
            showscale: false,  // Hide the color legend
            marker: {
                line: {
                    color: '#ffffff',
                    width: 0.5
                }
            }
        };

        var layout = {
            margin: { t: 0, r: 0, b: 0, l: 0 },
            geo: {
                showframe: false,
                showcoastlines: true,
                coastlinecolor: '#999999',
                projection: {
                    type: 'natural earth'
                },
                showland: true,
                landcolor: '#f5f5f5',
                showocean: true,
                oceancolor: '#e8f4f8',
                showlakes: true,
                lakecolor: '#e8f4f8',
                showcountries: true,
                countrycolor: '#cccccc'
            }
        };

        Plotly.newPlot('geographic-chart', [trace], layout, {responsive: true});
    }

    /**
     * Render traffic source (bot vs human) chart
     */
    function renderTrafficSourceChart(data) {
        if (!data.bot_stats) {
            document.getElementById('traffic-source-chart').innerHTML = '<p style="text-align:center;color:#666;">No traffic source data available</p>';
            return;
        }

        const humanTraffic = (data.bot_stats.human_views || 0) + (data.bot_stats.human_downloads || 0);
        const botTraffic = (data.bot_stats.bot_views || 0) + (data.bot_stats.bot_downloads || 0);

        const trace = {
            labels: ['Human Traffic', 'Bot Traffic'],
            values: [humanTraffic, botTraffic],
            type: 'pie',
            marker: {
                colors: ['#00D2D3', '#FF6B6B']
            },
            textinfo: 'label+percent',
            hovertemplate: '<b>%{label}</b><br>Count: %{value}<br>Percentage: %{percent}<extra></extra>'
        };

        const layout = {
            margin: { t: 10, r: 10, b: 10, l: 10 },
            showlegend: true,
            legend: {
                orientation: 'h',
                y: -0.1
            }
        };

        Plotly.newPlot('traffic-source-chart', [trace], layout, {responsive: true});
    }

    /**
     * Render datatype distribution chart
     */
    function renderDatatypeChart(data) {
        if (!data.by_datatype || Object.keys(data.by_datatype).length === 0) {
            document.getElementById('datatype-chart').innerHTML = '<p style="text-align:center;color:#666;">No datatype data available</p>';
            return;
        }

        // Map datatype IDs to names and sort by total traffic
        const datatypeData = Object.entries(data.by_datatype)
            .map(([datatypeId, stats]) => {
                const datatype = datatypes.find(dt => dt.id == datatypeId);
                return {
                    name: datatype ? datatype.shortName : `Datatype ${datatypeId}`,
                    views: stats.view_count || 0,
                    downloads: stats.download_count || 0,
                    total: (stats.view_count || 0) + (stats.download_count || 0)
                };
            })
            .sort((a, b) => b.total - a.total)
            .slice(0, 15); // Top 15 datatypes

        const trace1 = {
            x: datatypeData.map(d => d.name),
            y: datatypeData.map(d => d.views),
            name: 'Views',
            type: 'bar',
            marker: { color: '#2E86DE' }
        };

        const trace2 = {
            x: datatypeData.map(d => d.name),
            y: datatypeData.map(d => d.downloads),
            name: 'Downloads',
            type: 'bar',
            marker: { color: '#10AC84' }
        };

        const layout = {
            margin: { t: 10, r: 10, b: 100, l: 60 },
            barmode: 'group',
            xaxis: {
                title: 'Data Type',
                tickangle: -45
            },
            yaxis: {
                title: 'Count',
                showgrid: true,
                gridcolor: '#f0f0f0'
            },
            showlegend: true,
            legend: {
                orientation: 'h',
                y: -0.3
            }
        };

        Plotly.newPlot('datatype-chart', [trace1, trace2], layout, {responsive: true});
    }

    /**
     * Format number with commas
     */
    function formatNumber(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    /**
     * Show loading state
     */
    function showLoading() {
        // Could add loading spinners here
    }

    /**
     * Hide loading state
     */
    function hideLoading() {
        // Hide loading spinners
    }

    /**
     * Show error message
     */
    function showError(message) {
        console.error(message);
        // Could show error UI here
    }

    // Public API
    console.log('ODRStatisticsDashboard: Returning public API');
    return {
        init: init
    };
})();

console.log('ODRStatisticsDashboard: Module loaded, window.ODRStatisticsDashboard =', window.ODRStatisticsDashboard);
// Initialize dashboard with datatypes data and API base URL
console.log('ODRStatisticsDashboard Initializing dashboard...');
// document.addEventListener('DOMContentLoaded', function() {
    // Use wordpress_site_baseurl for API routes (e.g., //beta.rruff.net/odr)
    console.log('ODRStatisticsDashboard INIT DOMContentLoaded');
    try {
        let api_baseurl = site_baseurl;
        console.log('Site base URL: ' + api_baseurl);
        if(odr_wordpress_integrated) {
            console.log('Wordpress Site base URL: ' + wordpress_site_baseurl);
            console.log('Wordpress Integrated: ' + odr_wordpress_integrated);
            api_baseurl = wordpress_site_baseurl;
        }
        console.log('ODRStatisticsDashboard API Base URL: ', api_baseurl);

        // Get the datatypes from the page input data
        let datatypes = jQuery('#dashboard_datatypes').val();
        console.log('ODRStatisticsDashboard datatypes: ', datatypes);
        datatypes = JSON.parse(datatypes);
        console.log('ODRStatisticsDashboard datatypes: ', datatypes);
        ODRStatisticsDashboard.init(datatypes, api_baseurl);
        console.log('ODRStatisticsDashboard initialized!');
    } catch (e) {
        console.error('ODRStatisticsDashboard error: ', e);
    }
// });
