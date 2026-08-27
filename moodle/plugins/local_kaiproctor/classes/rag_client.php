<?php
// Talking to the Indorama LMS assistant, which is a different service from the
// reviewer.
//
// Nothing was trained. The assistant that answers about the client's legacy LMS
// is retrieval plus tools plus checks over a prebuilt index — no fine-tuning,
// no weights, nothing learned into a model. That is why it can be pointed at
// from here at all: everything it knows lives in an index file and a set of
// tools on its own side, so switching this widget over is a matter of asking a
// different service, not of moving anything.
//
// The division of labour is therefore the opposite of ai_client's. There, this
// plugin builds the context — it is the only side that knows what a learner may
// open — and the service only writes prose. Here the service owns retrieval
// entirely, because what it retrieves from is its own corpus and Moodle knows
// nothing about it. So this client sends the question and no context at all,
// which is also why assistant.php is bypassed rather than adapted.

namespace local_kaiproctor;

defined('MOODLE_INTERNAL') || die();

class rag_client {

    /** Longer than the service's own RAG_AGENT_TIMEOUT.
     *
     *  An agent turn there is several model calls — one to choose a tool, one
     *  to read what it returned, sometimes a third after a guardrail catch. If
     *  this fired first, the service's own named failure would be replaced by
     *  a bare curl timeout that says nothing about which part gave up. */
    const TIMEOUT = 330;

    public static function is_configured(): bool {
        return trim((string) get_config('local_kaiproctor', 'ragbaseurl')) !== '';
    }

    /**
     * Ask one question. Conversation state lives on the service, keyed by user.
     *
     * @param string $question
     * @param int $userid
     * @param string $conversationid Empty to start a new conversation
     * @return array ok/answer/sources, or ok=false with a code
     */
    public static function ask(string $question, int $userid,
                               string $conversationid = ''): array {
        global $CFG;

        // Required explicitly rather than assumed. The curl class lives in
        // filelib and is not autoloaded; a web request usually has it already
        // because something else pulled it in, so the omission only shows up
        // from CLI — which is exactly where this was first called from, and it
        // failed with "Class curl not found" on a client that was otherwise
        // correct.
        require_once($CFG->libdir . '/filelib.php');

        $base = rtrim((string) get_config('local_kaiproctor', 'ragbaseurl'), '/');
        if ($base === '') {
            return self::fail('not_configured',
                get_string('ask:rag:notconfigured', 'local_kaiproctor'));
        }

        // The Moodle user id, not a name or an address.
        //
        // The service files conversations per user and needs something stable
        // to file them under. An id is stable, is already public within this
        // site, and tells the far side nothing about who the person is —
        // which matters because that service is deliberately built never to
        // hold personal data.
        $payload = ['user_id' => 'moodle_' . $userid, 'message' => $question];
        if ($conversationid !== '') {
            $payload['conversation_id'] = $conversationid;
        }

        // ignoresecurity, for the same reason ai_client sets it.
        //
        // Moodle's curl helper blocks requests to private and loopback
        // addresses, which is the right default for a URL a user supplied and
        // the wrong one for a service an administrator configured and we run
        // beside Moodle. Without it the request never leaves and the reply is
        // empty, which this client reported as "malformed" — a true statement
        // about the response and a useless one about the cause.
        // The service's shared key, when one is configured there. Sent as a
        // header rather than in the body so it never lands in a log line that
        // recorded the request payload.
        $headers = ['Content-Type: application/json'];
        $key = trim((string) get_config('local_kaiproctor', 'ragapikey'));
        if ($key !== '') {
            $headers[] = 'X-Agent-Key: ' . $key;
        }

        $curl = new \curl(['ignoresecurity' => true]);
        $response = $curl->post($base . '/chat', json_encode($payload), [
            'CURLOPT_HTTPHEADER' => $headers,
            'CURLOPT_TIMEOUT' => self::TIMEOUT,
            'CURLOPT_CONNECTTIMEOUT' => 10,
        ]);

        $info = $curl->get_info();
        if ($curl->get_errno()) {
            return self::fail('unreachable',
                get_string('ask:rag:unreachable', 'local_kaiproctor'));
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return self::fail('malformed',
                get_string('ask:rag:malformed', 'local_kaiproctor'));
        }

        if (empty($decoded['ok'])) {
            // Two audiences, two texts.
            //
            // The service's diagnostics are written for whoever is debugging
            // it — "the model kept producing an answer that was not plain
            // Thai, or that carried names or figures it was not given:
            // ci_sessions, email_queue" — and that sentence appeared verbatim
            // in a learner's chat box, in English, naming database tables. It
            // told them nothing they could act on and showed them the one
            // thing the whole design keeps out of answers.
            //
            // So the code is mapped to something a person can read, and the
            // detail goes to the log where the person who needs it will look.
            $code = (string) ($decoded['code'] ?? 'refused');
            debugging('local_kaiproctor rag refusal [' . $code . ']: '
                . (string) ($decoded['detail'] ?? ''), DEBUG_DEVELOPER);
            return self::fail($code, self::explain($code));
        }

        return [
            'ok' => true,
            'answer' => (string) $decoded['answer'],
            'conversationid' => (string) ($decoded['conversation_id'] ?? ''),
            // Named, not linked. These are tables and files in somebody else's
            // database; there is no page on this site to point at, and a link
            // that goes nowhere is worse than a label that does not pretend to.
            'sources' => array_map(static fn($ref) => [
                'title' => (string) $ref,
                'kind' => 'table',
            ], $decoded['sources'] ?? []),
        ];
    }

    /**
     * What to tell the person who asked, for each way this can go wrong.
     *
     * @param string $code
     * @return string
     */
    protected static function explain(string $code): string {
        $known = ['off_topic', 'no_material', 'ungrounded_answer', 'llm_timeout',
                  'llm_empty', 'llm_unreachable', 'tool_limit'];
        $key = 'ask:rag:' . (in_array($code, $known, true) ? $code : 'refused');
        return get_string($key, 'local_kaiproctor');
    }

    protected static function fail(string $code, string $message): array {
        return ['ok' => false, 'error' => ['code' => $code, 'message' => $message]];
    }
}
