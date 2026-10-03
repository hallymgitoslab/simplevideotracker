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
 * Restore task for Simple Video Tracker.
 *
 * @package   mod_simplevideotracker
 * @category  backup
 * @copyright 2026 onwards Simple Video Tracker contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/simplevideotracker/backup/moodle2/restore_simplevideotracker_stepslib.php');

/**
 * Complete restore task for a Simple Video Tracker activity.
 */
class restore_simplevideotracker_activity_task extends restore_activity_task {
    /**
     * No activity-specific restore settings.
     */
    protected function define_my_settings() {
    }

    /**
     * Add the structure step.
     */
    protected function define_my_steps() {
        $this->add_step(new restore_simplevideotracker_activity_structure_step(
            'simplevideotracker_structure',
            'simplevideotracker.xml'
        ));
    }

    /**
     * Content fields requiring link decoding.
     *
     * @return array
     */
    public static function define_decode_contents() {
        return [new restore_decode_content('simplevideotracker', ['intro'], 'simplevideotracker')];
    }

    /**
     * Plugin link-decoding rules.
     *
     * @return array
     */
    public static function define_decode_rules() {
        return [
            new restore_decode_rule(
                'SIMPLEVIDEOTRACKERVIEWBYID',
                '/mod/simplevideotracker/view.php?id=$1',
                'course_module'
            ),
            new restore_decode_rule('SIMPLEVIDEOTRACKERINDEX', '/mod/simplevideotracker/index.php?id=$1', 'course'),
        ];
    }
}
