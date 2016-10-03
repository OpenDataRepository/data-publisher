/**
 * For use in Selenium IDE.
 *
 * To use, open Selenium IDE, then select "Options..." from the Options menu.
 * In the "Selenium IDE Extensions" field, browse to this file's location, then restart the IDE.
 */
var manager = new RollupManager();

/**
 * odr_login
 *
 * Logs the specified user into ODR for testing purposes.
 */
manager.addRollupRule({
    name: 'odr_login',
    description: 'Logs the specified user into ODR for testing purposes',
    args: [],
    commandMatchers: [],

    getExpandedCommands: function(args) {
        var commands = [];

        var username = 'testuser@opendatarepository.org';
        var password = 'Testuser1234*';

        commands.push({
            command: 'open',
            target: '/admin'
        });

        commands.push({
            command: 'type',
            target: 'id=username',
            value: username
        });

        commands.push({
            command: 'type',
            target: 'id=password',
            value: password
        });

        commands.push({
            command: 'clickAndWait',
            target: 'id=_submit'
        });

        return commands;
    }
});

/**
 * odr_logout
 *
 * Immediately navigates to the logout page
 */
manager.addRollupRule({
    name: 'odr_logout',
    description: 'Logs the current user out of ODR',
    args: [],
    commandMatchers: [],

    getExpandedCommands: function(args) {
        var commands = [];

        commands.push({
            command: 'open',
            target: '/logout'
        });

        return commands;
    }
});

