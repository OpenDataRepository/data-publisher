/*
 * Shared ODR API client for the background workers.
 *
 * Node keeps HTTPS connections alive and reuses them, and Apache closes a kept-alive connection
 *  after KeepAliveTimeout seconds idle.  When a worker sends a request on a connection at the
 *  moment Apache closes it, the request fails with ECONNRESET ("socket hang up") without ever
 *  reaching ODR.  Node drops the dead connection, so retrying opens a fresh one.
 *
 * apiCall() retries connection failures that are safe to retry:
 *  - any method, when the request cannot have reached ODR (a reset on a reused keep-alive
 *    connection, connection refused, DNS lookup failure);
 *  - otherwise only idempotent requests: GET/HEAD/PUT/DELETE, or a call marked idempotent.
 *
 * When a job still fails on a connection error, requeueIfConnectionError() puts it back on its
 *  tube, carrying the progress state (STATE_KEYS) so the next attempt skips completed work.
 */
const https = require('https');
const crypto = require('crypto');

const CONNECTION_ERROR_CODES = ['ECONNRESET', 'EPIPE', 'ETIMEDOUT', 'ECONNREFUSED', 'ECONNABORTED', 'EAI_AGAIN'];

// Wait before each retry, in ms.  The length is the number of retries.
const RETRY_DELAYS = [1000, 3000, 10000, 30000];
// For calls whose failure would strand a whole run (about 9 minutes in total)
const PERSISTENT_RETRY_DELAYS = [1000, 3000, 10000, 30000, 60000, 120000, 300000];

// Job payload fields that record how far a job got; carried over when it is re-queued.
//   _output_done     builder: output written, only the worker/job progress report is left
//   _worker_job_key  builder: random_key of that progress report (the server counts each key once)
//   _files_done      finisher: files moved/zipped, only marking the tracked job complete is left
//   _jobs_queued     coordinator: has queued other jobs, so re-running it would queue them twice
const STATE_KEYS = ['_output_done', '_worker_job_key', '_files_done', '_jobs_queued'];

// Re-queued jobs wait 15s, 30s, 45s, ... up to 5 minutes; about 50 minutes over 20 retries.
const MAX_REQUEUES = 20;

