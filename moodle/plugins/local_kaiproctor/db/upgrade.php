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

    return true;
}
