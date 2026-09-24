<?php

return [
    'make_command' => ['type' => 'class', 'emoji' => false],
    'temporary_file_upload' => [
        'disk' => 'local',
        'rules' => ['required', 'file', 'max:8192'],
    ],
];
