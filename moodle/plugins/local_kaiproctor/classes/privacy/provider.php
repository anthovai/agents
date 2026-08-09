<?php
// Privacy API provider.
//
// Moodle refuses to consider a site GDPR/PDPA-compliant unless every plugin
// declares what personal data it holds and can delete it on request. Because
// this plugin holds biometric data, getting this right is the whole reason the
// project sits on Moodle rather than a bespoke consent table.

namespace local_kaiproctor\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_kaiproctor_face', [
            'userid' => 'privacy:metadata:face:userid',
            'embedding' => 'privacy:metadata:face:embedding',
            'timecreated' => 'privacy:metadata:face:timecreated',
        ], 'privacy:metadata:face');

        $collection->add_database_table('local_kaiproctor_check', [
            'userid' => 'privacy:metadata:check:userid',
            'similarity' => 'privacy:metadata:check:similarity',
            'decision' => 'privacy:metadata:check:decision',
            'timecreated' => 'privacy:metadata:check:timecreated',
        ], 'privacy:metadata:check');

        $collection->add_database_table('local_kaiproctor_evidence', [
            'userid' => 'privacy:metadata:evidence:userid',
            'reason' => 'privacy:metadata:evidence:reason',
            'timecreated' => 'privacy:metadata:evidence:timecreated',
        ], 'privacy:metadata:evidence');

        // Images leave the site to be analysed, so this has to be declared even
        // though the receiving service stores nothing.
        $collection->add_external_location_link('faceservice', [
            'image' => 'privacy:metadata:faceservice:image',
        ], 'privacy:metadata:faceservice');

        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Face enrolment is site-wide; checks and evidence are tied to wherever
        // they were captured.
        $contextlist->add_from_sql(
            "SELECT DISTINCT contextid FROM {local_kaiproctor_check} WHERE userid = :userid",
            ['userid' => $userid]
        );
        $contextlist->add_from_sql(
            "SELECT DISTINCT contextid FROM {local_kaiproctor_evidence} WHERE userid = :userid",
            ['userid' => $userid]
        );

        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        $userlist->add_from_sql(
            'userid',
            "SELECT userid FROM {local_kaiproctor_check} WHERE contextid = :contextid",
            ['contextid' => $context->id]
        );
        $userlist->add_from_sql(
            'userid',
            "SELECT userid FROM {local_kaiproctor_evidence} WHERE contextid = :contextid",
            ['contextid' => $context->id]
        );
    }

    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $checks = $DB->get_records('local_kaiproctor_check',
                ['userid' => $userid, 'contextid' => $context->id], 'timecreated ASC');
            if ($checks) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_kaiproctor')],
                    (object) ['checks' => array_values($checks)]
                );
            }

            // Evidence files are exported through the file API so the learner
            // receives the actual images, not just a row saying they exist.
            writer::with_context($context)->export_area_files(
                [get_string('pluginname', 'local_kaiproctor')],
                'local_kaiproctor', 'evidence', 0
            );
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        $DB->delete_records('local_kaiproctor_check', ['contextid' => $context->id]);
        $DB->delete_records('local_kaiproctor_evidence', ['contextid' => $context->id]);
        get_file_storage()->delete_area_files($context->id, 'local_kaiproctor', 'evidence');
    }

    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $DB->delete_records('local_kaiproctor_check',
                ['userid' => $userid, 'contextid' => $context->id]);
            // Through evidence::delete_for_user so the stored photographs and
            // clips go with the rows. Deleting the rows alone would satisfy a
            // erasure request on paper while leaving the learner's face on disk.
            \local_kaiproctor\evidence::delete_for_user($userid, $context->id);
        }

        // The face embedding is not context-bound: an erasure request must take
        // it out entirely, or the learner stays identifiable.
        $DB->delete_records('local_kaiproctor_face', ['userid' => $userid]);
    }

    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['contextid'] = $context->id;

        $DB->delete_records_select('local_kaiproctor_check',
            "userid $insql AND contextid = :contextid", $params);

        // Per user, so the files are removed alongside their rows.
        foreach ($userids as $userid) {
            \local_kaiproctor\evidence::delete_for_user((int) $userid, $context->id);
        }
    }
}
