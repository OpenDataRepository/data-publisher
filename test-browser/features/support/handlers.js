var debug = require('debug')('test')
var testerror = require('../../modules/handle-error.js')
var globals = require('../../modules/globals.js')

var myHandlers = function () {

  var self = this;
  this.registerHandler('StepResult', function (step, callback) {
    if(step.getStatus() == "failed") {
      testerror
        .handleError(step.getFailureException(), globals.browserstack_session)
        .then(function() {
          callback();
        })
    }
    else {
      callback();
    }
  });

}

module.exports = myHandlers;
