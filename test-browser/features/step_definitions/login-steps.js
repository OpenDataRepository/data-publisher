'use strict';

var assert = require('cucumber-assert');
var webdriver = require('selenium-webdriver');
var debug = require('debug')('test')
var globals = require('../../modules/globals.js')
var conf = require('../../conf/local.conf.js')
var _ = require('lodash')
const moment = require('moment')
const fs = require('fs')
var path = require('path');
var mkpath = require('mkpath');

var session_id = "";
module.exports = function() {

  this.When(/^We open page$/, function (next) {
    debug('Attempt to maximize window');
    this.driver.manage().window().maximize();
    this.driver.get('http://epsilon.odr.io');
    next();
  });

  this.Then(/^We should store the BrowserStack session id$/, function (next) {

    this.driver.session_
      .then(function(session) {
        globals.browserstack_session = session.id_;
        assert.equal(
          globals.browserstack_session.length > 10,
          true,
          next,
          'Expected id_ to be present and greater than length 10'
        )
      })
  });

  this.Then(/^We should find "([^"]*)"$/, function (sourceMatch, next) {
    this.driver.getPageSource()
      .then(function(source) {
        assert.equal(
          source.indexOf(sourceMatch) > -1,
          true,
          next,
          'Expected source to contain ' + sourceMatch
        )
      })
  });

  this.Then(/^We should log in$/, function (next) {
    var self = this;
    this.driver.getPageSource()
      .then(function (source) {

        var username = self.driver
          .findElement(webdriver.By.name("_username"));

        var password = self.driver
          .findElement(webdriver.By.name("_password"));

        debug(globals.browser_name)
        if(_.indexOf(globals.webdriver_browsers, globals.browser_name) > -1) {
          debug("using webdriver default")
          // Chrome: not working
          // Safari: all elements clicked and filled
          // Internet Explorer: only password clicks and fills
          // Firefox: all elements clicked and filled
          // iPhone: not working
          // iPad: untested
          // Android: untested
          // Edge: untested
          username
            .click()
            .then(function() {
              username
                .sendKeys(conf.config.page_user)
            })

          password
            .click()
            .then(function() {
              password
                .sendKeys(conf.config.page_user_pass)
            })
        }
        else if(_.indexOf(globals.action_sequence_browsers, globals.browser_name) > -1) {
          debug("using action sequence")
          // Chrome: all elements clicked and filled
          // Safari: untested
          // Internet untested
          // Firefox: untested
          // iPhone: not working
          // iPad: untested
          // Android: Samsung Works
          // Edge: works
          var user_actions = new webdriver.ActionSequence(self.driver);
          user_actions
            .click(username)
            .sendKeys(conf.config.page_user)
            .click(password)
            .sendKeys(conf.config.page_user_pass)
            .perform();
        }

        // Section results
        // Chrome: submit works and page loads (6s)
        // Safari: submit works, but page does not load (15s)
        // Internet Explorer: username is empty, submit works, login failure
        // Firefox: submit works and page loads (6s)
        // iPhone: untested
        // iPad: untested
        // Android: Samsung Works
        // Edge: works
        var submit_btn = self.driver.findElement(webdriver.By.id("_submit"));
        submit_btn.click()
          .then(function() {
            return self.driver.wait(function () {
              debug('waiting for element ODRDashboardGraphs')
              return self.driver.getPageSource()
                .then(function(pageSource) {
                  if(pageSource.match(/Invalid login./)) {
                    throw("Invalid login.")
                  }
                  return self.driver.isElementPresent(webdriver.By.id("ODRDashboardGraphs"));
                })
            }, 15000)
              .then(function() {
                return self.driver.getPageSource()
                  .then(function (dash_source) {
                    return assert.equal(
                      dash_source.indexOf('Logout') > -1,
                      true,
                      next,
                      'Expected source to contain logout'
                    )
                  })
              })
          })
      })
  });


  /*
    // hover over element....
	var webdriver = require('selenium-webdriver');
	var driver = new webdriver.Builder().usingServer().withCapabilities({'browserName': 'chrome' }).build();

	driver.manage().window().setSize(1280, 1000).then(function() {
		driver.get('http://www.shazam.com/discover/track/58858793');
		driver.findElement(webdriver.By.css('.ta-tracks li:first-child')).then(function(elem){
			driver.actions().mouseMove(elem).perform();
			driver.sleep(5000);

   */

  /*
  this.Then(/^We snap a screenshot$/, function (next) {
    return this.driver.takeScreenshot();
  });
  */

  this.Then(/^We should see "([^"]*)"$/, function (sourceMatch, next) {
    this.driver.getPageSource()
      .then(function(source) {
        assert.equal(source.indexOf(sourceMatch) > -1, true, next, 'Expected source to contain ' + sourceMatch);
        next()
      })
      .catch(function(error) {
        return testerror.handleError(error, session_id)
      });
  });

  this.Then(/^We should take a screenshot of the "([^"]*)"$/, function (sourceMatch, next) {
      this.driver.takeScreenshot().then(function (data) {
          debug('Screenshot');
          var base64Data = data.replace(/^data:image\/png;base64,/, "")

          var ss_path = 'screenshots/' + moment().format('YYYY-MM-DD-HH')
          debug('path start');
          return mkpath(ss_path, function(fs_error) {
              debug('path function');
              if(fs_error) {
                  debug('path fail');
                  debug(fs_err);
                  return testerror.handleError(error, session_id)
              }
              else {
                  debug('path success');
                  fs.writeFile(ss_path + '/' + sourceMatch + ".png", base64Data, 'base64', function (err) {
                      if (err) {
                          debug(err);
                          return testerror.handleError(error, session_id)
                      }
                      next()
                  });
              }
          })
      })
      .catch(function(error) {
          debug(error);
          return testerror.handleError(error, session_id)
      });
  });

  this.Then(/^We should also see "([^"]*)"$/, function (sourceMatch, next) {
    this.driver.getPageSource()
      .then(function(source) {
        assert.equal(source.indexOf(sourceMatch) > -1, true, next, 'Expected source to contain ' + sourceMatch);
        next()
      })
      .catch(function(error) {
        return testerror.handleError(error, session_id)
      });
  });
};
