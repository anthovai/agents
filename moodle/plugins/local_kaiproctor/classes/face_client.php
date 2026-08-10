<?php
// Client for the stateless face service.
//
// Every call is multipart because the service takes image uploads. Moodle's
// curl wrapper is used rather than raw curl so that proxy settings, the
// cURL security helper and the site's timeouts all apply.

namespace local_kaiproctor;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

class face_client {

    /** Seconds to wait for the face service. Long enough for CPU inference on
     * a cold model, short enough that a hung service does not hold a quiz page. */
    const TIMEOUT = 20;

    /** Parsing a few hundred exam questions out of a PDF is not a quiz page
     * and legitimately takes longer. */
    const PARSE_TIMEOUT = 120;

    /**
     * Thrown-free wrapper: returns ['ok' => false, 'error' => [...]] on any
     * failure so callers never have to distinguish transport errors from
     * service-level rejections.
     */
    protected static function post(string $path, array $fields, ?string $jpeg = null,
                                   int $timeout = self::TIMEOUT): array {
        $base = trim(get_config('local_kaiproctor', 'faceserviceurl') ?: '');
        if ($base === '') {
            return self::fail('not_configured', 'Face service URL is not set');
        }

        $tempfile = null;
        if ($jpeg !== null) {
            // \curl needs a file on disk for multipart uploads. The request
            // directory is cleaned up automatically at the end of the request.
            $tempfile = make_request_directory() . '/frame.jpg';
            file_put_contents($tempfile, $jpeg);
            $fields['image'] = new \CURLFile($tempfile, 'image/jpeg', 'frame.jpg');
        }

        // The face service sits on a private network, which Moodle's cURL
        // security helper blocks by default to prevent SSRF. That default is
        // right for user-supplied URLs and wrong here: this one comes from an
        // admin setting, so the exemption is scoped to this client rather than
        // weakening curlsecurityblockedhosts for the whole site.
        $curl = new \curl(['ignoresecurity' => true]);
        $key = (string) get_config('local_kaiproctor', 'apikey');
        if ($key !== '') {
            $curl->setHeader('X-Proctor-Key: ' . $key);
        }

        $response = $curl->post(
            rtrim($base, '/') . $path,
            $fields,
            ['CURLOPT_TIMEOUT' => $timeout, 'CURLOPT_CONNECTTIMEOUT' => 5]
        );

        if ($curl->get_errno()) {
            return self::fail('service_unreachable', $curl->error);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return self::fail('bad_response', 'Face service returned a non-JSON response');
        }
        return $decoded;
    }

    protected static function fail(string $code, string $message): array {
        return ['ok' => false, 'error' => ['code' => $code, 'message' => $message]];
    }

    /** Presence, head pose and liveness for one frame. */
    public static function analyze(string $jpeg): array {
        return self::post('/analyze', [], $jpeg);
    }

    /** Turn an enrolment photo into an embedding. */
    public static function embed(string $jpeg): array {
        return self::post('/embed', [], $jpeg);
    }

    /**
     * Turn an exam PDF into questions.
     *
     * Not face work; it lives on the same service because that is the Python
     * side of this system and the parser is Python that already works. See
     * face-service/app/exam_pdf.py.
     *
     * @param string $bytes the PDF
     * @return array
     */
    public static function parse_questions(string $bytes): array {
        $tempfile = make_request_directory() . '/questions.pdf';
        file_put_contents($tempfile, $bytes);

        return self::post('/parse-questions', [
            'file' => new \CURLFile($tempfile, 'application/pdf', 'questions.pdf'),
        ], null, self::PARSE_TIMEOUT);
    }

    /** Compare a live frame against a stored embedding. */
    public static function verify(string $jpeg, string $referenceembedding): array {
        // The image is passed under a different field name by this endpoint,
        // so post() cannot supply it via its $jpeg argument.
        $tempfile = make_request_directory() . '/live.jpg';
        file_put_contents($tempfile, $jpeg);

        return self::post('/verify', [
            'live_image' => new \CURLFile($tempfile, 'image/jpeg', 'live.jpg'),
            'reference_embedding' => $referenceembedding,
        ]);
    }
}
