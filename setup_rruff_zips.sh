#!/bin/bash

mkdir -p app/rruff_files
mkdir -p app/rruff_files/chemistry
mkdir -p app/rruff_files/chemistry/microprobe_data
mkdir -p app/rruff_files/chemistry/reference_pdf
mkdir -p app/rruff_files/infrared/processed
mkdir -p app/rruff_files/infrared/raw
mkdir -p app/rruff_files/powder
mkdir -p app/rruff_files/powder/dif
mkdir -p app/rruff_files/powder/reference_pdf
mkdir -p app/rruff_files/powder/refinement_data
mkdir -p app/rruff_files/powder/refinement_output_data
mkdir -p app/rruff_files/powder/xy_processed
mkdir -p app/rruff_files/powder/xy_raw
mkdir -p app/rruff_files/raman
mkdir -p app/rruff_files/raman/lr-raman
mkdir -p app/rruff_files/raman/excellent_oriented
mkdir -p app/rruff_files/raman/excellent_unoriented
mkdir -p app/rruff_files/raman/fair_oriented
mkdir -p app/rruff_files/raman/fair_unoriented
mkdir -p app/rruff_files/raman/ignore_unoriented
mkdir -p app/rruff_files/raman/poor_oriented
mkdir -p app/rruff_files/raman/poor_unoriented
mkdir -p app/rruff_files/raman/unrated_oriented
mkdir -p app/rruff_files/raman/unrated_unoriented
mkdir -p app/rruff_files/rruff_good_images


mkdir -p web/zipped_data_files
mkdir -p web/zipped_data_files/chemistry
mkdir -p web/zipped_data_files/infrared
mkdir -p web/zipped_data_files/powder
mkdir -p web/zipped_data_files/raman

sudo setfacl -R -m u:"$HTTPDUSER":rwX -m u:`whoami`:rwX web/zipped_data_files
sudo setfacl -dR -m u:"$HTTPDUSER":rwX -m u:`whoami`:rwX web/zipped_data_files

