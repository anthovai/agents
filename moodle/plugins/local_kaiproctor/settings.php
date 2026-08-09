<?php
// Admin settings. Thresholds are configurable because the value that decided a
// learner's result has to be documented and auditable.

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_kaiproctor',
        get_string('pluginname', 'local_kaiproctor')
    );

    $settings->add(new admin_setting_heading(
        'local_kaiproctor/faceservice',
        get_string('settings:faceservice', 'local_kaiproctor'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_kaiproctor/faceserviceurl',
        get_string('settings:faceserviceurl', 'local_kaiproctor'),
        get_string('settings:faceserviceurl_desc', 'local_kaiproctor'),
        'http://face-service:9000',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_kaiproctor/apikey',
        get_string('settings:apikey', 'local_kaiproctor'),
        get_string('settings:apikey_desc', 'local_kaiproctor'),
        ''
    ));

    $settings->add(new admin_setting_heading(
        'local_kaiproctor/matching',
        get_string('settings:matching', 'local_kaiproctor'),
        ''
    ));

    // Defaults mirror face-service/app/config.py. Both are the SFace reference
    // value — neither is calibrated. See docs/MIGRATION.md.
    $settings->add(new admin_setting_configtext(
        'local_kaiproctor/matchthreshold',
        get_string('settings:matchthreshold', 'local_kaiproctor'),
        get_string('settings:matchthreshold_desc', 'local_kaiproctor'),
        '0.363',
        PARAM_FLOAT
    ));

    $settings->add(new admin_setting_configtext(
        'local_kaiproctor/reviewmin',
        get_string('settings:reviewmin', 'local_kaiproctor'),
        get_string('settings:reviewmin_desc', 'local_kaiproctor'),
        '0.30',
        PARAM_FLOAT
    ));

    $settings->add(new admin_setting_configtext(
        'local_kaiproctor/retentiondays',
        get_string('settings:retentiondays', 'local_kaiproctor'),
        get_string('settings:retentiondays_desc', 'local_kaiproctor'),
        '180',
        PARAM_INT
    ));

    $ADMIN->add('localplugins', $settings);
}
