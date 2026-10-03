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
 * Restore structure for Simple Video Tracker.
 *
 * @package   mod_simplevideotracker
 * @category  backup
 * @copyright 2026 onwards Simple Video Tracker contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Restore one Simple Video Tracker activity and optional user progress.
 */
class restore_simplevideotracker_activity_structure_step extends restore_activity_structure_step {
    /**
     * Define restore paths.
     *
     * @return array
     */
    protected function define_structure() {
        $paths = [new restore_path_element('simplevideotracker', '/activity/simplevideotracker')];

        if ($this->get_setting_value('userinfo')) {
            $paths[] = new restore_path_element(
                'simplevideotracker_progress',
                '/activity/simplevideotracker/progresses/progress'
            );
            $paths[] = new restore_path_element(
                'simplevideotracker_segment',
                '/activity/simplevideotracker/progresses/progress/segments/segment'
            );
        }

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restore the activity instance.
     *
     * @param array $data Backup data.
     */
    protected function process_simplevideotracker($data): void {
        global $DB;

        $data = (object)$data;
        $data->course = $this->get_courseid();

        $newitemid = $DB->insert_record('simplevideotracker', $data);
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Restore one user's progress row.
     *
     * @param array $data Backup data.
     */
    protected function process_simplevideotracker_progress($data): void {
        global $DB;

        $data = (object)$data;
        $oldid = (int)$data->id;
        $data->simplevideotrackerid = $this->get_new_parentid('simplevideotracker');
        $data->userid = $this->get_mappingid('user', $data->userid);

        if (empty($data->userid)) {
            return;
        }

        $newitemid = $DB->insert_record('simplevideotracker_progress', $data);
        $this->set_mapping('simplevideotracker_progress', $oldid, $newitemid);
    }

    /**
     * Restore one watched segment.
     *
     * @param array $data Backup data.
     */
    protected function process_simplevideotracker_segment($data): void {
        global $DB;

        $data = (object)$data;
        $progressid = $this->get_new_parentid('simplevideotracker_progress');
        if (empty($progressid)) {
            return;
        }

        $data->progressid = $progressid;
        $DB->insert_record('simplevideotracker_segments', $data);
    }

    /**
     * Restore activity files after the module context exists.
     */
    protected function after_execute(): void {
        $this->add_related_files('mod_simplevideotracker', 'intro', null);
        $this->add_related_files('mod_simplevideotracker', 'video', null);
    }
}
