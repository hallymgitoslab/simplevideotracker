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
 * Backup task for Simple Video Tracker.
 *
 * @package   mod_simplevideotracker
 * @category  backup
 * @copyright 2026 onwards Simple Video Tracker contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/simplevideotracker/backup/moodle2/backup_simplevideotracker_stepslib.php');

/**
 * Complete backup task for a Simple Video Tracker activity.
 */
class backup_simplevideotracker_activity_task extends backup_activity_task {
    /**
     * No activity-specific backup settings.
     */
    protected function define_my_settings() {
    }

    /**
     * Add the structure step.
     */
    protected function define_my_steps() {
        $this->add_step(new backup_simplevideotracker_activity_structure_step(
            'simplevideotracker_structure',
            'simplevideotracker.xml'
        ));
    }

    /**
     * Encode links to this activity.
     *
     * @param string $content Content which may contain absolute plugin URLs.
     * @return string
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');
        $content = preg_replace(
            '/(' . $base . '\/mod\/simplevideotracker\/index.php\?id=)([0-9]+)/',
            '$@SIMPLEVIDEOTRACKERINDEX*$2@$',
            $content
        );
        $content = preg_replace(
            '/(' . $base . '\/mod\/simplevideotracker\/view.php\?id=)([0-9]+)/',
            '$@SIMPLEVIDEOTRACKERVIEWBYID*$2@$',
            $content
        );

        return $content;
    }
}
