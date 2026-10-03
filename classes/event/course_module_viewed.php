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

namespace mod_simplevideotracker\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Event emitted when the video tracker activity is viewed.
 */
class course_module_viewed extends \core\event\course_module_viewed {
    /**
     * Initialise event metadata.
     */
    protected function init(): void {
        $this->data['objecttable'] = 'simplevideotracker';
        parent::init();
    }

    /**
     * Description for logs.
     */
    public function get_description(): string {
        return "The user with id '{$this->userid}' viewed the Simple Video Tracker activity with id '{$this->objectid}'.";
    }

    /**
     * URL associated with the event.
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/mod/simplevideotracker/view.php', ['id' => $this->contextinstanceid]);
    }
}
