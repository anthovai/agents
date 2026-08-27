<?php
// A number field that does not print "Default: 4" underneath itself.
//
// Moodle appends the default to every setting, which is usually helpful and is
// noise here. These settings are read as a group — nine intervals, one under
// the next — and the default line doubles the height of the list while adding
// a number that competes with the one the reader is actually looking for. On a
// page somebody is scanning to answer "what is this site doing right now", the
// value in the box is the answer and the default is trivia.
//
// The value is still recoverable: emptying the box restores the default, which
// is standard Moodle behaviour and unchanged by this class.

namespace local_kaiproctor\admin;

defined('MOODLE_INTERNAL') || die();

class plain_number extends \admin_setting_configtext {

    /**
     * @param string $name
     * @param string $visiblename
     * @param string $description
     * @param string $defaultsetting
     */
    public function __construct($name, $visiblename, $description, $defaultsetting) {
        parent::__construct($name, $visiblename, $description, $defaultsetting,
            PARAM_FLOAT, 8);
    }

    /**
     * The field, without the default-value line.
     *
     * Rendered through the same template as every other text setting so the
     * page stays consistent; the only difference is the empty $defaultinfo.
     *
     * @param mixed $data
     * @param string $query
     * @return string
     */
    public function output_html($data, $query = '') {
        $default = $this->get_defaultsetting();
        $context = (object) [
            'size' => $this->size,
            'id' => $this->get_id(),
            'name' => $this->get_full_name(),
            'value' => $data,
            'forceltr' => $this->get_force_ltr(),
            'readonly' => $this->is_readonly(),
        ];
        $element = $GLOBALS['OUTPUT']->render_from_template(
            'core_admin/setting_configtext', $context);

        // null rather than $default: that argument is the whole reason this
        // class exists.
        return format_admin_setting($this, $this->visiblename, $element,
            $this->description, true, '', null, $query);
    }
}
