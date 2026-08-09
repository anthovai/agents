<?php
// One event class covers every attention signal, with the specific signal in
// other['type'].
//
// Going through Moodle's event system rather than a private table means these
// land in the standard log store, which already has reports, retention and a
// Privacy API provider. A bespoke table would need all three built again.

namespace local_kaiproctor\event;

defined('MOODLE_INTERNAL') || die();

class attention_event extends \core\event\base {

    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    public static function get_name() {
        return get_string('event:attention', 'local_kaiproctor');
    }

    public function get_description() {
        $type = s($this->other['type'] ?? 'unknown');
        return "The user with id '{$this->userid}' triggered the proctoring signal '{$type}'.";
    }

    /**
     * @param array $other must contain 'type'; may contain 'detail' and
     *        'videotime' (position in the lesson video, in seconds)
     */
    public static function build(\context $context, int $userid, array $other): self {
        return self::create([
            'context' => $context,
            'userid' => $userid,
            'other' => $other,
        ]);
    }

    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['type'])) {
            throw new \coding_exception('attention_event requires other[type]');
        }
    }
}
