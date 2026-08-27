<?php
// Conversations with the assistant, kept as Markdown the person who owns them
// can read, export and delete.
//
// The same design as the standalone service's app/memory.py, moved here
// because this is where the people are. The reasoning transfers whole:
//
//   * **Markdown is the promise, so the file is the copy that must survive.**
//     A format people can read is a format people can keep, and the point of
//     choosing it is that a transcript outlives this plugin.
//   * **A row beside the file, not instead of it.** Listing somebody's
//     conversations and totalling what they use has to work without opening
//     and parsing every file they own. The row is an index; delete it and it
//     can be rebuilt from the files. Delete a file and nothing brings it back.
//   * **Over quota refuses, it does not truncate.** A conversation that
//     quietly stopped recording looks exactly like one nobody continued.
//
// What is different here is where the bytes live. The standalone service wrote
// into a directory it owned; a Moodle plugin that did that would sit outside
// backup, outside the file API's access checks, and outside the Privacy API's
// reach — so the file goes into Moodle's own file storage, where deleting a
// user really deletes it.

namespace local_kaiproctor;

defined('MOODLE_INTERNAL') || die();

class chat_history {

    /** Component and area for the File API. */
    const COMPONENT = 'local_kaiproctor';
    const FILEAREA = 'conversation';

    /** Turn boundary. An HTML comment renders as nothing in every Markdown
     *  viewer, so the file reads as a conversation rather than as a format. */
    const MARK = '<!--turn:';

    /**
     * The per-account ceiling, in bytes.
     *
     * One gigabyte by default, as specified. Worth knowing what that is: a
     * turn of Markdown runs two to five kilobytes, so this is somewhere past
     * two hundred thousand messages for one person. It is a backstop against a
     * loop or a script, not a budget anybody has to manage.
     */
    public static function quota(): int {
        $configured = (int) get_config('local_kaiproctor', 'chatquota');
        return $configured > 0 ? $configured : 1073741824;
    }

    /**
     * The far side's id for this conversation, when one has been recorded.
     *
     * The Indorama assistant keeps conversation state itself and hands back an
     * id; sending it with the next question is what makes "แล้วตารางนี้..."
     * mean something over there. Empty for the built-in assistant.
     *
     * @param int $userid
     * @param int $convoid
     * @return string empty when none was recorded
     */
    public static function remote_id(int $userid, int $convoid): string {
        global $DB;

        return (string) $DB->get_field('local_kaiproctor_convo', 'remoteid',
            ['id' => $convoid, 'userid' => $userid]);
    }

    /**
     * Record the far side's id against this conversation.
     *
     * @param int $userid
     * @param int $convoid
     * @param string $remoteid
     */
    public static function set_remote_id(int $userid, int $convoid, string $remoteid): void {
        global $DB;

        $DB->set_field('local_kaiproctor_convo', 'remoteid',
            \core_text::substr($remoteid, 0, 64),
            ['id' => $convoid, 'userid' => $userid]);
    }

    /**
     * Start a conversation and return its id.
     *
     * @param int $userid
     * @param string $title
     * @return int
     */
    public static function start(int $userid, string $title): int {
        global $DB;

        $now = time();
        $record = (object) [
            'userid' => $userid,
            'title' => \core_text::substr(trim($title), 0, 255),
            'timecreated' => $now,
            'timemodified' => $now,
            'turns' => 0,
            'bytes' => 0,
        ];
        $record->id = $DB->insert_record('local_kaiproctor_convo', $record);

        self::write($userid, $record->id, "# {$record->title}\n");
        return $record->id;
    }

    /**
     * Does this account own this conversation?
     *
     * Checked rather than assumed, because the id arrives from the browser. A
     * conversation id is the only thing standing between one person's
     * transcript and another's.
     *
     * @param int $userid
     * @param int $convoid
     * @return bool
     */
    public static function owns(int $userid, int $convoid): bool {
        global $DB;
        return $DB->record_exists('local_kaiproctor_convo',
            ['id' => $convoid, 'userid' => $userid]);
    }

    /**
     * Append one turn.
     *
     * @param int $userid
     * @param int $convoid
     * @param string $role 'user' or 'assistant'
     * @param string $text
     * @param array $sources Titles to record beside the answer, for the reader
     * @throws \moodle_exception when the account is over quota
     */
    public static function append(int $userid, int $convoid, string $role,
                                  string $text, array $sources = []): void {
        global $DB;

        $convo = $DB->get_record('local_kaiproctor_convo',
            ['id' => $convoid, 'userid' => $userid], '*', MUST_EXIST);

        $stamp = userdate(time(), get_string('strftimedatetimeshort', 'langconfig'));
        $who = get_string($role === 'user' ? 'chat:role:user' : 'chat:role:assistant',
            'local_kaiproctor');

        // Escaped so a pasted transcript cannot be read back as turn markers.
        // Somebody reporting a problem pastes one of these files into the chat;
        // without this, that paste splits their conversation in half.
        $body = str_replace(self::MARK, '<!-- turn:', trim($text));

        $block = "\n" . self::MARK . $role . ':' . time() . "-->\n"
            . "## {$who} · {$stamp}\n\n{$body}\n";
        if ($sources) {
            $block .= "\n_" . get_string('chat:sources', 'local_kaiproctor') . ': '
                . implode(', ', $sources) . "_\n";
        }

        // used() already counts this conversation's current size, so what the
        // new block adds is all that has to fit.
        $existing = self::read($userid, $convoid);
        if (self::used($userid) + strlen($block) > self::quota()) {
            throw new \moodle_exception('chat:overquota', 'local_kaiproctor');
        }

        self::write($userid, $convoid, $existing . $block);

        $convo->turns++;
        $convo->timemodified = time();
        $convo->bytes = strlen($existing . $block);
        $DB->update_record('local_kaiproctor_convo', $convo);
    }