function delay(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function isConnectionError(e) {
    return e !== null && typeof e === 'object' && CONNECTION_ERROR_CODES.includes(e.code);
}

/**
 * Calls the ODR API and resolves with the parsed JSON response.
 *
 * @param {string} api_url   URL without the scheme, e.g. '//dev.rruff.net/odr_rruff/api/v4/token'
 * @param {*} post_data      body for POST/PUT ('' for none)
 * @param {string} method
 * @param {object} [opts]
 * @param {string} [opts.token]            bearer token
 * @param {boolean} [opts.log=true]        log each call
 * @param {boolean} [opts.rejectHttpErrors=false]  reject on a non-2xx status instead of resolving the error body
 * @param {boolean} [opts.alwaysSendBody=false]    send post_data whatever the method (statistics workers)
 * @param {boolean} [opts.idempotent]      safe to repeat; defaults to true for GET/HEAD/PUT/DELETE
 * @param {number[]} [opts.retryDelays]    defaults to RETRY_DELAYS
 */
async function apiCall(api_url, post_data, method, opts = {}) {
    if(opts.log !== false) {
        console.log('API Call: ', api_url);
    }
    const idempotent = (opts.idempotent !== undefined)
        ? opts.idempotent
        : ['GET', 'HEAD', 'PUT', 'DELETE'].includes(method);
    const retry_delays = opts.retryDelays || RETRY_DELAYS;

    for(let attempt = 0; ; attempt++) {
        try {
            return await request(api_url, post_data, method, opts);
        }
        catch (err) {
            if(
                !isConnectionError(err)
                || attempt >= retry_delays.length
                || !(err.notSent || idempotent)
            ) {
                throw err;
            }
            // A stale keep-alive connection: retry straight away on a fresh one
            let wait = (err.notSent && err.reusedSocket) ? 0 : retry_delays[attempt];
            console.log('API Call: ' + err.code + ' on ' + method + ' ' + api_url
                + (err.reusedSocket ? ' (reused connection)' : '')
                + '; retry ' + (attempt + 1) + ' of ' + retry_delays.length + ' in ' + wait + 'ms');
            await delay(wait);
        }
    }
}

function request(api_url, post_data, method, opts) {
    return new Promise((resolve, reject) => {
        let settled = false;
        function fail(err) {
            if(!settled) {
                settled = true;
                reject(err);
            }
        }

        try {
            // Parse the URL to extract hostname and path
            const url = new URL('https://' + api_url);

            const send_body = opts.alwaysSendBody
                || (post_data !== '' && (method === 'POST' || method === 'PUT'));
            const body = send_body ? JSON.stringify(post_data) : null;

            const options = {
                hostname: url.hostname,
                port: url.port || 443,
                path: url.pathname + url.search,
                method: method,
                headers: {
                    'Content-Type': 'application/json'
                }
            };
            if(opts.token) {
                options.headers['Authorization'] = 'Bearer ' + opts.token;
            }
            if(body !== null) {
                options.headers['Content-Length'] = Buffer.byteLength(body);
            }

            const req = https.request(options, (res) => {
                let data = '';
                res.on('data', (chunk) => {
                    data += chunk;
                });
                // The connection dropped part way through the response: ODR got the request
                res.on('error', (err) => {
                    err.reusedSocket = req.reusedSocket;
                    err.notSent = false;
                    fail(err);
                });
                res.on('end', () => {
                    if(settled) {
                        return;
                    }
                    if(opts.rejectHttpErrors && (res.statusCode < 200 || res.statusCode >= 300)) {
                        return fail(new Error('API ' + res.statusCode + ' from ' + api_url
                            + ': ' + data.slice(0, 500)));
                    }
                    let parsed;
                    try {
                        parsed = JSON.parse(data);
                    }
                    catch (e) {
                        return fail(new Error('Failed to parse JSON response from ' + api_url
                            + ' (HTTP ' + res.statusCode + '): ' + e.message
                            + ' -- body starts: ' + data.slice(0, 200)));
                    }
                    settled = true;
                    resolve(parsed);
                });
            });

            req.on('error', (err) => {
                console.error('Error thrown');
                err.reusedSocket = req.reusedSocket;
                // True when the request cannot have reached ODR
                err.notSent = err.code === 'ECONNREFUSED'
                    || err.code === 'EAI_AGAIN'
                    || (req.reusedSocket && (err.code === 'ECONNRESET' || err.code === 'EPIPE'));
                fail(err);
            });

            if(body !== null) {
                req.write(body);
            }
            req.end();
        }
        catch (err) {
            console.error('Error thrown');
            fail(err);
        }
    });
}

/**
 * Reports one finished unit of work for a tracked job (POST /api/vN/worker/job).
 *
 * The random_key is generated once and kept on `state`, so a retry -- here, or after the job is
 *  re-queued -- sends the same key, and the job status counts each key only once.
 *
 * @param {string} api_url        record.api_worker_job_url
 * @param {number} tracked_job_id
 * @param {string} prefix         random_key prefix, at most 12 characters
 * @param {object} state          the job payload; holds _worker_job_key
 * @param {string} token
 */
async function postWorkerJob(api_url, tracked_job_id, prefix, state, token) {
    if(!state._worker_job_key) {
        // random_key is a 32 character column
        state._worker_job_key = (prefix + crypto.randomBytes(16).toString('hex')).slice(0, 32);
    }
    const worker_job = {
        'job': {
            'tracked_job_id': tracked_job_id,
            'random_key': state._worker_job_key,
            'job_order': 0,
            'line_count': 1
        }
    };
    return apiCall(api_url, worker_job, 'POST', { token: token, idempotent: true });
}

/**
 * For a job's catch block.  If `err` is a connection error, puts a copy of the job back on
 *  `tube` (after a delay), with the STATE_KEYS fields from `state`, and resolves true.  The
 *  caller still deletes the original job.
 *
 * Does nothing for other errors, for a job that has queued other jobs (_jobs_queued), or once
 *  the job has been re-queued MAX_REQUEUES times.
 *
 * @param {*} err
 * @param {object} client   nodestalker client; its `use` tube is changed to `tube`
 * @param {string} tube
 * @param {object} job      the reserved job
 * @param {object} [state]  the job payload as the worker left it
 * @return {Promise<boolean>}
 */
async function requeueIfConnectionError(err, client, tube, job, state) {
    if(!isConnectionError(err)) {
        return false;
    }
    if(state && state._jobs_queued) {
        console.log('Connection error after this job queued other jobs; not re-running it.');
        return false;
    }

    let payload = JSON.parse(job.data);
    if(state) {
        for(let key of STATE_KEYS) {
            if(state[key] !== undefined) {
                payload[key] = state[key];
            }
        }
    }
    payload._connection_retries = (payload._connection_retries || 0) + 1;
    if(payload._connection_retries > MAX_REQUEUES) {
        console.log('Connection error (' + err.code + '); job ' + job.id + ' has been re-queued '
            + MAX_REQUEUES + ' times already, giving up.');
        return false;
    }
    let wait = Math.min(300, 15 * payload._connection_retries);

    return new Promise((resolve) => {
        function failed(e) {
            console.log('Could not re-queue job ' + job.id + ': ', e);
            resolve(false);
        }
        client.use(tube).onSuccess(() => {
            client.put(JSON.stringify(payload), undefined, wait).onSuccess((new_id) => {
                console.log('Connection error (' + err.code + '); re-queued job ' + job.id
                    + ' as ' + new_id + ' in ' + wait + 's (retry ' + payload._connection_retries
                    + ' of ' + MAX_REQUEUES + ')');
                resolve(true);
            }).onError(failed);
        }).onError(failed);
    });
}

/**
 * For a builder's catch block, when requeueIfConnectionError() didn't take the job: the record
 *  itself couldn't be processed (bad or missing data, a bug), so retrying won't help.  Logs it
 *  as invalid and reports it as done, so the tracked job still reaches its total and the run
 *  finishes -- without this record's output.
 *
 * Each one is logged twice:
 *  - one line in the worker's log, starting "INVALID RECORD" (grep for it);
 *  - one JSON line in <instance>/app/logs/invalid_records.jsonl, for a future queue of
 *    records for someone to fix.
 *
 * @param {*} err
 * @param {object} job      the reserved job
 * @param {object} [record] the job payload (undefined if it wasn't valid JSON)
 * @param {string} prefix   postWorkerJob() random_key prefix
 * @param {string} token
 * @param {string} worker   e.g. the worker's tube name
 */
async function skipInvalidRecord(err, job, record, prefix, token, worker) {
    const message = (err && err.message) ? err.message : String(err);
    // Where it failed: the first stack frame in our own code
    let where = '';
    if(err && typeof err.stack === 'string') {
        let frame = err.stack.split('\n').find((line) => line.includes('background_services/'));
        if(frame) {
            where = frame.trim().replace(/^at\s+/, '').replace(/.*background_services\//, '').replace(/\)$/, '');
        }
    }
    if(!record || typeof record !== 'object') {
        console.log('INVALID RECORD: job ' + job.id + ' on ' + worker + ' has an unreadable payload: '
            + message + ' -- data starts: ' + String(job.data).slice(0, 200));
        return;
    }

    const entry = {
        time: new Date().toISOString(),
        worker: worker,
        tracked_job_id: record.tracked_job_id,
        record_uuid: record.unique_id,
        // the cell params builder handles records from three databases
        record_type: record.cell_params_type,
        record_url: record.base_url ? record.base_url + '/api/v5/dataset/record/' + record.unique_id : undefined,
        error: message,
        where: where || undefined,
    };
    console.log('INVALID RECORD: ' + record.unique_id
        + (record.cell_params_type ? ' (' + record.cell_params_type + ')' : '')
        + ' on ' + worker + ', tracked job ' + record.tracked_job_id + ': ' + message
        + (where ? ' at ' + where : '') + ' -- skipped, counted as done');

    if(record.base_path) {
        try {
            require('fs').appendFileSync(
                record.base_path + '/app/logs/invalid_records.jsonl',
                JSON.stringify(entry) + '\n'
            );
        }
        catch (e) {
            console.log('Could not write invalid_records.jsonl: ' + e.message);
        }
    }

    // Count it, so the finisher isn't left waiting for it
    if(record.api_worker_job_url && record.tracked_job_id !== undefined) {
        try {
            await postWorkerJob(record.api_worker_job_url, record.tracked_job_id, prefix, record, token);
        }
        catch (e) {
            console.log('Could not report invalid record ' + record.unique_id + ' as done: ', e);
        }
    }
}

module.exports = {
    apiCall,
    postWorkerJob,
    requeueIfConnectionError,
    skipInvalidRecord,
    isConnectionError,
    RETRY_DELAYS,
    PERSISTENT_RETRY_DELAYS,
};
