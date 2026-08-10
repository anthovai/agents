<?php
// What an administrator needs to see before switching the AI on, and after.
//
// The settings page has the switch, but a switch on its own asks somebody to
// decide blind: switched on, where does the text go, which model answers, and
// is that machine ours? Those are the questions that decide whether turning it
// on is allowed at all under a customer's data agreements, and they are
// answered by the service, not by the setting.
//
// So this page puts the switch next to the answers.

namespace local_kaiproctor;

defined('MOODLE_INTERNAL') || die();

class ai_console {

    /** Hosts that mean "the model runs on infrastructure you control". */
    const LOCAL_HOSTS = ['localhost', '127.0.0.1', 'host.docker.internal',
        'ollama', 'llm-gateway', '::1'];

    /**
     * Everything the console template renders.
     *
     * @return array
     */
    public static function build(): array {
        $enabled = (bool) get_config('local_kaiproctor', 'aienabled');
        $baseurl = trim((string) get_config('local_kaiproctor', 'aibaseurl'));

        $health = $baseurl === '' ? ['ok' => false,
            'error' => ['code' => 'not_configured', 'message' => '']]
            : self::reach($baseurl);

        $reachable = !empty($health['backend_reachable']);
        $backend = (string) ($health['backend'] ?? '');

        $tasks = [];
        foreach (($health['models'] ?? []) as $task => $model) {
            $tasks[] = [
                'task' => get_string('ai:task:' . $task, 'local_kaiproctor'),
                'model' => $model,
            ];
        }

        return [
            'enabled' => $enabled,
            'baseurl' => $baseurl,
            'serviceok' => !empty($health['ok']),
            'serviceproblem' => $health['error']['message']
                ?? ($health['error']['code'] ?? ''),
            'contract' => $health['contract'] ?? '',
            'backend' => $backend,
            'backendreachable' => $reachable,
            'backendproblem' => (string) ($health['backend_problem'] ?? ''),
            'tasks' => $tasks,
            // The question a data-protection officer actually asks. Said in
            // one line here rather than left to be worked out from a URL.
            'offpremises' => $backend !== '' && !self::is_local($backend),
            // Switching it on while the model is unreachable produces a
            // feature that fails on every use, so the state is named rather
            // than left for somebody to discover through a learner complaint.
            'brokenwhileon' => $enabled && !$reachable,
            'toggleurl' => (new \moodle_url('/local/kaiproctor/ai.php'))->out(false),
            'settingsurl' => (new \moodle_url('/admin/settings.php',
                ['section' => 'local_kaiproctor']))->out(false),
            'askurl' => (new \moodle_url('/local/kaiproctor/ask.php'))->out(false),
            'sesskey' => sesskey(),
        ];
    }

    /** Whether the model runs somewhere the customer controls. */
    public static function is_local(string $url): bool {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }
        if (in_array($host, self::LOCAL_HOSTS, true)) {
            return true;
        }
        // A compose service name has no dots; a vendor endpoint always does.
        return strpos($host, '.') === false;
    }

    protected static function reach(string $baseurl): array {
        $health = ai_client::health_at($baseurl);
        return is_array($health) ? $health : ['ok' => false];
    }
}