    /**
     * The raw Markdown, as stored.
     *
     * @param int $userid
     * @param int $convoid
     * @return string Empty when there is no such conversation for this account
     */
    public static function read(int $userid, int $convoid): string {
        $file = self::file($userid, $convoid);
        return $file ? $file->get_content() : '';
    }

    /**
     * The conversation split back into turns.
     *
     * Parsed out of the file rather than kept in the database, because the
     * file is the copy that was promised. If somebody edits it, the assistant
     * should see what they left behind — anything else makes the Markdown
     * decoration over a hidden real store.
     *
     * @param int $userid
     * @param int $convoid
     * @return array of ['role' => string, 'text' => string]
     */
    public static function turns(int $userid, int $convoid): array {
        $body = self::read($userid, $convoid);
        if ($body === '') {
            return [];
        }

        $out = [];
        preg_match_all('~<!--turn:(user|assistant):\d+-->~', $body, $marks,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($marks as $n => $mark) {
            $start = $mark[0][1] + strlen($mark[0][0]);
            $end = isset($marks[$n + 1]) ? $marks[$n + 1][0][1] : strlen($body);
            $chunk = substr($body, $start, $end - $start);
            // Drop the human-facing heading and the sources line; what is left
            // is what was said.
            $chunk = preg_replace('~^\s*##[^\n]*\n~', '', trim($chunk));
            $chunk = preg_replace('~\n_[^\n]*_\s*$~', '', $chunk);
            $out[] = ['role' => $mark[1][0], 'text' => trim($chunk)];
        }
        return $out;
    }

    /**
     * This account's conversations, newest first.
     *
     * @param int $userid
     * @return array
     */
    public static function listing(int $userid): array {
        global $DB;
        return $DB->get_records('local_kaiproctor_convo', ['userid' => $userid],
            'timemodified DESC');
    }

    /**
     * Bytes this account is using.
     *
     * @param int $userid
     * @return int
     */
    public static function used(int $userid): int {
        global $DB;
        return (int) $DB->get_field_sql(
            "SELECT COALESCE(SUM(bytes), 0) FROM {local_kaiproctor_convo} WHERE userid = ?",
            [$userid]);
    }

    /**
     * Rename, in the row and in the file's own heading.
     *
     * @param int $userid
     * @param int $convoid
     * @param string $title
     */
    public static function rename(int $userid, int $convoid, string $title): void {
        global $DB;

        $convo = $DB->get_record('local_kaiproctor_convo',
            ['id' => $convoid, 'userid' => $userid], '*', MUST_EXIST);
        $convo->title = \core_text::substr(trim($title), 0, 255);

        $body = self::read($userid, $convoid);
        $body = preg_replace('~^# [^\n]*~', '# ' . $convo->title, $body, 1);
        self::write($userid, $convoid, $body);

        $convo->bytes = strlen($body);
        $DB->update_record('local_kaiproctor_convo', $convo);
    }

    /**
     * Delete one conversation, file and row.
     *
     * The file goes first. A row without its file is a listing entry that
     * opens to nothing; a file without its row is storage the owner has been
     * told they deleted, which is the worse of the two to leave behind.
     *
     * @param int $userid
     * @param int $convoid
     */
    public static function delete(int $userid, int $convoid): void {
        global $DB;

        $file = self::file($userid, $convoid);
        if ($file) {
            $file->delete();
        }
        $DB->delete_records('local_kaiproctor_convo',
            ['id' => $convoid, 'userid' => $userid]);
    }

    /**
     * Everything this account owns.
     *
     * @param int $userid
     * @return int How many were removed
     */
    public static function delete_all(int $userid): int {
        $removed = 0;
        foreach (self::listing($userid) as $convo) {
            self::delete($userid, $convo->id);
            $removed++;
        }
        return $removed;
    }

    // ---------- storage ----------

    protected static function file(int $userid, int $convoid): ?\stored_file {
        global $DB;

        if (!$DB->record_exists('local_kaiproctor_convo',
                ['id' => $convoid, 'userid' => $userid])) {
            return null;
        }
        $file = get_file_storage()->get_file(
            \context_user::instance($userid)->id, self::COMPONENT, self::FILEAREA,
            $convoid, '/', $convoid . '.md');
        return $file ?: null;
    }

    protected static function write(int $userid, int $convoid, string $body): void {
        $fs = get_file_storage();
        $record = [
            // The user context, so that deleting the user deletes the file with
            // them and the Privacy API can find it without being told where to
            // look.
            'contextid' => \context_user::instance($userid)->id,
            'component' => self::COMPONENT,
            'filearea' => self::FILEAREA,
            'itemid' => $convoid,
            'filepath' => '/',
            'filename' => $convoid . '.md',
        ];

        $existing = $fs->get_file($record['contextid'], $record['component'],
            $record['filearea'], $record['itemid'], '/', $record['filename']);
        if ($existing) {
            $existing->delete();
        }
        $fs->create_file_from_string($record, $body);
    }
}
