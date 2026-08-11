<?php
// Schema changes after the first install.

defined('MOODLE_INTERNAL') || die();

/**
 * @param int $oldversion
 * @return bool
 */
function xmldb_kaivideo_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026081101) {
        // "Viewed" was the only completion rule available, and opening a video
        // and closing it is not watching a lesson.
        $table = new xmldb_table('kaivideo');

        foreach (['completionanswerall', 'completionwatched'] as $name) {
            $field = new xmldb_field($name, XMLDB_TYPE_INTEGER, '1', null,
                XMLDB_NOTNULL, null, '0', 'grade');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_mod_savepoint(true, 2026081101, 'kaivideo');
    }

    return true;
}
