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

    $settings->add(new admin_setting_heading(
        'local_kaiproctor/policy',
        get_string('settings:policy', 'local_kaiproctor'),
        get_string('settings:policy_desc', 'local_kaiproctor')
    ));

    // Intervals are in minutes; 0 switches a check off entirely.
    foreach ([
        'presenceminutes' => '2',
        'verifyminutes' => '10',
        'clickconfirmminutes' => '5',
        'clickconfirmgracesec' => '30',
        'mouseidleminutes' => '3',
        'randomclipsperhour' => '4',
        'clipseconds' => '8',
        'blurallowance' => '0',
    ] as $name => $default) {
        $settings->add(new admin_setting_configtext(
            'local_kaiproctor/' . $name,
            get_string('settings:' . $name, 'local_kaiproctor'),
            get_string('settings:' . $name . '_desc', 'local_kaiproctor'),
            $default,
            PARAM_FLOAT
        ));
    }

    $settings->add(new admin_setting_configcheckbox(
        'local_kaiproctor/strictlockdown',
        get_string('settings:strictlockdown', 'local_kaiproctor'),
        get_string('settings:strictlockdown_desc', 'local_kaiproctor'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_kaiproctor/desktopnotification',
        get_string('settings:desktopnotification', 'local_kaiproctor'),
        get_string('settings:desktopnotification_desc', 'local_kaiproctor'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_kaiproctor/lessonvideourl',
        get_string('settings:lessonvideourl', 'local_kaiproctor'),
        get_string('settings:lessonvideourl_desc', 'local_kaiproctor'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_heading(
        'local_kaiproctor/ai',
        get_string('settings:ai', 'local_kaiproctor'),
        get_string('settings:ai_desc', 'local_kaiproctor')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_kaiproctor/aienabled',
        get_string('settings:aienabled', 'local_kaiproctor'),
        get_string('settings:aienabled_desc', 'local_kaiproctor'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_kaiproctor/aibaseurl',
        get_string('settings:aibaseurl', 'local_kaiproctor'),
        get_string('settings:aibaseurl_desc', 'local_kaiproctor'),
        'http://llm-gateway:4000/v1',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_kaiproctor/aiapikey',
        get_string('settings:aiapikey', 'local_kaiproctor'),
        get_string('settings:aiapikey_desc', 'local_kaiproctor'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_kaiproctor/aimodel',
        get_string('settings:aimodel', 'local_kaiproctor'),
        get_string('settings:aimodel_desc', 'local_kaiproctor'),
        'proctor-reviewer',
        PARAM_TEXT
    ));

    $ADMIN->add('localplugins', $settings);
}
