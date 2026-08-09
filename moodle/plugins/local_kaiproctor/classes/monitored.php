<?php
// Which course modules are watched.
//
// The activities being watched belong to other plugins — mod_interactivevideo
// today, something else tomorrow. Storing the flag here rather than adding a
// field to their settings form is what keeps this an integration instead of a
// fork we have to carry.

namespace local_kaiproctor;

defined('MOODLE_INTERNAL') || die();

class monitored {

    /** Module types this plugin knows how to watch. */
    const SUPPORTED = ['interactivevideo', 'h5pactivity', 'page', 'resource', 'url'];

    public static function is_monitored(int $cmid): bool {
        global $DB;

        return $DB->record_exists('local_kaiproctor_monitored', ['cmid' => $cmid, 'enabled' => 1]);
    }

    public static function set(int $cmid, bool $enabled): void {
        global $DB;

        $existing = $DB->get_record('local_kaiproctor_monitored', ['cmid' => $cmid]);
        if ($existing) {
            $DB->update_record('local_kaiproctor_monitored', (object) [
                'id' => $existing->id,
                'enabled' => $enabled ? 1 : 0,
                'timemodified' => time(),
            ]);
            return;
        }

        $DB->insert_record('local_kaiproctor_monitored', (object) [
            'cmid' => $cmid,
            'enabled' => $enabled ? 1 : 0,
            'timemodified' => time(),
        ]);
    }

    public static function is_supported(string $modname): bool {
        return in_array($modname, self::SUPPORTED, true);
    }
}
