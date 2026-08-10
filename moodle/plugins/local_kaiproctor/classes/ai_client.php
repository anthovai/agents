<?php
// Talking to the LLM gateway.
//
// Two rules are enforced here rather than left to callers, because a caller
// that gets them wrong causes a data-protection incident, not a bug:
//
//   1. Only text goes out. No photograph, no clip, no face embedding, ever.
//      The consent document tells learners their biometric data goes to a
//      stateless service that keeps nothing; sending it to a language model
//      instead would make that statement false.
//
//   2. Nothing that comes back decides anything. Every response is advice for
//      a human reviewer, stored and displayed as such. A proctoring decision
//      that a person cannot trace to a rule is not evidence.
//
// The endpoint is OpenAI-compatible, so the gateway can put GPT-5 mini or a
// model on our own hardware behind it without this file changing.

namespace local_kaiproctor;

defined('MOODLE_INTERNAL') || die();

class ai_client {

    /** A reviewer is waiting for this, but not forever. */
    const TIMEOUT = 60;

    public static function is_configured(): bool {
        return (bool) get_config('local_kaiproctor', 'aienabled')
            && trim((string) get_config('local_kaiproctor', 'aibaseurl')) !== '';
    }

    /**
     * Ask the model for a structured answer.
     *
     * @param string $system what the model is being asked to be
     * @param string $user the material, already stripped of anything personal
     * @return array {ok, content} or {ok:false, error}
     */
    public static function ask(string $system, string $user): array {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');

        if (!self::is_configured()) {
            return self::fail('not_configured',
                get_string('ai:notconfigured', 'local_kaiproctor'));
        }

        $base = rtrim(trim((string) get_config('local_kaiproctor', 'aibaseurl')), '/');
        $model = trim((string) get_config('local_kaiproctor', 'aimodel')) ?: 'proctor-reviewer';

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            // Low, not zero: a summary that reads like a person wrote it is
            // more useful than one that reads like a template, and nothing
            // here is a decision that needs determinism.
            'temperature' => 0.2,
            'max_tokens' => 700,
        ];

        // Same SSRF exemption as the face service, for the same reason: the
        // URL is an admin setting pointing at our own network, not user input.
        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader('Content-Type: application/json');
        $key = (string) get_config('local_kaiproctor', 'aiapikey');
        if ($key !== '') {
            $curl->setHeader('Authorization: Bearer ' . $key);
        }

        $response = $curl->post($base . '/chat/completions', json_encode($payload),
            ['CURLOPT_TIMEOUT' => self::TIMEOUT, 'CURLOPT_CONNECTTIMEOUT' => 5]);

        if ($curl->get_errno()) {
            return self::fail('unreachable', $curl->error);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return self::fail('bad_response',
                get_string('ai:badresponse', 'local_kaiproctor'));
        }
        if (isset($decoded['error'])) {
            return self::fail('rejected', (string) ($decoded['error']['message'] ?? ''));
        }

        $content = $decoded['choices'][0]['message']['content'] ?? '';
        if (trim($content) === '') {
            return self::fail('empty', get_string('ai:emptyresponse', 'local_kaiproctor'));
        }

        return ['ok' => true, 'content' => $content, 'model' => $decoded['model'] ?? $model];
    }

    protected static function fail(string $code, string $message): array {
        return ['ok' => false, 'error' => ['code' => $code, 'message' => $message]];
    }
}
