<?php
// A proctoring session: one sitting, from the moment monitoring starts until
// it stops or is cut short.
//
// Without this, checks and evidence pile up against a context with no way to
// say which sitting they belong to, and no record of what the rules were at
// the time. A learner disputing a decision months later is entitled to know
// which policy was actually being enforced when it was made — answering "the
// one that is configured today" is not an answer.

namespace local_kaiproctor;

defined('MOODLE_INTERNAL') || die();

class session {

    const STATUS_ACTIVE = 'active';
    const STATUS_COMPLETED = 'completed';
    const STATUS_TERMINATED = 'terminated';
    /** Closed by the cleanup task: the learner never came back. */
    const STATUS_ABANDONED = 'abandoned';

    /** A session with no activity for this long is presumed over. */
    const STALE_AFTER = 2 * HOURSECS;

    /**
     * The rules in force, read from configuration on the server.
     *
     * Built here rather than accepted from the browser on purpose: a session
     * record whose policy came from the client would prove nothing, because a
     * tampered client could report whatever policy suited it afterwards.
     *
     * @param int|null $attemptid the exam attempt this governs, null for a
     *        lesson. Only strictness differs, and only because ending a
     *        lesson and ending an exam mean different things.
     * @return array
     */
    public static function current_policy(?int $attemptid = null): array {
        $get = static fn(string $name) => get_config('local_kaiproctor', $name);

        // A lesson is not ended by the monitor unless somebody asks for it.
        //
        // Ending an exam attempt protects something: the attempt is the thing
        // being sat, and letting it continue after a breach is the failure.
        // A lesson has nothing equivalent — ending it only sends the learner
        // back to the start, while the breach is recorded either way, so the
        // enforcement that means anything is pausing the video and writing
        // down what happened. Sites that want the stricter reading can still
        // have it; they just have to say so.
        $strict = (bool) $get($attemptid === null ? 'lessonstrictlockdown' : 'strictlockdown');

        return [
            // Seconds, and named so. Sittings recorded before this change
            // carry the old *minutes keys and are read back as they were
            // written — that is the point of snapshotting a policy, and
            // report.php handles both.
            'presenceseconds' => (float) $get('presenceseconds'),
            'verifyseconds' => (float) $get('verifyseconds'),
            'clickconfirmseconds' => (float) $get('clickconfirmseconds'),
            'clickconfirmgracesec' => (float) $get('clickconfirmgracesec'),
            'mouseidleseconds' => (float) $get('mouseidleseconds'),
            'mouseidlewarnsec' => (float) $get('mouseidlewarnsec'),
            'presencewarnsec' => (float) $get('presencewarnsec'),
            'randomclipsperhour' => (float) $get('randomclipsperhour'),
            'clipseconds' => (float) $get('clipseconds'),
            'blurallowance' => (int) $get('blurallowance'),
            'strictlockdown' => $strict,
            'desktopnotification' => (bool) $get('desktopnotification'),
            // The matching thresholds belong in the snapshot too: "which rules
            // applied" includes how strict the face comparison was.
            'matchthreshold' => (float) $get('matchthreshold'),
            'reviewmin' => (float) $get('reviewmin'),
        ];
    }

