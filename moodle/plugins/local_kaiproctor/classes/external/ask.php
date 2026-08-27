<?php
// A learner asking where something is.
//
// Answers are built only from pages this user can already open, and the
// service is only called when something matched. Advisory throughout: nothing
// here reads a grade, and nothing here writes anything.

namespace local_kaiproctor\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_kaiproctor\assistant;
use local_kaiproctor\chat_history;
use local_kaiproctor\rag_client;

class ask extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'question' => new external_value(PARAM_TEXT, 'What the learner typed'),
            'conversationid' => new external_value(PARAM_ALPHANUMEXT,
                'Continue this conversation, when the source keeps them',
                VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(string $question,
                                   string $conversationid = ''): array {
        global $USER;

        // A local model can take a minute. Waiting on curl is not charged
        // against max_execution_time on Linux, but depending on that is
        // depending on a platform detail nobody reading this would check.
        \core_php_time_limit::raise(360);

        $params = self::validate_parameters(self::execute_parameters(),
            ['question' => $question, 'conversationid' => $conversationid]);

        // System context: the assistant searches across the learner's own
        // courses, so there is no single course context to validate against.
        // What bounds it is site_index, which only ever returns pages this
        // user can open.
        self::validate_context(\context_system::instance());

        // Which assistant this widget is wired to.
        //
        // Two different products behind one box. The Moodle one answers "where
        // is the page I need" from this learner's own courses; the Indorama one
        // answers about the structure of a different system entirely and knows
        // nothing about Moodle. They are not merged, and neither is adapted
        // into the other — the setting picks which service the question goes
        // to, and the one not picked is not consulted.
        $source = get_config('local_kaiproctor', 'asksource') ?: 'moodle';
        $conversation = '';

        // The conversation is recorded whichever assistant answers.
        //
        // Started before the answer exists, and the question written into it
        // first. If the model fails, the turn that caused it is still in the
        // transcript — and a transcript that silently drops the questions that
        // went wrong is the transcript somebody is reading precisely because
        // something went wrong.
        $convoid = (int) $params['conversationid'];
        if ($convoid && !chat_history::owns($USER->id, $convoid)) {
            $convoid = 0;
        }
        if (!$convoid) {
            $convoid = chat_history::start($USER->id, $params['question']);
        }
        try {
            chat_history::append($USER->id, $convoid, 'user', $params['question']);
        } catch (\moodle_exception $e) {
            return [
                'ok' => false,
                'errorcode' => 'overquota',
                'message' => $e->getMessage(),
                'sources' => [],
            ];
        }

        if ($source === 'indorama') {
            // Staff only, unless somebody has decided otherwise.
            //
            // The Indorama assistant answers about a database schema, which is
            // useful to whoever maintains the system and odd to put in front of
            // a learner — so the restriction is the default. It is a setting
            // rather than a hard rule because who should be allowed to ask is a
            // decision for whoever runs the site, and the first version of this
            // simply blocked every learner with a permissions error that told
            // them nothing about why.
            //
            // Turning it off does not widen what the assistant can see. It
            // never reads a row of data either way; this governs who may ask.
            if (get_config('local_kaiproctor', 'ragstaffonly') !== '0') {
                require_capability('local/kaiproctor:manage',
                    \context_system::instance());
            }

            // The service keeps its own conversation state and hands back an
            // id for it. Sending that id with the follow-up is what makes
            // "แล้วตารางนี้..." refer to the table from the previous turn;
            // without it every question started the conversation over and the
            // service's memory of earlier turns went unused.
            $remoteid = chat_history::remote_id($USER->id, $convoid);
            $result = rag_client::ask($params['question'], (int) $USER->id,
                $remoteid);
            if (!empty($result['conversationid'])) {
                chat_history::set_remote_id($USER->id, $convoid,
                    $result['conversationid']);
            }

            // The prose is the answer; the source labels are table and file
            // names. Shown under every reply they read as data being spilled
            // into the chat, so they are attached only when the question asked
            // where something is — a page, a link, a route.
            if (!empty($result['ok']) && !self::asked_where($params['question'])) {
                $result['sources'] = [];
            }
        } else {
            $result = assistant::answer($params['question'], (int) $USER->id);
        }

        if (empty($result['ok'])) {
            chat_history::append($USER->id, $convoid, 'assistant',
                '[' . ($result['error']['code'] ?? 'unknown') . '] '
                . ($result['error']['message'] ?? ''));
            return [
                'ok' => false,
                'conversationid' => (string) $convoid,
                'errorcode' => $result['error']['code'] ?? 'unknown',
                'message' => $result['error']['message'] ?? '',
                'sources' => [],
            ];
        }

        chat_history::append($USER->id, $convoid, 'assistant', $result['answer'],
            array_column($result['sources'] ?? [], 'title'));

        return [
            'ok' => true,
            'answer' => $result['answer'],
            'model' => $result['model'] ?? '',
            'conversationid' => (string) $convoid,
            // Returned so the page can show the links as links, rather than
            // leaving the learner to pick them out of a paragraph.
            // url is optional now: an Indorama source is a table in somebody
            // else's database and has no page on this site to link to.
            'sources' => array_map(static fn($item) => array_filter([
                'title' => $item['title'],
                'url' => $item['url'] ?? '',
                'kind' => $item['kind'],
            ], static fn($v) => $v !== ''), $result['sources'] ?? []),
        ];
    }

    /**
     * Whether the question asks where something is — a page, a link, a route.
     *
     * Decides only whether the source labels ride along with the answer, so a
     * miss costs a set of labels, not an answer. Erring towards showing them
     * is the right direction: labels somebody did not ask for are clutter,
     * labels somebody did ask for and was refused are a dead end.
     *
     * @param string $question
     * @return bool
     */
    protected static function asked_where(string $question): bool {
        $lower = \core_text::strtolower($question);
        $asks = ['หน้า', 'ลิงก์', 'ลิ้ง', 'ที่ไหน', 'ตรงไหน', 'เปิดยังไง',
            'link', 'url', 'route', 'endpoint', 'path', 'page', 'where'];
        foreach ($asks as $word) {
            if (\core_text::strpos($lower, $word) !== false) {
                return true;
            }
        }
        return false;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Whether an answer was produced'),
            'errorcode' => new external_value(PARAM_ALPHANUMEXT, 'Error code', VALUE_OPTIONAL),
            'message' => new external_value(PARAM_RAW, 'What went wrong', VALUE_OPTIONAL),
            'answer' => new external_value(PARAM_RAW, 'The answer', VALUE_OPTIONAL),
            'model' => new external_value(PARAM_RAW, 'Which model wrote it', VALUE_OPTIONAL),
            'conversationid' => new external_value(PARAM_ALPHANUMEXT,
                'The stored conversation this turn was filed under; pass back '
                . 'to keep adding to it', VALUE_OPTIONAL),
            'sources' => new external_multiple_structure(
                new external_single_structure([
                    'title' => new external_value(PARAM_TEXT, 'Page title'),
                    'url' => new external_value(PARAM_URL, 'Where it is, when it is on this site',
                        VALUE_OPTIONAL),
                    'kind' => new external_value(PARAM_ALPHA, 'What kind of page'),
                ])
            ),
        ]);
    }
}
