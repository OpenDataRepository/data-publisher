#!/bin/bash
echo "Start Jobs DEV"
BASEPATH=/home/rruff/data-publisher

cd $BASEPATH/background_services
nodemon ima_data_builder.js >> $BASEPATH/app/logs/ima_data_builder.log 2>&1 &
nodemon ima_data_finisher.js >> $BASEPATH/app/logs/ima_data_finisher.log 2>&1 &
nodemon ima_record_builder.js  >> $BASEPATH/app/logs/ima_record_builder.log 2>&1 &
nodemon ima_paragenetic_modes_builder.js   >> $BASEPATH/app/logs/ima_paragenetic_modes_builder.log 2>&1 &
nodemon ima_cell_params_record_builder.js   >> $BASEPATH/app/logs/ima_cell_params_builder_1.log 2>&1 &
nodemon ima_reference_record_builder.js >> $BASEPATH/app/logs/ima_reference_builder_1.log 2>&1 &
nodemon rruff_file_builder.js >> $BASEPATH/app/logs/rruff_file_builder.log 2>&1 &
nodemon rruff_file_finisher.js >> $BASEPATH/app/logs/rruff_file_finisher.log 2>&1 &
nodemon rruff_record_analyzer.js >> $BASEPATH/app/logs/rruff_record_analyzer.log 2>&1 &
nodemon amcsd_file_builder.js >> $BASEPATH/app/logs/amcsd_file_builder.log 2>&1 &
nodemon amcsd_file_finisher.js >> $BASEPATH/app/logs/amcsd_file_finisher.log 2>&1 &
nodemon amcsd_record_analyzer.js >> $BASEPATH/app/logs/amcsd_record_analyzer.log 2>&1 &


nodemon seed_elastic_record_daemon.js >> $BASEPATH/app/logs/seed_elastic_record_daemon.log 2>&1 &
nodemon statistics_processor.js >> $BASEPATH/app/logs/statistics_processor.log 2>&1 &
nodemon statistics_daily_aggregator.js >> $BASEPATH/app/logs/statistics_daily_aggregator.log 2>&1 &

STATIC_RENDER_IGNORE_HTTPS_ERRORS=1 nodemon static_render_daemon.js >> $BASEPATH/app/logs/static_render_daemon.log 2>&1 &
echo "END Start Jobs DEV"
