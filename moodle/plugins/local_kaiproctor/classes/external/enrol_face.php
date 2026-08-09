<?php
// Enrolment: turn the learner's photo into their reference embedding.
//
// The active-liveness challenge record travels with it and is stored verbatim.
// Without that record an enrolment is just "a face was submitted"; with it,
// there is evidence that a live person performed a randomised sequence.

namespace local_kaiproctor\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_kaiproctor\checks;
use local_kaiproctor\enrolment;
use local_kaiproctor\face_client;

class enrol_face extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'imagedata' => new external_value(PARAM_RAW, 'Base64-encoded JPEG of the enrolment photo'),
            'challenge' => new external_value(PARAM_RAW, 'JSON record of the liveness challenge'),
        ]);
    }

    public static function execute(string $imagedata, string $challenge): array {
        global $USER;

        ['imagedata' => $imagedata, 'challenge' => $challenge] = self::validate_parameters(
            self::execute_parameters(),
            ['imagedata' => $imagedata, 'challenge' => $challenge]
        );

        $context = \context_user::instance($USER->id);
        self::validate_context($context);
        require_capability('local/kaiproctor:enrolface', $context);

        $jpeg = base64_decode($imagedata, true);
        if ($jpeg === false || $jpeg === '') {
            return ['ok' => false, 'errorcode' => 'invalid_image'];
        }

        $decodedchallenge = json_decode($challenge, true);
        if (!is_array($decodedchallenge)) {
            // An enrolment whose challenge cannot be read is not evidence of
            // anything, so it is refused rather than stored without one.
            return ['ok' => false, 'errorcode' => 'invalid_challenge'];
        }

        $result = face_client::embed($jpeg);
        if (empty($result['ok'])) {
            return ['ok' => false, 'errorcode' => $result['error']['code'] ?? 'unknown'];
        }

        enrolment::store(
            $USER->id,
            $result['embedding'],
            (int) $result['dimensions'],
            (string) ($result['model_pack'] ?? ''),
            $decodedchallenge
        );

        checks::record(
            $USER->id, $context, 'enrolment', 'pass',
            null,
            isset($result['liveness']['score']) ? (float) $result['liveness']['score'] : null,
            $result['model_pack'] ?? null,
            null,
            ['det_score' => $result['det_score'] ?? null]
        );

        return ['ok' => true];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Whether the face was enrolled'),
            'errorcode' => new external_value(PARAM_ALPHANUMEXT, 'Error code', VALUE_OPTIONAL),
        ]);
    }
}
