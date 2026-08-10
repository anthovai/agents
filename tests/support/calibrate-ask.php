<?php
// Choosing assistant::MIN_SCORE by measurement instead of by feel.
//
// The threshold decides whether a question reaches a model at all, so it is
// two different failures dressed as one number:
//
//   miss         a question about a page that exists returns nothing. The
//                learner sees "ไม่พบ" and concludes the assistant is useless.
//
//   false accept a question this site cannot answer still finds pages. The
//                assistant then hands a model irrelevant material and gets a
//                confident, irrelevant answer back — which is worse than
//                saying no, because it looks like an answer.
//
// They pull in opposite directions, so the sweep below reports both at every
// threshold rather than collapsing them into a single score. Same shape as
// the face threshold calibration, for the same reason: whoever has to defend
// the chosen number should be able to see what it cost.
//
// Run:  php calibrate-ask.php <username>

define('CLI_SCRIPT', true);
require('/var/www/html/config.php');

$username = $argv[1] ?? 'learner';
$user = $DB->get_record('user',
    ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id], '*', MUST_EXIST);

$fixtures = json_decode(file_get_contents(__DIR__ . '/ask-questions.json'), true);
$index = \local_kaiproctor\site_index::for_user((int) $user->id);

$ontopic = $fixtures['onTopic'];
$offtopic = $fixtures['offTopic'];

$context = \local_kaiproctor\assistant::CONTEXT_SIZE;
$rows = [];

for ($threshold = 0.01; $threshold <= 0.40001; $threshold += 0.01) {
    $threshold = round($threshold, 2);

    $found = 0;
    $ranked1 = 0;
    $misses = [];

    foreach ($ontopic as $case) {
        $results = \local_kaiproctor\assistant::rank($case['q'], $index, $threshold);
        $shown = array_slice($results, 0, $context);

        $hit = false;
        foreach ($shown as $position => $item) {
            if (strpos($item['url'], $case['expects']) !== false) {
                $hit = true;
                if ($position === 0) {
                    $ranked1++;
                }
                break;
            }
        }

        if ($hit) {
            $found++;
        } else {
            $misses[] = $case['q'];
        }
    }

    $accepted = 0;
    $wrongly = [];
    foreach ($offtopic as $case) {
        if (\local_kaiproctor\assistant::rank($case['q'], $index, $threshold)) {
            $accepted++;
            $wrongly[] = $case['q'];
        }
    }

    $rows[] = [
        'threshold' => $threshold,
        // Cast: 0 / 10 is int(0) in PHP, and int(0) === 0.0 is false, so a
        // strict comparison against 0.0 further down would match nothing.
        'recall' => (float) ($found / count($ontopic)),
        'top1' => (float) ($ranked1 / count($ontopic)),
        'falseaccept' => (float) ($accepted / count($offtopic)),
        'misses' => $misses,
        'wrongly' => $wrongly,
    ];
}

// The choice.
//
// Zero false accepts is a hard constraint, not one side of a trade. The
// feature's stated design is that a question with no matching page never
// reaches a model, and a threshold that lets one in every ten through does not
// implement that design — it approximates it, which is a different product and
// a weaker claim to make to a customer. A miss, by contrast, is a quality
// shortfall the learner can work around by naming the page.
//
// That ordering was decided on those grounds, not by looking at the table.
// Among the thresholds that admit nothing off-topic, take the best recall, and
// break a tie by preferring the higher one: the further from the boundary, the
// less a slightly differently worded question flips the outcome.
$clean = array_values(array_filter($rows,
    static fn($row) => $row['falseaccept'] === 0.0));

if (!$clean) {
    // Worth failing loudly rather than quietly picking the least bad row: it
    // means the question set and the page titles overlap so much that no
    // threshold separates them, and that is a retrieval problem, not a
    // threshold problem.
    fwrite(STDERR, "no threshold rejects every off-topic question" . PHP_EOL);
    $clean = $rows;
}

$best = null;
$bestrecall = max(array_column($clean, 'recall'));
foreach ($clean as $row) {
    if ($row['recall'] < $bestrecall) {
        continue;
    }
    if ($best === null || $row['threshold'] > $best['threshold']) {
        $best = $row;
    }
}

$out = [];
$out[] = 'MIN_SCORE calibration';
$out[] = sprintf('%d on-topic questions, %d off-topic, %d pages in the index',
    count($ontopic), count($offtopic), count($index));
$out[] = sprintf('measured %s against %s', date('Y-m-d H:i'), $CFG->wwwroot);
$out[] = '';
$out[] = 'threshold   recall   top-1   false-accept';
foreach ($rows as $row) {
    $out[] = sprintf('   %.2f     %5.1f%%  %5.1f%%     %5.1f%%%s',
        $row['threshold'], $row['recall'] * 100, $row['top1'] * 100,
        $row['falseaccept'] * 100,
        $row['threshold'] === $best['threshold'] ? '   <-- chosen' : '');
}

$out[] = '';
$out[] = sprintf('CHOSEN: %.2f  (recall %.1f%%, top-1 %.1f%%, false-accept %.1f%%)',
    $best['threshold'], $best['recall'] * 100, $best['top1'] * 100,
    $best['falseaccept'] * 100);

if ($best['misses']) {
    $out[] = '';
    $out[] = 'still missed at the chosen threshold:';
    foreach ($best['misses'] as $miss) {
        $out[] = '  ' . $miss;
    }
}
if ($best['wrongly']) {
    $out[] = '';
    $out[] = 'off-topic questions still finding pages:';
    foreach ($best['wrongly'] as $wrong) {
        $out[] = '  ' . $wrong;
    }
}

$out[] = '';
$out[] = 'Measured against the demo site with questions written by the developer,';
$out[] = 'which is the honest limit of this number. Recompute it against questions';
$out[] = 'real learners typed, on a site with real course names, before relying on';
$out[] = 'it — a 20-question set cannot resolve better than 5 percentage points.';

$report = implode("\n", $out) . "\n";
echo $report;

$path = '/var/www/html/ask-calibration.txt';
file_put_contents($path, $report);
