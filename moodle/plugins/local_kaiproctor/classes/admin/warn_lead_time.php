<?php
// A countdown that has to fit inside the thing it counts down to.
//
// The idle warning is a lead-in: it appears during the last few seconds of the
// idle tolerance and reaches zero exactly when the video pauses. It therefore
// cannot be longer than the tolerance itself — asked to warn fifteen seconds
// before something that happens three seconds in, the monitor shows what is
// actually left, which is three.
//
// That is the right behaviour and the wrong silence. An administrator who
// typed 15 and watched it count 3 has no way to tell whether the setting is
// ignored, capped, or broken. So the pair is refused at the point it is set,
// where it can still be explained.
//
// Not used for the presence warning, which is a different shape: that one is
// a grace period beginning when a face goes missing, so it has nothing to fit
// inside and any length is meaningful.

namespace local_kaiproctor\admin;

defined('MOODLE_INTERNAL') || die();

class warn_lead_time extends plain_number {

    /** @var string the interval setting this has to fit inside, in seconds */
    protected $intervalname;

    /**
     * @param string $name
     * @param string $visiblename
     * @param string $description
     * @param string $defaultsetting
     * @param string $intervalname name of the seconds setting that bounds this
     */
    public function __construct($name, $visiblename, $description,
                                $defaultsetting, string $intervalname) {
        $this->intervalname = $intervalname;
        // plain_number rather than admin_setting_configtext, so this field
        // looks like the eight it sits among. It is the only one in the policy
        // block with a validator, which is no reason for it to be the only one
        // printing a default underneath itself.
        parent::__construct($name, $visiblename, $description, $defaultsetting);
    }

    /**
     * @param string $data
     * @return true|string true, or the reason it was refused
     */
    public function validate($data) {
        $parent = parent::validate($data);
        if ($parent !== true) {
            return $parent;
        }

        $seconds = (float) $data;
        if ($seconds < 0) {
            return get_string('settings:warnnegative', 'local_kaiproctor');
        }

        // Zero switches the warning off, which is always a coherent request.
        //
        // No conversion any more: the interval this has to fit inside is
        // stored in seconds, the same unit as this setting. It used to be
        // minutes and needed a × 60 here, which is exactly the kind of
        // arithmetic that goes wrong once and then reads as correct forever.
        $tolerance = (float) get_config('local_kaiproctor', $this->intervalname);
        if ($seconds > 0 && $tolerance > 0 && $seconds > $tolerance) {
            return get_string('settings:warntoolong', 'local_kaiproctor',
                (object) ['warn' => format_float($seconds, 1, true, true),
                    'tolerance' => format_float($tolerance, 1, true, true)]);
        }

        return true;
    }
}
