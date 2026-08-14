<?php
// What this activity keeps about a person, and how to get rid of it.
//
// Answers and watch position are personal data. Small, but a learner asking
// for their record has to get them, and a learner asking to be erased has to
// lose them — which is why this exists at all rather than being waved off as
// "just a couple of columns".

namespace mod_kaivideo\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\core_userlist_provider,
        \core_privacy\local\request\plugin\provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('kaivideo_response', [
            'userid' => 'privacy:metadata:kaivideo_response:userid',
            'response' => 'privacy:metadata:kaivideo_response:response',
            'correct' => 'privacy:metadata:kaivideo_response:correct',
            'timecreated' => 'privacy:metadata:kaivideo_response:timecreated',
        ], 'privacy:metadata:kaivideo_response');

        $collection->add_database_table('kaivideo_progress', [
            'userid' => 'privacy:metadata:kaivideo_progress:userid',
            'furthest' => 'privacy:metadata:kaivideo_progress:furthest',
            'finished' => 'privacy:metadata:kaivideo_progress:finished',
            'timemodified' => 'privacy:metadata:kaivideo_progress:timemodified',
        ], 'privacy:metadata:kaivideo_progress');

        // Declared even though it holds nothing personal. A file area that is
        // never mentioned reads, to anybody auditing the plugin, as an area
        // somebody forgot to think about.
        $collection->add_subsystem_link('core_files', [],
            'privacy:metadata:filepurpose');

        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'kaivideo'
                  JOIN {kaivideo} k ON k.id = cm.instance
                  JOIN {context} ctx ON ctx.instanceid = cm.id
                                    AND ctx.contextlevel = :modulelevel
             LEFT JOIN {kaivideo_item} i ON i.kaivideoid = k.id
             LEFT JOIN {kaivideo_response} r ON r.itemid = i.id AND r.userid = :userid1
             LEFT JOIN {kaivideo_progress} p ON p.kaivideoid = k.id AND p.userid = :userid2
                 WHERE r.id IS NOT NULL OR p.id IS NOT NULL";

        $contextlist->add_from_sql($sql, [
            'modulelevel' => CONTEXT_MODULE,
            'userid1' => $userid,
            'userid2' => $userid,
        ]);
        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }

        $userlist->add_from_sql('userid', "
            SELECT r.userid
              FROM {course_modules} cm
              JOIN {kaivideo_item} i ON i.kaivideoid = cm.instance
              JOIN {kaivideo_response} r ON r.itemid = i.id
             WHERE cm.id = :cmid", ['cmid' => $context->instanceid]);

        $userlist->add_from_sql('userid', "
            SELECT p.userid
              FROM {course_modules} cm
              JOIN {kaivideo_progress} p ON p.kaivideoid = cm.instance
             WHERE cm.id = :cmid", ['cmid' => $context->instanceid]);
    }

    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('kaivideo', $context->instanceid);
            if (!$cm) {
                continue;
            }

            $answers = [];
            foreach (\mod_kaivideo\responses::latest((int) $cm->instance, $userid)
                    as $itemid => $answer) {
                $item = $DB->get_record('kaivideo_item', ['id' => $itemid]);
                $choices = $item ? (json_decode($item->choices, true) ?: []) : [];
                // Exported as what they said, not as an index into a list the
                // person would have to reconstruct. A subject access request
                // answered with "3" tells them nothing.
                $said = (string) $answer['response'];
                $indexes = json_decode($said, true);
                if (is_array($indexes)) {
                    $named = [];
                    foreach ($indexes as $index) {
                        $named[] = $choices[(int) $index] ?? '';
                    }
                    $said = implode(', ', $named);
                }

                $answers[] = [
                    'question' => $item->questiontext ?? '',
                    'at' => $item ? \mod_kaivideo\timeline::clock((float) $item->attime) : '',
                    'said' => $said,
                    'correct' => $answer['correct'] ? get_string('yes') : get_string('no'),
                ];
            }

            writer::with_context($context)->export_data([], (object) [
                'answers' => $answers,
                'progress' => \mod_kaivideo\responses::progress((int) $cm->instance, $userid),
            ]);
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('kaivideo', $context->instanceid);
        if (!$cm) {
            return;
        }

        $items = $DB->get_fieldset_select('kaivideo_item', 'id', 'kaivideoid = ?',
            [$cm->instance]);
        if ($items) {
            $DB->delete_records_list('kaivideo_response', 'itemid', $items);
        }
        $DB->delete_records('kaivideo_progress', ['kaivideoid' => $cm->instance]);
    }

    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('kaivideo', $context->instanceid);
            if (!$cm) {
                continue;
            }

            $items = $DB->get_fieldset_select('kaivideo_item', 'id', 'kaivideoid = ?',
                [$cm->instance]);
            if ($items) {
                [$insql, $params] = $DB->get_in_or_equal($items, SQL_PARAMS_NAMED);
                $params['userid'] = $userid;
                $DB->delete_records_select('kaivideo_response',
                    "itemid $insql AND userid = :userid", $params);
            }
            $DB->delete_records('kaivideo_progress',
                ['kaivideoid' => $cm->instance, 'userid' => $userid]);
        }
    }

    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('kaivideo', $context->instanceid);
        if (!$cm) {
            return;
        }

        [$usersql, $userparams] = $DB->get_in_or_equal($userlist->get_userids(),
            SQL_PARAMS_NAMED);

        $items = $DB->get_fieldset_select('kaivideo_item', 'id', 'kaivideoid = ?',
            [$cm->instance]);
        if ($items) {
            [$itemsql, $itemparams] = $DB->get_in_or_equal($items, SQL_PARAMS_NAMED);
            $DB->delete_records_select('kaivideo_response',
                "itemid $itemsql AND userid $usersql",
                array_merge($itemparams, $userparams));
        }

        $params = array_merge(['kaivideoid' => $cm->instance], $userparams);
        $DB->delete_records_select('kaivideo_progress',
            "kaivideoid = :kaivideoid AND userid $usersql", $params);
    }
}
