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
 * Data generator for Simple Video Tracker tests.
 *
 * @package   mod_simplevideotracker
 * @category  test
 * @copyright 2026 onwards Simple Video Tracker contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Simple Video Tracker module data generator.
 */
class mod_simplevideotracker_generator extends testing_module_generator {
    /**
     * Create a Simple Video Tracker activity instance.
     *
     * @param array|stdClass|null $record Instance data.
     * @param array|null $options Generator options.
     * @return stdClass
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object)(array)$record;
        $record->name ??= 'Simple Video Tracker test';
        $record->videoduration ??= 600.0;
        $record->completionpercent ??= 80;
        $record->preventseeking ??= 1;
        $record->seektolerance ??= 3;
        $record->heartbeat ??= 5;

        return parent::create_instance($record, (array)$options);
    }
}
