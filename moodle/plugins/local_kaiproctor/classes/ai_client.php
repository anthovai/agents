<?php
// Talking to the AI reviewer service.
//
// This used to call an OpenAI-compatible endpoint directly, with the prompts
// and the rules about what may be sent living here, in the plugin. That works
// while we own both ends. It stops working the moment a customer takes the
// plugin source and maintains their own copy: the guardrails become theirs to
// edit, and the payload becomes theirs to widen, while the summaries still
// carry our name.
//
// So the plugin now posts a documented payload to a service we run, and the
// service decides what to do with it — including refusing payloads that carry
// anything derived from a face. The boundary is the product; see
// ai-service/app/contract.py.
//
// What stays true either way: only text goes out, and nothing that comes back
// decides anything.

namespace local_kaiproctor;

defined('MOODLE_INTERNAL') || die();

class ai_client {

    /** A reviewer is waiting for this, but not forever. A model on local
     *  hardware is slower than a hosted one, so this is not tight. */
    const TIMEOUT = 150;

    /** The payload shape this plugin speaks. The service checks it, and
     *  still accepts 1.0 — but declaring the version actually being sent is
     *  the point of sending one. */
    const CONTRACT = '1.1';

    public static function is_configured(): bool {
        return (bool) get_config('local_kaiproctor', 'aienabled')
            && trim((string) get_config('local_kaiproctor', 'aibaseurl')) !== '';
    }

    /**
     * Post a payload to the reviewer service.
     *
     * @param string $path e.g. '/summarise'
     * @param array $body already restricted to what the contract allows
     * @return array whatever the service returned, or {ok:false, error}
     */
    public static function call(string $path, array $body): array {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');

        if (!self::is_configured()) {
            return self::fail('not_configured',
                get_string('ai:notconfigured', 'local_kaiproctor'));
        }

        $base = rtrim(trim((string) get_config('local_kaiproctor', 'aibaseurl')), '/');
        $body['contract'] = self::CONTRACT;

        // Same SSRF exemption as the face service, for the same reason: the
        // URL is an admin setting pointing at our own network, not user input.
        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader('Content-Type: application/json');
        $key = (string) get_config('local_kaiproctor', 'aiapikey');
        if ($key !== '') {
            $curl->setHeader('X-Proctor-Key: ' . $key);
        }

        $response = $curl->post($base . $path, json_encode($body),
            ['CURLOPT_TIMEOUT' => self::TIMEOUT, 'CURLOPT_CONNECTTIMEOUT' => 5]);

        if ($curl->get_errno()) {
            return self::fail('unreachable', $curl->error);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return self::fail('bad_response',
                get_string('ai:badresponse', 'local_kaiproctor'));
        }

        // The service already reports failures in this shape, including the
        // ones it raises itself — a refused payload, or a model that reached a
        // verdict — so they are passed through with their diagnosis intact.
        return $decoded;
    }

    /** Whether the service answers at all, and what is behind it. */
    public static function health(): array {
        if (!self::is_configured()) {
            return self::fail('not_configured', '');
        }
        return self::health_at(rtrim(trim(
            (string) get_config('local_kaiproctor', 'aibaseurl')), '/'));
    }

    /**
     * Health of a service at a given address, regardless of the on/off switch.
     *
     * Separate from health() because the console has to show an administrator
     * what they would be switching on. Asking them to enable it first, look,
     * and disable it again if the answer is wrong is how a feature gets turned
     * on for a few minutes on a live site by somebody who only wanted to read.
     *
     * @param string $baseurl
     * @return array
     */
    public static function health_at(string $baseurl): array {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');

        $baseurl = rtrim(trim($baseurl), '/');
        if ($baseurl === '') {
            return self::fail('not_configured', '');
        }

        $curl = new \curl(['ignoresecurity' => true]);
        $response = $curl->get($baseurl . '/health',
            [], ['CURLOPT_TIMEOUT' => 10, 'CURLOPT_CONNECTTIMEOUT' => 5]);

        if ($curl->get_errno()) {
            return self::fail('unreachable', $curl->error);
        }
        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded
            : self::fail('bad_response', get_string('ai:badresponse', 'local_kaiproctor'));
    }

    protected static function fail(string $code, string $message): array {
        return ['ok' => false, 'error' => ['code' => $code, 'message' => $message]];
    }
}
