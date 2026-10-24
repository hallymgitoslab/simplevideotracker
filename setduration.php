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
 * Persist authoritative duration detected from the already-stored activity video.
 *
 * This is intentionally a normal sesskey-protected Moodle endpoint rather than
 * an external-function AJAX call. A failure here cannot interfere with the file
 * manager upload transaction.
 *
 * @package   mod_simplevideotracker
 * @copyright 2026 onwards Simple Video Tracker contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$cmid = required_param('cmid', PARAM_INT);
$durationraw = required_param('duration', PARAM_RAW_TRIMMED);

$cm = get_coursemodule_from_id('simplevideotracker', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$context = context_module::instance($cm->id);

require_course_login($course, false, $cm);
require_sesskey();
require_capability('moodle/course:manageactivities', $context);

header('Content-Type: application/json; charset=utf-8');

try {
    $duration = \mod_simplevideotracker\local\video_validator::validate_external_duration($durationraw);
    $file = simplevideotracker_get_video_file($context->id);
    if (!$file) {
        throw new moodle_exception('novideo', 'simplevideotracker');
    }

    $instance = $DB->get_record('simplevideotracker', ['id' => $cm->instance], '*', MUST_EXIST);
    $old = (float)$instance->videoduration;
    $DB->set_field('simplevideotracker', 'videoduration', $duration, ['id' => $instance->id]);
    $DB->set_field('simplevideotracker', 'timemodified', time(), ['id' => $instance->id]);

    if (abs($old - $duration) > 0.0005) {
        \mod_simplevideotracker\local\progress_manager::recalculate_instance((int)$instance->id);
    }

    echo json_encode([
        'success' => true,
        'duration' => $duration,
    ]);
} catch (Throwable $exception) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage(),
    ]);
}
