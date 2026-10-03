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
$page = max(0, optional_param('page', 0, PARAM_INT));
$perpage = 100;
$cm = get_coursemodule_from_id('simplevideotracker', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$instance = $DB->get_record('simplevideotracker', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_course_login($course, true, $cm);
require_capability('mod/simplevideotracker:viewreport', $context);

$PAGE->set_url('/mod/simplevideotracker/report.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('report', 'simplevideotracker'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->navbar->add(get_string('report', 'simplevideotracker'));

$params = ['instanceid' => $instance->id];
$countsql = "SELECT COUNT(1)
               FROM {simplevideotracker_progress} p
               JOIN {user} u ON u.id = p.userid
              WHERE p.simplevideotrackerid = :instanceid";
$total = (int)$DB->count_records_sql($countsql, $params);

if ($total > 0) {
    $lastpage = max(0, (int)ceil($total / $perpage) - 1);
    $page = min($page, $lastpage);
}

$sql = "SELECT p.*, u.firstname, u.lastname, u.email
          FROM {simplevideotracker_progress} p
          JOIN {user} u ON u.id = p.userid
         WHERE p.simplevideotrackerid = :instanceid
      ORDER BY u.lastname, u.firstname, u.id";
$records = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('report', 'simplevideotracker') . ': ' . format_string($instance->name));

echo html_writer::div(
    get_string('completionpercentdesc', 'simplevideotracker', (int)$instance->completionpercent),
    'mb-3 text-muted'
);

if ($total === 0) {
    echo $OUTPUT->notification(get_string('noprogress', 'simplevideotracker'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$reporturl = new moodle_url('/mod/simplevideotracker/report.php', ['id' => $cm->id]);
$pagingbar = new paging_bar($total, $page, $perpage, $reporturl);
echo $OUTPUT->render($pagingbar);

$table = new html_table();
$table->head = [
    get_string('user', 'simplevideotracker'),
    get_string('email', 'simplevideotracker'),
    get_string('progress', 'simplevideotracker'),
    get_string('watched', 'simplevideotracker'),
    get_string('alloweduntil', 'simplevideotracker'),
    get_string('lastposition', 'simplevideotracker'),
    get_string('completed', 'simplevideotracker'),
    get_string('lastupdated', 'simplevideotracker'),
];
$table->data = [];

foreach ($records as $record) {
    $user = (object)[
        'id' => $record->userid,
        'firstname' => $record->firstname,
        'lastname' => $record->lastname,
    ];
    $userurl = new moodle_url('/user/view.php', ['id' => $record->userid, 'course' => $course->id]);
    $table->data[] = [
        html_writer::link($userurl, fullname($user)),
        s($record->email),
        format_float((float)$record->progress, 1) . '%',
        format_time((int)round((float)$record->watchedseconds)),
        format_time((int)round((float)$record->alloweduntil)),
        format_time((int)round((float)$record->lastposition)),
        !empty($record->completed) ? get_string('yes', 'simplevideotracker') : get_string('no', 'simplevideotracker'),
        userdate((int)$record->timemodified),
    ];
}

echo html_writer::table($table);
echo $OUTPUT->render($pagingbar);
$backurl = new moodle_url('/mod/simplevideotracker/view.php', ['id' => $cm->id]);
echo html_writer::div(html_writer::link($backurl, get_string('back')), 'mt-3');
echo $OUTPUT->footer();
