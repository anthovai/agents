<?php
// Where the video comes from.
//
// Two backends, and the difference is not cosmetic. A file plays in a real
// <video> element, which the proctoring adapter already drives and which can be
// paused in the same tick a question falls due. YouTube plays in an iframe
// across a postMessage boundary, so every instruction is asynchronous and every
// reading of the playhead is a poll.
//
// Both are supported because most customers already have their videos on
// YouTube and telling them to re-host is not a real answer. But the file is the
// better one, and the difference is written down here rather than left for
// somebody to discover when a question arrives half a second late.

namespace mod_kaivideo;

defined('MOODLE_INTERNAL') || die();

class source {

    const FILE = 'file';
    const YOUTUBE = 'youtube';

    /**
     * What kind of address this is, and the id if it needs one.
     *
     * @param string $url
     * @return array {provider, videoid}
     */
    public static function describe(string $url): array {
        $videoid = self::youtube_id($url);
        return $videoid === null
            ? ['provider' => self::FILE, 'videoid' => '']
            : ['provider' => self::YOUTUBE, 'videoid' => $videoid];
    }

    /**
     * The eleven-character id out of any of YouTube's address shapes.
     *
     * Matched rather than parsed loosely: an id is exactly eleven characters of
     * a known alphabet, and accepting anything else would put whatever the
     * author pasted into an embed URL.
     *
     * @param string $url
     * @return string|null
     */
    public static function youtube_id(string $url): ?string {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $patterns = [
            // youtu.be/ID
            '~^https?://(?:www\.)?youtu\.be/([A-Za-z0-9_-]{11})~',
            // youtube.com/watch?v=ID
            '~^https?://(?:www\.|m\.)?youtube\.com/watch\?(?:.*&)?v=([A-Za-z0-9_-]{11})~',
            // youtube.com/embed/ID and /v/ID and /shorts/ID
            '~^https?://(?:www\.)?youtube(?:-nocookie)?\.com/(?:embed|v|shorts)/([A-Za-z0-9_-]{11})~',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return $matches[1];
            }
        }
        return null;
    }

    /** Whether an address is something either backend can actually play. */
    public static function is_playable(string $url): bool {
        if (self::youtube_id($url) !== null) {
            return true;
        }

        // A file, then — and one the browser can decode. Checked because the
        // commonest authoring mistake is pasting a page that contains a video
        // rather than the video, and the failure otherwise appears as a blank
        // player with nothing to explain it.
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        foreach (['.mp4', '.webm', '.ogg', '.ogv', '.m4v', '.mov'] as $extension) {
            if (substr($path, -strlen($extension)) === $extension) {
                return true;
            }
        }
        return false;
    }
}
