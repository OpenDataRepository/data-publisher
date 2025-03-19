#!/bin/bash

cd /home/rruff/data-publisher/background_services
nodemon ima_data_builder.js >> ../app/logs/ima_data_builder.log 2>&1 &
nodemon ima_data_finisher.js >> ../app/logs/ima_data_finisher.log 2>&1 &
nodemon ima_record_builder.js  >> ../app/logs/ima_record_builder.log 2>&1 &
nodemon ima_paragenetic_modes_builder.js   >> ../app/logs/ima_paragenetic_modes_builder.log 2>&1 &
nodemon ima_cell_params_record_builder.js   >> ../app/logs/ima_cell_params_builder_1.log 2>&1 &
nodemon ima_cell_params_record_builder.js   >> ../app/logs/ima_cell_params_builder_2.log 2>&1 &
nodemon ima_cell_params_record_builder.js   >> ../app/logs/ima_cell_params_builder_3.log 2>&1 &
nodemon ima_reference_record_builder.js >> ../app/logs/ima_reference_builder_1.log 2>&1 &
nodemon ima_reference_record_builder.js >> ../app/logs/ima_reference_builder_2.log 2>&1 &

