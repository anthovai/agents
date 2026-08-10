<?php
// Identity re-check against the learner's enrolled face.
//
// Unlike analyze_frame this always leaves a record: an identity check that
// happened but was not written down cannot be used as evidence later. A failed
// check also captures the frame, so a dispute has something to look at.

namespace local_kaiproctor\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_kaiproctor\checks;
use local_kaiproctor\enrolment;
use local_kaiproctor\evidence;
use local_kaiproctor\face_client;

class verify_frame extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Context the check belongs to'),
            'imagedata' => new external_value(PARAM_RAW, 'Base64-encoded JPEG frame'),
            'attemptid' => new external_value(PARAM_INT, 'Quiz attempt id, 0 if none', VALUE_DEFAULT, 0),
            'storeevidence' => new external_value(PARAM_BOOL, 'Keep the frame even when the check passes', VALUE_DEFAULT, false),
            'sessionid' => new external_value(PARAM_INT, 'The sitting this belongs to, 0 if none', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $contextid, string $imagedata, int $attemptid = 0,
                                   bool $storeevidence = false, int $sessionid = 0): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'imagedata' => $imagedata,
            'attemptid' => $attemptid,
            'storeevidence' => $storeevidence,
            'sessionid' => $sessionid,
        ]);

        $context = \context::instance_by_id($params['contextid']);
        self::validate_context($context);

        // A sitting id from the browser is a claim; it counts only if it really
        // is this learner's sitting in this context.
        $sessionid = \local_kaiproctor\session::validate(
            $params['sessionid'] ?: null, $USER->id, $context);
        if ($sessionid) {
            \local_kaiproctor\session::touch($sessionid);
        }

        $enrolled = enrolment::get_active($USER->id);
        if (!$enrolled) {
            return ['ok' => false, 'errorcode' => 'not_enrolled', 'decision' => 'fail'];
        }

        $jpeg = base64_decode($params['imagedata'], true);
        if ($jpeg === false || $jpeg === '') {
            return ['ok' => false, 'errorcode' => 'invalid_image', 'decision' => 'fail'];
        }

        $result = face_client::verify($jpeg, $enrolled->embedding);
        if (empty($result['ok'])) {
            $code = $result['error']['code'] ?? 'unknown';
            // A face that cannot be found is a presence problem, not proof of
            // impersonation — record it as such rather than as a mismatch.
            checks::record($USER->id, $context, 'identity', 'absent', null, null, null,
                $params['attemptid'] ?: null, ['errorcode' => $code], $sessionid);
            return ['ok' => false, 'errorcode' => $code, 'decision' => 'absent'];
        }

        $decision = (string) $result['decision'];
        $similarity = (float) $result['similarity'];
        $livenessscore = isset($result['liveness']['score'])
            ? (float) $result['liveness']['score'] : null;

        checks::record(
            $USER->id, $context, 'identity', $decision,
            $similarity, $livenessscore, $result['model_pack'] ?? null,
            $params['attemptid'] ?: null,
            ['det_score' => $result['det_score'] ?? null],
            $sessionid
        );

        $failed = in_array($decision, ['fail', 'fail_liveness'], true);
        if ($failed || $params['storeevidence']) {
            evidence::store($USER->id, $context, 'snapshot',
                $failed ? 'identity_' . $decision : 'identity_check',
                $jpeg, $params['attemptid'] ?: null, $sessionid);
        }

        return [
            'ok' => true,
            'decision' => $decision,
            'similarity' => $similarity,
            'livenessscore' => $livenessscore,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Whether the check could be performed'),
            'errorcode' => new external_value(PARAM_ALPHANUMEXT, 'Error code', VALUE_OPTIONAL),
            'decision' => new external_value(PARAM_ALPHANUMEXT, 'pass | review | fail | fail_liveness | absent'),
            'similarity' => new external_value(PARAM_FLOAT, 'Cosine similarity', VALUE_OPTIONAL),
            'livenessscore' => new external_value(PARAM_FLOAT, 'Liveness score 0..1', VALUE_OPTIONAL),
        ]);
    }
}
