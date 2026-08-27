const bs = require('nodestalker');
const client = bs.Client('127.0.0.1:11300');
const tube = 'odr_seed_elastic_record';

async function app() {
    console.log('Clearing tube: odr_seed_elastic_record');
    client.watch(tube).onSuccess(function(data) {
        function resJob() {
            client.reserve().onSuccess(async function(job) {
                try {
                    client.deleteJob(job.id).onSuccess(function(del_msg) {
                        console.log('Deleted Job: ' + job.id);
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

app();
