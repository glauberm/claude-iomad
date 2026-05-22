<?php
defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configpasswordunmask(
        'block_iomad_claude/apikey',
        get_string('apikey', 'block_iomad_claude'),
        get_string('apikey_desc', 'block_iomad_claude'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'block_iomad_claude/model',
        get_string('model', 'block_iomad_claude'),
        get_string('model_desc', 'block_iomad_claude'),
        'claude-sonnet-4-6'
    ));
}
