<?php
// Site-wide proctoring figures.
//
// Written to answer the questions an administrator actually has — is the
// service up, is anything being recorded, how much evidence is accumulating,
// is the retention policy running — rather than to fill a dashboard.

namespace local_kaiproctor;

defined('MOODLE_INTERNAL') || die();

class stats {

    /**
     * @return array template context for local_kaiproctor/stats
     */
    public static function build(): array {
        global $DB, $CFG;

        require_once($CFG->libdir . '/filelib.php');

        $decisions = $DB->get_records_sql(
            "SELECT decision, COUNT(1) AS total
               FROM {local_kaiproctor_check}
              WHERE kind = 'identity'
           GROUP BY decision
           ORDER BY total DESC"
        );

        $sessionstatuses = $DB->get_records_sql(
            "SELECT status, COUNT(1) AS total
               FROM {local_kaiproctor_session}
           GROUP BY status
           ORDER BY total DESC"
        );

        $evidencebytes = (int) $DB->get_field_sql(
            "SELECT COALESCE(SUM(f.filesize), 0)
               FROM {files} f
              WHERE f.component = :component AND f.filearea = :filearea
                AND f.filename <> '.'",
            ['component' => evidence::COMPONENT, 'filearea' => evidence::FILEAREA]
        );

        $retention = (int) get_config('local_kaiproctor', 'retentiondays');
        $oldest = $DB->get_field_sql(
            'SELECT MIN(timecreated) FROM {local_kaiproctor_evidence}');

        $purgetask = $DB->get_record('task_scheduled',
            ['classname' => '\local_kaiproctor\task\purge_evidence']);

        return [
            'enrolled' => $DB->count_records_select('local_kaiproctor_face', 'active = 1'),
            'sessions' => $DB->count_records('local_kaiproctor_session'),
            'sessionstatuses' => array_map(static fn($row) => [
                'status' => $row->status,
                'label' => get_string('session:' . $row->status, 'local_kaiproctor'),
                'total' => (int) $row->total,
            ], array_values($sessionstatuses)),
            'checks' => $DB->count_records('local_kaiproctor_check'),
            'decisions' => array_map(static fn($row) => [
                'decision' => $row->decision,
                'total' => (int) $row->total,
                // A run of failures is the thing worth noticing at a glance.
                'concerning' => in_array($row->decision, ['fail', 'fail_liveness'], true),
            ], array_values($decisions)),
            'evidencecount' => $DB->count_records('local_kaiproctor_evidence'),
            'evidencesize' => display_size($evidencebytes),
            'monitored' => $DB->count_records('local_kaiproctor_monitored', ['enabled' => 1]),
            'proctoredquizzes' => $DB->count_records('quizaccess_kaiproctor', ['enabled' => 1]),
            'retentiondays' => $retention,
            'oldestevidence' => $oldest ? userdate($oldest) : '',
            'hasoldest' => (bool) $oldest,
            // Evidence older than the retention window means the purge task is
            // not running — the kind of thing that is invisible until somebody
            // asks why there is a year of faces on disk.
            'overdue' => $oldest && $retention > 0 && $oldest < (time() - ($retention * DAYSECS)),
            'purgelastrun' => $purgetask && $purgetask->lastruntime
                ? userdate($purgetask->lastruntime) : '',
            'purgeneverrun' => !$purgetask || !$purgetask->lastruntime,
            'service' => self::service_health(),
        ];
    }

    /**
     * Whether the face service is reachable, and what it has loaded.
     *
     * @return array
     */
    protected static function service_health(): array {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');

        $base = trim((string) get_config('local_kaiproctor', 'faceserviceurl'));
        if ($base === '') {
            return ['ok' => false, 'problem' => get_string('stats:noserviceurl', 'local_kaiproctor')];
        }

        $curl = new \curl(['ignoresecurity' => true]);
        $key = (string) get_config('local_kaiproctor', 'apikey');
        if ($key !== '') {
            $curl->setHeader('X-Proctor-Key: ' . $key);
        }
        $response = $curl->get(rtrim($base, '/') . '/health',
            [], ['CURLOPT_TIMEOUT' => 5, 'CURLOPT_CONNECTTIMEOUT' => 3]);

        if ($curl->get_errno()) {
            return ['ok' => false, 'problem' => $curl->error];
        }

        $health = json_decode($response, true);
        if (!is_array($health) || empty($health['ok'])) {
            return ['ok' => false, 'problem' => get_string('stats:badresponse', 'local_kaiproctor')];
        }

        return [
            'ok' => true,
            'version' => $health['service_version'] ?? '',
            'modelpack' => $health['model_pack'] ?? '',
            'models' => count($health['models_present'] ?? []),
            // Liveness silently unavailable means every check reports "not
            // evaluated" and a photograph held to the camera would pass.
            'liveness' => !empty($health['liveness_available']),
            'matchthreshold' => $health['thresholds']['match'] ?? null,
        ];
    }
}
