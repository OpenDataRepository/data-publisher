'use strict';
var superagent = require('superagent')
var debug = require('debug')('test')


module.exports.handleError = function(error, session_id) {

  debug('Reporting error to BrowserStack')
  /*
   curl
   -u "username:apikey"
   -X PUT
   -H "Content-Type: application/json"
   -d "{\"status\":\"<new-status>\", \"reason\":\"<reason text>\"}"
   https://www.browserstack.com/autmate/sessions/<session-id>.json
    */
  // .auth('natestone2', 'PzqsYiUkUYHxQgM3iL88')
  // .auth('natestone1', 'wAJq8PkhxoRpndiean5F')
  return superagent
    .put('https://www.browserstack.com/automate/sessions/' + session_id + '.json')
    .set('Content-Type', 'application/json')
    .auth('natestone1', 'wAJq8PkhxoRpndiean5F')
    .send({
      status: 'failed',
      reason: error.toString()
    })
    .then(function(res) {
      debug("Status codes")
      debug(res.statusCode);
      if(res.statusCode != 200) {
        // Do nothing
        debug('Superagent error')
        debug(res)
        // throw(res.statusCode)
      }
      else {
        debug('We logged an error.')
        // throw(error)
      }
      return
    });
}
