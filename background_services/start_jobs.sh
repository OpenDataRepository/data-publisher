#!/bin/bash

cd /home/rruff/data-publisher/background_services
node ima_data_builder.js >> ../app/logs/ima_data_builder.log 2>&1 &
node ima_data_finisher.js >> ../app/logs/ima_data_finisher.log 2>&1 &
node ima_record_builder.js  >> ../app/logs/ima_record_builder.log 2>&1 &
node ima_paragenetic_modes_builder.js   >> ../app/logs/ima_paragenetic_modes_builder.log 2>&1 &
node ima_cell_params_record_builder.js   >> ../app/logs/ima_cell_params_builder_1.log 2>&1 &
node ima_cell_params_record_builder.js   >> ../app/logs/ima_cell_params_builder_2.log 2>&1 &
# node ima_cell_params_record_builder.js   >> ../app/logs/ima_cell_params_builder_3.log 2>&1 &
node ima_reference_record_builder.js >> ../app/logs/ima_reference_builder_1.log 2>&1 &
node ima_reference_record_builder.js >> ../app/logs/ima_reference_builder_2.log 2>&1 &

node rruff_file_builder.js >> ../app/logs/rruff_file_builder.log 2>&1 &
node rruff_file_finisher.js >> ../app/logs/rruff_file_finisher.log 2>&1 &
node rruff_record_analyzer.js >> ../app/logs/rruff_record_analyzer.log 2>&1 &
