<?php

use Mallto\Tool\Domain\NewConfig\SubjectConfigRuntimeValues;

return [
    'values_file' => env('SUBJECT_CONFIG_VALUES_FILE', storage_path('framework/subject_configs_values.php')),
    'values' => SubjectConfigRuntimeValues::load(env('SUBJECT_CONFIG_VALUES_FILE', storage_path('framework/subject_configs_values.php'))),
    'sa_lo_st_limit' => env('SUBJECT_CONFIG_SA_LO_ST_LIMIT', 10),
];
