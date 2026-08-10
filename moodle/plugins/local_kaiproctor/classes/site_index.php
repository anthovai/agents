<?php
// What one learner is allowed to open, as a list of titles and links.
//
// This is built here rather than in the reviewer service because Moodle is the
// only side that knows the answer. Enrolment, group restrictions, availability
// dates and "hidden from students" all decide whether a page exists for this
// person, and none of that survives being exported to somewhere else.
//
// The rule that matters: an assistant must not reveal that a course exists to
// somebody who cannot open it. Answering "you are not enrolled in X" is itself
// a disclosure, so nothing outside the learner's own courses is ever indexed.

namespace local_kaiproctor;

defined('MOODLE_INTERNAL') || die();

class site_index {

    /** Longer than a click, short enough that a new activity shows up. */
    const CACHE_SECONDS = 300;

    /**
     * Every page this user may open, as {title, url, kind, summary}.
     *
     * @param int $userid
     * @return array
     */
    public static function for_user(int $userid): array {
        $cache = \cache::make_from_params(\cache_store::MODE_APPLICATION,
            'local_kaiproctor', 'siteindex');
        $cached = $cache->get($userid);
        if ($cached && ($cached['built'] ?? 0) > time() - self::CACHE_SECONDS) {
            return $cached['items'];
        }

        $items = array_merge(self::tools(), self::courses($userid));
        $cache->set($userid, ['built' => time(), 'items' => $items]);
        return $items;
    }

    /** Pages of ours that exist for every learner. */
    protected static function tools(): array {
        return [
            [
                'title' => get_string('ask:page:enrol', 'local_kaiproctor'),
                'url' => '/local/kaiproctor/enrol.php',
                'kind' => 'tool',
                'summary' => get_string('ask:page:enrol_desc', 'local_kaiproctor'),
            ],
            [
                'title' => get_string('ask:page:lesson', 'local_kaiproctor'),
                'url' => '/local/kaiproctor/lesson.php',
                'kind' => 'tool',
                'summary' => get_string('ask:page:lesson_desc', 'local_kaiproctor'),
            ],
        ];
    }

    /** The learner's own courses, their sections and their activities. */
    protected static function courses(int $userid): array {
        $items = [];

        // onlyactive: a suspended enrolment is not a page you can open.
        foreach (enrol_get_users_courses($userid, true, ['summary']) as $course) {
            if (!$course->visible) {
                continue;
            }

            $items[] = [
                'title' => format_string($course->fullname),
                'url' => '/course/view.php?id=' . $course->id,
                'kind' => 'course',
                'summary' => self::shorten(strip_tags((string) ($course->summary ?? ''))),
            ];

            $modinfo = get_fast_modinfo($course, $userid);

            foreach ($modinfo->get_section_info_all() as $section) {
                $name = trim((string) $section->name);
                if ($name === '' || !$section->uservisible) {
                    continue;
                }
                $items[] = [
                    'title' => format_string($name),
                    'url' => '/course/view.php?id=' . $course->id
                        . '#section-' . $section->sectionnum,
                    'kind' => 'section',
                    'summary' => format_string($course->fullname),
                ];
            }

            foreach ($modinfo->get_cms() as $cm) {
                // uservisible folds together hidden, availability restrictions
                // and group membership. Checking it is what keeps this from
                // announcing an activity the learner cannot reach.
                if (!$cm->uservisible || !$cm->has_view()) {
                    continue;
                }
                $items[] = [
                    'title' => format_string($cm->get_formatted_name()),
                    'url' => '/mod/' . $cm->modname . '/view.php?id=' . $cm->id,
                    'kind' => self::kind_of($cm->modname),
                    'summary' => format_string($course->fullname),
                ];
            }
        }

        return $items;
    }

    /** Module names the contract understands; everything else is a page. */
    protected static function kind_of(string $modname): string {
        return [
            'quiz' => 'quiz',
            'lesson' => 'lesson',
            'interactivevideo' => 'video',
            'h5pactivity' => 'video',
            'resource' => 'resource',
            'url' => 'resource',
            'page' => 'page',
        ][$modname] ?? 'activity';
    }

    protected static function shorten(string $text): string {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        return \core_text::strlen($text) > 200
            ? \core_text::substr($text, 0, 197) . '...' : $text;
    }
}
