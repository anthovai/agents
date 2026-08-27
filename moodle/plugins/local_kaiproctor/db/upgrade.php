<?php
// Upgrade steps.

defined('MOODLE_INTERNAL') || die();

/**
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_kaiproctor_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026081000) {
        $table = new xmldb_table('local_kaiproctor_monitored');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('cmid', XMLDB_KEY_UNIQUE, ['cmid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026081000, 'local', 'kaiproctor');
    }

    if ($oldversion < 2026081001) {
        // Sittings, with the policy that was in force recorded on each.
        $table = new xmldb_table('local_kaiproctor_session');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('contextid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('attemptid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('policy', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'active');
        $table->add_field('reason', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('timestart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timeend', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('userid-contextid', XMLDB_INDEX_NOTUNIQUE, ['userid', 'contextid']);
        $table->add_index('status-timemodified', XMLDB_INDEX_NOTUNIQUE, ['status', 'timemodified']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Existing checks and evidence predate sittings and stay unassigned:
        // inventing a sitting for them would be making up an audit trail.
        foreach (['local_kaiproctor_check', 'local_kaiproctor_evidence'] as $tablename) {
            $target = new xmldb_table($tablename);
            $field = new xmldb_field('sessionid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'attemptid');
            if (!$dbman->field_exists($target, $field)) {
                $dbman->add_field($target, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2026081001, 'local', 'kaiproctor');
    }

    if ($oldversion < 2026081003) {
        $table = new xmldb_table('local_kaiproctor_draw');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('attemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('attemptnumber', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, null);
        $table->add_field('seed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('blueprint', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('questionids', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('attemptid', XMLDB_KEY_UNIQUE, ['attemptid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026081003, 'local', 'kaiproctor');
    }

    if ($oldversion < 2026081005) {
        // The plugin used to speak to an OpenAI-compatible gateway directly,
        // holding the prompts and the payload rules itself. It now posts to
        // the KAISER reviewer service, which enforces them at the boundary.
        //
        // A site that was already configured keeps pointing at the old gateway
        // otherwise: changing a default in settings.php does nothing to a
        // value that has already been stored.
        $current = (string) get_config('local_kaiproctor', 'aibaseurl');
        if ($current === '' || strpos($current, '/v1') !== false) {
            set_config('aibaseurl', 'http://ai-service:9100', 'local_kaiproctor');
        }

        // The model is the service's decision now, so the setting is gone and
        // its stored value would only mislead whoever reads the config table.
        unset_config('aimodel', 'local_kaiproctor');

        upgrade_plugin_savepoint(true, 2026081005, 'local', 'kaiproctor');
    }

    if ($oldversion < 2026081702) {
        // reviewmin was never written. A setting declared with a default in
        // settings.php only reaches the config table when somebody saves that
        // page or when it is new at upgrade time, and this one was neither —
        // so get_config returned false, (float) false is 0.0, and every
        // record of a check claimed the review band started at zero.
        //
        // It cost nothing while the thresholds lived in the face service's
        // environment and this value was ignored. It costs the audit trail its
        // meaning now that the platform is the one deciding, so it is filled
        // in rather than left to a fallback in the code.
        if (get_config('local_kaiproctor', 'reviewmin') === false) {
            set_config('reviewmin', 0.30, 'local_kaiproctor');
        }
        if (get_config('local_kaiproctor', 'matchthreshold') === false) {
            set_config('matchthreshold', 0.363, 'local_kaiproctor');
        }

        upgrade_plugin_savepoint(true, 2026081702, 'local', 'kaiproctor');
    }

    if ($oldversion < 2026081713) {
        // Conversation history. The transcript lives in the file API under the
        // user context; this table is the index that makes listing and quota
        // possible without opening every file.
        $table = new xmldb_table('local_kaiproctor_convo');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('turns', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('bytes', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('userid-timemodified', XMLDB_INDEX_NOTUNIQUE,
            ['userid', 'timemodified']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026081713, 'local', 'kaiproctor');
    }


    if ($oldversion < 2026081716) {
        // Intervals moved from minutes to seconds.
        //
        // Converted rather than defaulted, because the numbers on this page
        // were chosen for a reason and silently replacing them with defaults
        // would change what the site does without telling anybody. A site
        // running a thirty-second idle tolerance keeps one.
        $conversions = [
            'presenceminutes' => 'presenceseconds',
            'verifyminutes' => 'verifyseconds',
            'clickconfirmminutes' => 'clickconfirmseconds',
            'mouseidleminutes' => 'mouseidleseconds',
        ];
        foreach ($conversions as $old => $new) {
            $value = get_config('local_kaiproctor', $old);
            if ($value !== false && get_config('local_kaiproctor', $new) === false) {
                set_config($new, round((float) $value * 60), 'local_kaiproctor');
            }
            unset_config($old, 'local_kaiproctor');
        }

        upgrade_plugin_savepoint(true, 2026081716, 'local', 'kaiproctor');
    }

    if ($oldversion < 2026082600) {
        // The far side's id for a conversation, when the assistant keeps its
        // own state. The Indorama service files conversations itself and
        // hands back an id; without somewhere to keep it, every question
        // through this site started the remote conversation over.
        $table = new xmldb_table('local_kaiproctor_convo');
        $field = new xmldb_field('remoteid', XMLDB_TYPE_CHAR, '64', null, null,
            null, null, 'bytes');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026082600, 'local', 'kaiproctor');
    }

    return true;
}
