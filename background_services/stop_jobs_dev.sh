#!/bin/bash

ps auxww |grep nodemon | awk '{print $2}'| xargs kill
