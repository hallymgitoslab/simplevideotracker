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

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_simplevideotracker_save_progress' => [
        'classname' => 'mod_simplevideotracker\\external\\save_progress',
        'description' => 'Save a verified video playback progress heartbeat.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/simplevideotracker:view',
    ],

    'mod_simplevideotracker_set_video' => [
        'classname' => 'mod_simplevideotracker\\external\\set_video',
        'description' => 'Upload a validated draft video with an authoritative duration and reset progress if the content changed.',
        'type' => 'write',
        'ajax' => false,
        'capabilities' => 'moodle/course:manageactivities',
    ],

    'mod_simplevideotracker_create_activity' => [
        'classname' => 'mod_simplevideotracker\\external\\create_activity',
        'description' => 'Create a new Simple Video Tracker activity in a course section.',
        'type' => 'write',
        'ajax' => false,
        'capabilities' => 'moodle/course:manageactivities,mod/simplevideotracker:addinstance',
    ],

];