    /**
     * Open a session, or return the one already open for this learner here.
     *
     * Re-entering the same activity — a reload, a second tab — must not start
     * a second sitting, or the evidence for one sitting ends up split in two.
     *
     * @param int $userid
     * @param \context $context
     * @param int|null $attemptid
     * @return \stdClass
     */
    public static function start(int $userid, \context $context, ?int $attemptid = null): \stdClass {
        global $DB;

        $policy = self::current_policy($attemptid);

        // Reuse the open sitting, but only while it is still running under
        // today's rules.
        //
        // Reuse exists so that a reload does not split one lesson's evidence
        // into two records. A settings change is not a reload: an
        // administrator who lowers the idle tolerance and reloads the page was
        // watching the old sitting carry the old numbers, with nothing on
        // screen to say why, and reasonably concluded the settings did not
        // work. Rewriting the snapshot instead would be worse — it would
        // restate what was enforced during the earlier part of the sitting.
        //
        // So the old sitting is closed as it stands and a new one opens under
        // the new rules. The report then shows both, each with the policy that
        // actually governed it, and the moment the rules changed is visible
        // rather than lost.
        $existing = self::active_for($userid, $context, $attemptid);
        if ($existing) {
            if (self::same_policy($existing->policy, $policy)) {
                $DB->set_field('local_kaiproctor_session', 'timemodified', time(),
                    ['id' => $existing->id]);
                return $existing;
            }
            self::end((int) $existing->id, self::STATUS_COMPLETED, 'policy_changed');
        }

        $cmid = null;
        if ($context instanceof \context_module) {
            $cmid = $context->instanceid;
        }

        $now = time();
        $record = (object) [
            'userid' => $userid,
            'contextid' => $context->id,
            'cmid' => $cmid,
            'attemptid' => $attemptid,
            'policy' => json_encode($policy, JSON_UNESCAPED_UNICODE),
            'status' => self::STATUS_ACTIVE,
            'reason' => null,
            'timestart' => $now,
            'timeend' => null,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('local_kaiproctor_session', $record);

        return $record;
    }

    /**
     * Whether a recorded snapshot says the same thing as the current rules.
     *
     * Compared as sorted arrays rather than as JSON text: the encoder is free
     * to order keys differently between releases, and a sitting restarted over
     * a difference nobody made is the same annoyance in the other direction.
     *
     * @param string|null $recorded the snapshot as stored
     * @param array $current
     * @return bool
     */
    protected static function same_policy(?string $recorded, array $current): bool {
        $decoded = $recorded ? json_decode($recorded, true) : null;
        if (!is_array($decoded)) {
            return false;
        }
        ksort($decoded);
        ksort($current);
        return $decoded == $current;
    }

    /**
     * @param int $userid
     * @param \context $context
     * @param int|null $attemptid
     * @return \stdClass|null
     */
    public static function active_for(int $userid, \context $context, ?int $attemptid = null): ?\stdClass {
        global $DB;

        $conditions = [
            'userid' => $userid,
            'contextid' => $context->id,
            'status' => self::STATUS_ACTIVE,
        ];
        if ($attemptid !== null) {
            $conditions['attemptid'] = $attemptid;
        }

        $records = $DB->get_records('local_kaiproctor_session', $conditions, 'id DESC', '*', 0, 1);
        return $records ? reset($records) : null;
    }

    /**
     * Close a session. Closing one that is already closed is ignored rather
     * than overwritten — the first ending is the true one, and a late
     * "completed" must not erase an earlier "terminated".
     *
     * @param int $sessionid
     * @param string $status
     * @param string|null $reason
     */
    public static function end(int $sessionid, string $status, ?string $reason = null): void {
        global $DB;

        $record = $DB->get_record('local_kaiproctor_session', ['id' => $sessionid]);
        if (!$record || $record->status !== self::STATUS_ACTIVE) {
            return;
        }

        $DB->update_record('local_kaiproctor_session', (object) [
            'id' => $sessionid,
            'status' => $status,
            'reason' => $reason,
            'timeend' => time(),
            'timemodified' => time(),
        ]);
    }

    /** Keep a running session from looking abandoned while it is still going. */
    public static function touch(int $sessionid): void {
        global $DB;

        // Every monitor call lands here, several times a minute per learner,
        // and staleness is judged in hours. Guarding on the previous value
        // keeps the frequent calls from writing a row each: the statement
        // matches nothing until a minute has passed, so at most one real
        // write per session per minute reaches the database.
        $now = time();
        $DB->execute('UPDATE {local_kaiproctor_session}
                         SET timemodified = :now
                       WHERE id = :id AND status = :status AND timemodified < :cutoff',
            [
                'now' => $now,
                'id' => $sessionid,
                'status' => self::STATUS_ACTIVE,
                'cutoff' => $now - MINSECS,
            ]);
    }

    /**
     * Close sessions nobody ever ended — a closed laptop, a lost connection.
     *
     * Left alone they would sit at "active" forever, which reads as "still in
     * progress" to anyone auditing months later.
     *
     * @return int how many were closed
     */
    public static function close_stale(): int {
        global $DB;

        $cutoff = time() - self::STALE_AFTER;
        $stale = $DB->get_records_select('local_kaiproctor_session',
            'status = :status AND timemodified < :cutoff',
            ['status' => self::STATUS_ACTIVE, 'cutoff' => $cutoff]);

        foreach ($stale as $record) {
            // Two different things end up here and they are not the same news.
            // A learner who clicked back to the course left a page_left event
            // on the way out; a learner whose laptop lost power left nothing.
            // Closing both as abandoned made every ordinary lesson read as one
            // we lost contact with — and hid the ones we genuinely did.
            $left = self::left_deliberately($record);

            $DB->update_record('local_kaiproctor_session', (object) [
                'id' => $record->id,
                // 'completed' says the sitting ran its course without a
                // breach, not that they watched to the end: how far they got
                // is the video's record, not the proctor's.
                'status' => $left ? self::STATUS_COMPLETED : self::STATUS_ABANDONED,
                'reason' => $left ? 'page_left' : 'no_activity',
                // The last sign of life, not the moment the task noticed.
                'timeend' => $record->timemodified,
                'timemodified' => time(),
            ]);
        }

        return count($stale);
    }

    /**
     * Whether the learner's last act in this sitting was leaving the page.
     *
     * The browser logs page_left on its way out and does not close the sitting
     * itself: a reload raises the same event, and only the server can see that
     * nobody came back. If the last thing recorded was the page going away and
     * nothing since, they left.
     *
     * @param \stdClass $record the sitting
     * @return bool
     */
    protected static function left_deliberately(\stdClass $record): bool {
        $left = self::last_signal($record, 'page_left');
        if ($left === null) {
            return false;
        }

        // Compared against the last time monitoring started, not against
        // whatever happened to be logged last. page_left is a beacon and the
        // monitor's own shutdown is an ordinary request, so the two race and
        // land in either order — a rule that read "the final event" called the
        // same departure deliberate or not depending on which won.
        //
        // What actually decides it is whether they came back: a later
        // monitor_started means they did, and whatever ended the sitting after
        // that was not this departure.
        $started = self::last_signal($record, 'monitor_started');
        return $started === null || $left > $started;
    }

    /**
     * The id of the most recent event of one type in a sitting.
     *
     * @param \stdClass $record the sitting
     * @param string $type signal name
     * @return int|null null when it never happened
     */
    protected static function last_signal(\stdClass $record, string $type): ?int {
        global $DB;

        $rows = $DB->get_records_select('logstore_standard_log',
            'contextid = :contextid AND userid = :userid AND timecreated >= :from AND '
            . $DB->sql_like('eventname', ':pattern') . ' AND '
            . $DB->sql_like('other', ':signal'),
            [
                'contextid' => $record->contextid,
                'userid' => $record->userid,
                'from' => (int) $record->timestart,
                'pattern' => '%kaiproctor%',
                'signal' => '%"type":"' . $type . '"%',
            ], 'id DESC', 'id', 0, 1);

        return $rows ? (int) reset($rows)->id : null;
    }

    /**
     * Sessions for one learner, newest first.
     *
     * @param int $userid
     * @param \context|null $context
     * @return array
     */
    public static function for_user(int $userid, ?\context $context = null): array {
        global $DB;

        $conditions = ['userid' => $userid];
        if ($context) {
            $conditions['contextid'] = $context->id;
        }
        return $DB->get_records('local_kaiproctor_session', $conditions, 'timestart DESC');
    }

    /**
     * Check that a session id really belongs to this learner and context
     * before anything is filed against it.
     *
     * @param int|null $sessionid
     * @param int $userid
     * @param \context $context
     * @return int|null the id if it is usable, otherwise null
     */
    public static function validate(?int $sessionid, int $userid, \context $context): ?int {
        global $DB;

        if (empty($sessionid)) {
            return null;
        }

        $ok = $DB->record_exists('local_kaiproctor_session', [
            'id' => $sessionid,
            'userid' => $userid,
            'contextid' => $context->id,
        ]);

        return $ok ? $sessionid : null;
    }
}
