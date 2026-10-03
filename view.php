<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * View page for Simple Video Tracker.
 *
 * @package   mod_simplevideotracker
 * @copyright 2026 onwards Simple Video Tracker contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('simplevideotracker', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$instance = $DB->get_record('simplevideotracker', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_course_login($course, true, $cm);
require_capability('mod/simplevideotracker:view', $context);

$PAGE->set_url('/mod/simplevideotracker/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$file = simplevideotracker_get_video_file($context->id);
if (!$file) {
    throw new moodle_exception('novideo', 'simplevideotracker');
}
$durationconfigured = \mod_simplevideotracker\local\video_validator::is_valid_duration((float)$instance->videoduration);
if (!$durationconfigured) {
    if (!has_capability('moodle/course:manageactivities', $context)) {
        throw new moodle_exception('durationnotconfigured', 'simplevideotracker');
    }

    $videourl = moodle_url::make_pluginfile_url(
        $context->id,
        'mod_simplevideotracker',
        'video',
        0,
        $file->get_filepath(),
        $file->get_filename(),
        false
    );
    $playerid = 'simplevideotracker-duration-probe-' . $cm->id;
    $statusid = 'simplevideotracker-duration-repair-status-' . $cm->id;
    $PAGE->requires->js_call_amd('mod_simplevideotracker/duration_repair', 'init', [[
        'cmid' => (int)$cm->id,
        'playerId' => $playerid,
        'statusId' => $statusid,
        'endpoint' => (new moodle_url('/mod/simplevideotracker/setduration.php'))->out(false),
        'sesskey' => sesskey(),
        'savingText' => get_string('videodurationrepairsaving', 'simplevideotracker'),
        'savedText' => get_string('videodurationrepairsaved', 'simplevideotracker'),
        'failedText' => get_string('videodurationrepairfailed', 'simplevideotracker'),
    ]]);

    echo $OUTPUT->header();
    echo $OUTPUT->heading(format_string($instance->name));
    echo $OUTPUT->notification(get_string('videodurationrepairing', 'simplevideotracker'), 'info', false);
    $source = html_writer::empty_tag('source', [
        'src' => $videourl,
        'type' => \mod_simplevideotracker\local\video_validator::get_browser_mimetype($file->get_filename()),
    ]);
    echo html_writer::tag('video', $source, [
        'id' => $playerid,
        'preload' => 'metadata',
        'muted' => 'muted',
        'playsinline' => 'playsinline',
        'style' => 'display:none',
    ]);
    echo html_writer::div(
        get_string('videodurationrepairsaving', 'simplevideotracker'),
        'small text-muted',
        ['id' => $statusid, 'aria-live' => 'polite']
    );
    echo $OUTPUT->footer();
    exit;
}

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

$event = \mod_simplevideotracker\event\course_module_viewed::create([
    'objectid' => $instance->id,
    'context' => $context,
]);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('simplevideotracker', $instance);
$event->trigger();

$progress = \mod_simplevideotracker\local\progress_manager::start_session((int)$instance->id, (int)$USER->id);

$videourl = moodle_url::make_pluginfile_url(
    $context->id,
    'mod_simplevideotracker',
    'video',
    0,
    $file->get_filepath(),
    $file->get_filename(),
    false
);

$playerid = 'simplevideotracker-player-' . $cm->id;
$statusid = 'simplevideotracker-status-' . $cm->id;
$progressid = 'simplevideotracker-progress-' . $cm->id;
$bypassseek = has_capability('mod/simplevideotracker:bypassseek', $context);

$config = [
    'cmid' => (int)$cm->id,
    'playerId' => $playerid,
    'statusId' => $statusid,
    'progressId' => $progressid,
    'initialPosition' => (!empty($instance->preventseeking) && !$bypassseek)
        ? min((float)$progress->lastposition, (float)$progress->alloweduntil)
        : (float)$progress->lastposition,
    'allowedUntil' => (float)$progress->alloweduntil,
    'initialProgress' => (float)$progress->progress,
    'preventSeeking' => !empty($instance->preventseeking) && !$bypassseek,
    'seekTolerance' => (int)$instance->seektolerance,
    'heartbeat' => (int)$instance->heartbeat,
    'strings' => [
        'seekblocked' => get_string('seekblocked', 'simplevideotracker'),
        'saving' => get_string('saving', 'simplevideotracker'),
        'saved' => get_string('saved', 'simplevideotracker'),
        'savefailed' => get_string('savefailed', 'simplevideotracker'),
        'savepartial' => get_string('savepartial', 'simplevideotracker'),
        'savepending' => get_string('savepending', 'simplevideotracker'),
        'progress' => get_string('progress', 'simplevideotracker'),
    ],
];
$PAGE->requires->js_call_amd('mod_simplevideotracker/player', 'init', [$config]);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($instance->name));

if (trim((string)$instance->intro) !== '') {
    echo $OUTPUT->box(format_module_intro('simplevideotracker', $instance, $cm->id), 'generalbox mod_introbox');
}

$source = html_writer::empty_tag('source', [
    'src' => $videourl,
    'type' => \mod_simplevideotracker\local\video_validator::get_browser_mimetype($file->get_filename()),
]);
$videoattrs = [
    'id' => $playerid,
    'class' => 'simplevideotracker-player',
    'controls' => 'controls',
    'preload' => 'metadata',
    'playsinline' => 'playsinline',
    'controlsList' => 'nodownload noplaybackrate',
    'disablePictureInPicture' => 'disablePictureInPicture',
];
echo html_writer::div(html_writer::tag('video', $source, $videoattrs), 'simplevideotracker-player-wrap');

echo html_writer::start_div('simplevideotracker-meta');
echo html_writer::div('', 'small text-muted simplevideotracker-status', ['id' => $statusid, 'aria-live' => 'polite']);
$initialtext = get_string('progress', 'simplevideotracker') . ': ' . format_float((float)$progress->progress, 1) . '%';
echo html_writer::div($initialtext, 'simplevideotracker-progress-text', ['id' => $progressid]);
echo html_writer::end_div();

if ((float)$progress->lastposition > 1.0) {
    echo $OUTPUT->notification(get_string('resume', 'simplevideotracker'), 'info', false);
}

if (has_capability('mod/simplevideotracker:viewreport', $context)) {
    $reporturl = new moodle_url('/mod/simplevideotracker/report.php', ['id' => $cm->id]);
    echo html_writer::div(html_writer::link($reporturl, get_string('viewreport', 'simplevideotracker')), 'mt-3');
}

echo $OUTPUT->footer();
