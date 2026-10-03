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
 * Simple Video Tracker plugin code.
 *
 * @package   mod_simplevideotracker
 * @copyright 2026 onwards Simple Video Tracker contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$course = get_course($id);
require_course_login($course);

$PAGE->set_url('/mod/simplevideotracker/index.php', ['id' => $course->id]);
$PAGE->set_title(get_string('modulenameplural', 'simplevideotracker'));
$PAGE->set_heading(format_string($course->fullname));

$modinfo = get_fast_modinfo($course);
$instances = $modinfo->get_instances_of('simplevideotracker');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'simplevideotracker'));

if (!$instances) {
    echo $OUTPUT->notification(get_string('noprogress', 'simplevideotracker'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$items = [];
foreach ($instances as $cm) {
    if (!$cm->uservisible) {
        continue;
    }
    $items[] = html_writer::link(new moodle_url('/mod/simplevideotracker/view.php', ['id' => $cm->id]), format_string($cm->name));
}
echo html_writer::alist($items);
echo $OUTPUT->footer();
