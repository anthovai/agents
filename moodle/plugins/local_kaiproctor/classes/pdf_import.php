<?php
// Import a Thai licence-exam PDF into a Moodle question bank.
//
// The parsing is done by the Python side-car, which already had the tested
// logic for these packs: broken Thai font repairs, ก/ข/ค/ง choices, and answer
// keys headed "คำตอบ : วิชา". Getting the questions back as data and building
// Moodle XML from them means Moodle's own importer does the writing, so the
// questions end up indistinguishable from ones imported any other way.
//
// Difficulty has no native home in a Moodle question, so it becomes a tag.
// That is not a workaround: a quiz can draw random questions by tag, which is
// exactly the difficulty blueprint the original system had.

namespace local_kaiproctor;

defined('MOODLE_INTERNAL') || die();

class pdf_import {

    const DIFFICULTIES = ['easy', 'medium', 'hard'];

    /**
     * Ask the side-car to turn a PDF into questions.
     *
     * @param string $bytes the uploaded file
     * @return array {ok, questions, title, note, count} or {ok:false, error}
     */
    public static function parse(string $bytes): array {
        return face_client::parse_questions($bytes);
    }

    /**
     * Build Moodle question XML from parsed questions.
     *
     * XML rather than GIFT: GIFT gives ~ = # { } : special meaning, and these
     * are exam questions full of punctuation in a language nobody importing
     * them will want to hand-escape.
     *
     * @param array $questions
     * @param string $categorypath
     * @return string
     */
    public static function to_moodle_xml(array $questions, string $categorypath): string {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<quiz>\n";

        $xml .= "  <question type=\"category\">\n";
        $xml .= "    <category><text>" . htmlspecialchars($categorypath, ENT_XML1) . "</text></category>\n";
        $xml .= "  </question>\n";

        foreach ($questions as $question) {
            $choices = $question['choices'] ?? [];
            $answer = (int) ($question['answer'] ?? -1);
            if (count($choices) < 2 || $answer < 0 || $answer >= count($choices)) {
                // A question whose key does not point at one of its own
                // choices is not a question; skip rather than import a broken
                // one that would silently mark everybody wrong.
                continue;
            }

            $difficulty = in_array($question['difficulty'] ?? '', self::DIFFICULTIES, true)
                ? $question['difficulty'] : 'medium';

            $xml .= "  <question type=\"multichoice\">\n";
            $xml .= "    <name><text>" . self::escape($question['id'] ?? '') . "</text></name>\n";
            $xml .= "    <questiontext format=\"html\"><text>"
                 . self::escape($question['text'] ?? '') . "</text></questiontext>\n";
            $xml .= "    <defaultgrade>1</defaultgrade>\n";
            $xml .= "    <single>true</single>\n";
            $xml .= "    <shuffleanswers>true</shuffleanswers>\n";
            $xml .= "    <answernumbering>abc</answernumbering>\n";

            foreach ($choices as $index => $choice) {
                $fraction = $index === $answer ? '100' : '0';
                $xml .= "    <answer fraction=\"{$fraction}\" format=\"html\">\n";
                $xml .= "      <text>" . self::escape((string) $choice) . "</text>\n";
                $xml .= "    </answer>\n";
            }

            $xml .= "    <tags>\n";
            $xml .= "      <tag><text>" . self::escape($difficulty) . "</text></tag>\n";
            $xml .= "      <tag><text>pdfimport</text></tag>\n";
            $xml .= "    </tags>\n";
            $xml .= "  </question>\n";
        }

        return $xml . "</quiz>\n";
    }

    protected static function escape(string $value): string {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Import parsed questions into a category, through Moodle's own importer.
     *
     * @param array $questions
     * @param \stdClass $category
     * @param \context $bankcontext
     * @param \stdClass $course
     * @return array {ok, imported, skipped, messages}
     */
    public static function import(array $questions, \stdClass $category,
                                  \context $bankcontext, \stdClass $course): array {
        global $CFG;

        require_once($CFG->dirroot . '/question/format.php');
        require_once($CFG->dirroot . '/question/format/xml/format.php');

        $usable = array_filter($questions, static function ($question) {
            $choices = $question['choices'] ?? [];
            $answer = (int) ($question['answer'] ?? -1);
            return count($choices) >= 2 && $answer >= 0 && $answer < count($choices);
        });

        if (!$usable) {
            return [
                'ok' => false,
                'imported' => 0,
                'skipped' => count($questions),
                'messages' => [get_string('import:nousable', 'local_kaiproctor')],
            ];
        }

        $path = make_request_directory() . '/questions.xml';
        file_put_contents($path, self::to_moodle_xml(array_values($usable), $category->name));

        $format = new \qformat_xml();
        $format->setCategory($category);
        $format->setContexts([$bankcontext]);
        $format->setCourse($course);
        $format->setFilename($path);
        $format->setRealfilename('questions.xml');
        // 'error' or 'nearest' are the only values the importer accepts. Every
        // question here is worth one mark, so nothing needs adjusting, but a
        // pack that somehow carried an odd grade should fail loudly rather
        // than be silently rounded.
        $format->setMatchgrades('error');
        $format->setCatfromfile(false);
        $format->setContextfromfile(false);
        $format->setStoponerror(false);
        $format->setCattofile(false);
        $format->setContexttofile(false);

        // The importer writes progress straight to output; a page rendering a
        // form does not want a wall of it in the middle.
        ob_start();
        $ok = $format->importpreprocess()
            && $format->importprocess()
            && $format->importpostprocess();
        $output = ob_get_clean();

        return [
            'ok' => (bool) $ok,
            'imported' => $ok ? count($usable) : 0,
            'skipped' => count($questions) - count($usable),
            'messages' => $ok ? [] : [strip_tags($output)],
        ];
    }

    /**
     * How many questions of each difficulty are in a parsed set.
     *
     * @param array $questions
     * @return array
     */
    public static function difficulty_counts(array $questions): array {
        $counts = array_fill_keys(self::DIFFICULTIES, 0);
        foreach ($questions as $question) {
            $difficulty = $question['difficulty'] ?? 'medium';
            if (isset($counts[$difficulty])) {
                $counts[$difficulty]++;
            }
        }
        return $counts;
    }
}
