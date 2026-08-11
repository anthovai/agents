<?php
// The backup task for one interactive video.

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/kaivideo/backup/moodle2/backup_kaivideo_stepslib.php');

class backup_kaivideo_activity_task extends backup_activity_task {

    protected function define_my_settings() {
    }

    protected function define_my_steps() {
        $this->add_step(new backup_kaivideo_activity_structure_step(
            'kaivideo_structure', 'kaivideo.xml'));
    }

    /**
     * Rewrite links to this activity so a restored copy points at itself.
     *
     * @param string $content
     * @return string
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        $content = preg_replace(
            '/(' . $base . '\/mod\/kaivideo\/index.php\?id\=)([0-9]+)/',
            '$@KAIVIDEOINDEX*$2@$', $content);
        $content = preg_replace(
            '/(' . $base . '\/mod\/kaivideo\/view.php\?id\=)([0-9]+)/',
            '$@KAIVIDEOVIEWBYID*$2@$', $content);

        return $content;
    }
}
