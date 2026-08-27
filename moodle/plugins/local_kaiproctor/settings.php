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

    // Every interval is in whole seconds; 0 switches a check off entirely.
    //
    // Minutes were the original unit and they made the short intervals this
    // system actually uses unreadable: half a minute had to be typed as 0.5,
    // three seconds as 0.05, and nobody could tell at a glance whether
    // "0.05" meant three seconds or three minutes. Seconds are what the
    // countdown on screen counts in, so the setting and the thing it controls
    // now use the same unit.
    foreach ([
        'presenceseconds' => '120',
        'verifyseconds' => '600',
        'clickconfirmseconds' => '300',
        'clickconfirmgracesec' => '30',
        'mouseidleseconds' => '180',
        'presencewarnsec' => '5',
        'randomclipsperhour' => '4',
        'clipseconds' => '8',
        'blurallowance' => '0',
    ] as $name => $default) {
        $settings->add(new \local_kaiproctor\admin\plain_number(
            'local_kaiproctor/' . $name,
            get_string('settings:' . $name, 'local_kaiproctor'),
            get_string('settings:' . $name . '_desc', 'local_kaiproctor'),
            $default
        ));
    }

    // Not in the loop above: this one has to fit inside the idle tolerance,
    // and a pair that cannot both be true is refused rather than quietly
    // capped. See the class for why the presence warning is not the same.
    $settings->add(new \local_kaiproctor\admin\warn_lead_time(
        'local_kaiproctor/mouseidlewarnsec',
        get_string('settings:mouseidlewarnsec', 'local_kaiproctor'),
        get_string('settings:mouseidlewarnsec_desc', 'local_kaiproctor'),
        '10',
        'mouseidleseconds'
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_kaiproctor/strictlockdown',
        get_string('settings:strictlockdown', 'local_kaiproctor'),
        get_string('settings:strictlockdown_desc', 'local_kaiproctor'),
        1
    ));

    // Off by default, and the asymmetry is deliberate: see the reasoning in
    // session::current_policy().
    $settings->add(new admin_setting_configcheckbox(
        'local_kaiproctor/lessonstrictlockdown',
        get_string('settings:lessonstrictlockdown', 'local_kaiproctor'),
        get_string('settings:lessonstrictlockdown_desc', 'local_kaiproctor'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_kaiproctor/desktopnotification',
        get_string('settings:desktopnotification', 'local_kaiproctor'),
        get_string('settings:desktopnotification_desc', 'local_kaiproctor'),
        1
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
        'local_kaiproctor/chatquota',
        get_string('settings:chatquota', 'local_kaiproctor'),
        get_string('settings:chatquota_desc', 'local_kaiproctor'),
        1073741824,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configselect(
        'local_kaiproctor/asksource',
        get_string('settings:asksource', 'local_kaiproctor'),
        get_string('settings:asksource_desc', 'local_kaiproctor'),
        'moodle',
        [
            'moodle' => get_string('settings:asksource:moodle', 'local_kaiproctor'),
            'indorama' => get_string('settings:asksource:indorama', 'local_kaiproctor'),
        ]
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_kaiproctor/ragstaffonly',
        get_string('settings:ragstaffonly', 'local_kaiproctor'),
        get_string('settings:ragstaffonly_desc', 'local_kaiproctor'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_kaiproctor/ragbaseurl',
        get_string('settings:ragbaseurl', 'local_kaiproctor'),
        get_string('settings:ragbaseurl_desc', 'local_kaiproctor'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_kaiproctor/ragapikey',
        get_string('settings:ragapikey', 'local_kaiproctor'),
        get_string('settings:ragapikey_desc', 'local_kaiproctor'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_kaiproctor/aibaseurl',
        get_string('settings:aibaseurl', 'local_kaiproctor'),
        get_string('settings:aibaseurl_desc', 'local_kaiproctor'),
        'http://ai-service:9100',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_kaiproctor/aiapikey',
        get_string('settings:aiapikey', 'local_kaiproctor'),
        get_string('settings:aiapikey_desc', 'local_kaiproctor'),
        ''
    ));


    // A findable home for the switch, sitting next to the facts that decide
    // whether it may be used at all: which model answers, and whose machine it
    // is on. The raw setting below still exists; this is where somebody who
    // has to answer for that decision should be sent.
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_kaiproctor_ai',
        get_string('ai:console', 'local_kaiproctor'),
        new moodle_url('/local/kaiproctor/ai.php'),
        'local/kaiproctor:manage'
    ));

    $ADMIN->add('localplugins', $settings);
}
