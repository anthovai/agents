<?php
// Presence, head pose and liveness for a single frame.
//
// The active-liveness challenge polls this a few times a second, so it stays
// deliberately cheap: nothing is written to the database and no evidence is
// stored. A frame that contains no face is a normal answer, not an error.

namespace local_kaiproctor\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_kaiproctor\face_client;

class analyze_frame extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'imagedata' => new external_value(PARAM_RAW, 'Base64-encoded JPEG frame'),
        ]);
    }

    public static function execute(string $imagedata): array {
        ['imagedata' => $imagedata] = self::validate_parameters(
            self::execute_parameters(), ['imagedata' => $imagedata]
        );

        $context = \context_user::instance($GLOBALS['USER']->id);
        self::validate_context($context);
        require_capability('local/kaiproctor:enrolface', $context);

        $jpeg = base64_decode($imagedata, true);
        if ($jpeg === false || $jpeg === '') {
            return ['ok' => false, 'errorcode' => 'invalid_image', 'present' => false];
        }

        $result = face_client::analyze($jpeg);
        if (empty($result['ok'])) {
            return [
                'ok' => false,
                'errorcode' => $result['error']['code'] ?? 'unknown',
                'present' => false,
            ];
        }

        return [
            'ok' => true,
            'present' => !empty($result['present']),
            'reason' => $result['reason'] ?? null,
            'warning' => $result['warning'] ?? null,
            'yaw' => isset($result['pose']['yaw']) ? (float) $result['pose']['yaw'] : null,
            'pitch' => isset($result['pose']['pitch']) ? (float) $result['pose']['pitch'] : null,
            'roll' => isset($result['pose']['roll']) ? (float) $result['pose']['roll'] : null,
            'livenessevaluated' => !empty($result['liveness']['evaluated']),
            'livenessscore' => isset($result['liveness']['score'])
                ? (float) $result['liveness']['score'] : null,
            'live' => isset($result['liveness']['live']) ? (bool) $result['liveness']['live'] : null,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Whether the frame could be analysed'),
            'errorcode' => new external_value(PARAM_ALPHANUMEXT, 'Error code', VALUE_OPTIONAL),
            'present' => new external_value(PARAM_BOOL, 'Whether a usable face was found'),
            'reason' => new external_value(PARAM_ALPHANUMEXT, 'Why no face was usable', VALUE_OPTIONAL),
            'warning' => new external_value(PARAM_ALPHANUMEXT, 'Non-fatal warning, e.g. multiple_faces', VALUE_OPTIONAL),
            'yaw' => new external_value(PARAM_FLOAT, 'Head yaw in degrees', VALUE_OPTIONAL),
            'pitch' => new external_value(PARAM_FLOAT, 'Head pitch in degrees', VALUE_OPTIONAL),
            'roll' => new external_value(PARAM_FLOAT, 'Head roll in degrees', VALUE_OPTIONAL),
            'livenessevaluated' => new external_value(PARAM_BOOL, 'Whether liveness could be scored', VALUE_OPTIONAL),
            'livenessscore' => new external_value(PARAM_FLOAT, 'Liveness score 0..1', VALUE_OPTIONAL),
            'live' => new external_value(PARAM_BOOL, 'Whether the frame passed liveness', VALUE_OPTIONAL),
        ]);
    }
}
