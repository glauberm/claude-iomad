<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'block_iomad_claude_ask' => [
        'classname'     => 'block_iomad_claude\external',
        'methodname'    => 'ask_question',
        'description'   => 'Ask a natural-language question about IOMAD data via Claude',
        'type'          => 'read',
        'ajax'          => true,
        'loginrequired' => true,
    ],
];
