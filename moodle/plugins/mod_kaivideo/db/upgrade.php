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

    if ($oldversion < 2026081200) {
        // More than one kind of interruption: several right answers, a typed
        // answer, and a card that just says something and carries on.
        //
        // Two columns are replaced rather than added alongside. correctchoice
        // could hold one index and response one number, and keeping them next
        // to the general form would leave two places that answer the same
        // question — which is how a grader and a report end up disagreeing.
        $table = new xmldb_table('kaivideo_item');

        $answers = new xmldb_field('answers', XMLDB_TYPE_TEXT, null, null,
            null, null, null, 'choices');
        if (!$dbman->field_exists($table, $answers)) {
            $dbman->add_field($table, $answers);
        }

        // Every existing item is a single-answer question, whatever its type
        // column happened to say.
        foreach ($DB->get_records('kaivideo_item') as $item) {
            $DB->set_field('kaivideo_item', 'answers',
                json_encode([(int) $item->correctchoice]), ['id' => $item->id]);
            $DB->set_field('kaivideo_item', 'type', 'choice', ['id' => $item->id]);
        }

        $answers = new xmldb_field('answers', XMLDB_TYPE_TEXT, null, null,
            XMLDB_NOTNULL, null, null, 'choices');
        $dbman->change_field_notnull($table, $answers);

        $correctchoice = new xmldb_field('correctchoice');
        if ($dbman->field_exists($table, $correctchoice)) {
            $dbman->drop_field($table, $correctchoice);
        }

        // And what the learner sent stops being an integer.
        $responses = new xmldb_table('kaivideo_response');
        $response = new xmldb_field('response', XMLDB_TYPE_TEXT, null, null,
            null, null, null, 'userid');
        if (!$dbman->field_exists($responses, $response)) {
            $dbman->add_field($responses, $response);
        }

        foreach ($DB->get_records('kaivideo_response') as $row) {
            $DB->set_field('kaivideo_response', 'response',
                json_encode([(int) $row->choice]), ['id' => $row->id]);
        }

        $choice = new xmldb_field('choice');
        if ($dbman->field_exists($responses, $choice)) {
            $dbman->drop_field($responses, $choice);
        }

        upgrade_mod_savepoint(true, 2026081200, 'kaivideo');
    }

    if ($oldversion < 2026081700) {
        // Questions gain a category, and so does every answer to one.
        //
        // Two columns rather than a join, because they answer different
        // questions. The item's category is what it is filed under now; the
        // response's is what it was filed under when somebody answered it.
        // Recategorising a question next term must not rewrite what last
        // term's report said, in the same way that changing a threshold does
        // not rewrite the checks already recorded against the old one.
        $items = new xmldb_table('kaivideo_item');
        $category = new xmldb_field('category', XMLDB_TYPE_CHAR, '100', null,
            XMLDB_NOTNULL, null, '', 'type');
        if (!$dbman->field_exists($items, $category)) {
            $dbman->add_field($items, $category);
        }

        $responses = new xmldb_table('kaivideo_response');
        $answered = new xmldb_field('category', XMLDB_TYPE_CHAR, '100', null,
            XMLDB_NOTNULL, null, '', 'response');
        if (!$dbman->field_exists($responses, $answered)) {
            $dbman->add_field($responses, $answered);
        }

        // Existing answers keep the empty category their questions now carry.
        // Backfilling them from anywhere would be inventing a fact about the
        // past: nothing was categorised when they were given.

        upgrade_mod_savepoint(true, 2026081700, 'kaivideo');
    }

    return true;
}
