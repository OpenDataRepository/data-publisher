#!/usr/bin/env node
var child_process = require('child_process');
var config_file = './conf/' + (process.env.CONFIG_FILE || 'local') + '.conf.js';
var config = require(config_file).config;

process.argv[0] = 'node';
process.argv[1] = '../node_modules/.bin/cucumber-js';

for(var i in config.capabilities){
  var env = Object.create( process.env );
  env.TASK_ID = i.toString();
  var p = child_process.spawn('/usr/bin/env', process.argv, { env: env } );  
  p.stdout.pipe(process.stdout);
}
