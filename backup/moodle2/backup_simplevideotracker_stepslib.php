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
 * Backup structure for Simple Video Tracker.
 *
 * @package   mod_simplevideotracker
 * @category  backup
 * @copyright 2026 onwards Simple Video Tracker contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Define the complete Simple Video Tracker structure for backup.
 */
class backup_simplevideotracker_activity_structure_step extends backup_activity_structure_step {
    /**
     * Define the backup tree, sources, ID annotations, and file areas.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $simplevideotracker = new backup_nested_element('simplevideotracker', ['id'], [
            'name',
            'intro',
            'introformat',
            'videoduration',
            'completionpercent',
            'preventseeking',
            'seektolerance',
            'heartbeat',
            'timecreated',
            'timemodified',
        ]);
        $progresses = new backup_nested_element('progresses');
        $progress = new backup_nested_element('progress', ['id'], [
            'userid',
            'duration',
            'alloweduntil',
            'lastposition',
            'watchedseconds',
            'progress',
            'completed',
            'lastping',
            'timecreated',
            'timemodified',
        ]);
        $segments = new backup_nested_element('segments');
        $segment = new backup_nested_element('segment', ['id'], [
            'starttime',
            'endtime',
            'timecreated',
            'timemodified',
        ]);

        $simplevideotracker->add_child($progresses);
        $progresses->add_child($progress);
        $progress->add_child($segments);
        $segments->add_child($segment);

        $simplevideotracker->set_source_table('simplevideotracker', ['id' => backup::VAR_ACTIVITYID]);
        if ($userinfo) {
            $progress->set_source_table(
                'simplevideotracker_progress',
                ['simplevideotrackerid' => backup::VAR_PARENTID],
                'id ASC'
            );
            $segment->set_source_table(
                'simplevideotracker_segments',
                ['progressid' => backup::VAR_PARENTID],
                'id ASC'
            );
        }

        $progress->annotate_ids('user', 'userid');

        $simplevideotracker->annotate_files('mod_simplevideotracker', 'intro', null);
        $simplevideotracker->annotate_files('mod_simplevideotracker', 'video', null);

        return $this->prepare_activity_structure($simplevideotracker);
    }
}
