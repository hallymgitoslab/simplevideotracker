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
 * External API for Simple Video Tracker progress heartbeats.
 *
 * @package   mod_simplevideotracker
 * @copyright 2026 onwards Simple Video Tracker contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_simplevideotracker\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_simplevideotracker\local\progress_manager;

/**
 * AJAX endpoint for verified progress heartbeats.
 */
class save_progress extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'from' => new external_value(PARAM_FLOAT, 'Natural playback segment start'),
            'to' => new external_value(PARAM_FLOAT, 'Natural playback segment end'),
            'position' => new external_value(PARAM_FLOAT, 'Current playback position'),
            'duration' => new external_value(
                PARAM_FLOAT,
                'Browser-reported video duration used only for consistency checking'
            ),
        ]);
    }

    /**
     * Save progress.
     *
     * @param int $cmid Course-module ID.
     * @param float $from Segment start.
     * @param float $to Segment end.
     * @param float $position Current playback position.
     * @param float $duration Browser-reported duration for consistency checking.
     * @return array
     */
    public static function execute(int $cmid, float $from, float $to, float $position, float $duration): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'from' => $from,
            'to' => $to,
            'position' => $position,
            'duration' => $duration,
        ]);

        $cmrecord = get_coursemodule_from_id('simplevideotracker', $params['cmid'], 0, false, MUST_EXIST);
        $course = get_course($cmrecord->course);
        $context = \context_module::instance($cmrecord->id);
        self::validate_context($context);
        require_capability('mod/simplevideotracker:view', $context);

        $instance = $DB->get_record('simplevideotracker', ['id' => $cmrecord->instance], '*', MUST_EXIST);
        $cminfo = get_fast_modinfo($course)->get_cm($cmrecord->id);
        $bypassseek = has_capability('mod/simplevideotracker:bypassseek', $context);

        return progress_manager::save(
            $cminfo,
            $instance,
            (int)$USER->id,
            (float)$params['from'],
            (float)$params['to'],
            (float)$params['position'],
            (float)$params['duration'],
            $bypassseek
        );
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'accepted' => new external_value(PARAM_BOOL, 'Whether the heartbeat was fully accepted'),
            'reason' => new external_value(PARAM_ALPHANUMEXT, 'Verification result code'),
            'creditedfrom' => new external_value(PARAM_FLOAT, 'Start of the range credited by this heartbeat'),
            'creditedto' => new external_value(PARAM_FLOAT, 'End of the range credited by this heartbeat'),
            'alloweduntil' => new external_value(PARAM_FLOAT, 'Verified contiguous frontier'),
            'lastposition' => new external_value(PARAM_FLOAT, 'Stored last position'),
            'watchedseconds' => new external_value(PARAM_FLOAT, 'Unique watched seconds'),
            'duration' => new external_value(PARAM_FLOAT, 'Trusted stored video duration'),
            'progress' => new external_value(PARAM_FLOAT, 'Unique watched percentage'),
            'completed' => new external_value(PARAM_BOOL, 'Completion threshold reached'),
        ]);
    }
}
