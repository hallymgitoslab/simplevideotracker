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

namespace mod_simplevideotracker\completion;

defined('MOODLE_INTERNAL') || die();

use core_completion\activity_custom_completion;

/**
 * Custom completion rule: watched percentage.
 */
class custom_completion extends activity_custom_completion {
    /**
     * Define the custom rules provided by this module.
     *
     * @return array
     */
    public static function get_defined_custom_rules(): array {
        return ['completionpercent'];
    }

    /**
     * Return completion state for one rule.
     *
     * @param string $rule
     * @return int
     */
    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);
        if ($rule !== 'completionpercent') {
            return COMPLETION_INCOMPLETE;
        }

        $instance = $DB->get_record(
            'simplevideotracker',
            ['id' => $this->cm->instance],
            'id,completionpercent,videoduration',
            MUST_EXIST
        );
        $progress = $DB->get_record(
            'simplevideotracker_progress',
            ['simplevideotrackerid' => $instance->id, 'userid' => $this->userid],
            'progress'
        );

        // Legacy progress percentages from releases before 2.3.0 were calculated
        // against a learner-reported duration. Never treat those values as
        // completion until a privileged authoritative duration is configured.
        if (!\mod_simplevideotracker\local\video_validator::is_valid_duration((float)$instance->videoduration)) {
            return COMPLETION_INCOMPLETE;
        }

        if ($progress && (float)$progress->progress + 0.0001 >= (float)$instance->completionpercent) {
            return COMPLETION_COMPLETE;
        }
        return COMPLETION_INCOMPLETE;
    }

    /**
     * Human-readable descriptions for custom rules.
     *
     * @return array
     */
    public function get_custom_rule_descriptions(): array {
        global $DB;

        $instance = $DB->get_record(
            'simplevideotracker',
            ['id' => $this->cm->instance],
            'completionpercent',
            MUST_EXIST
        );
        return [
            'completionpercent' => get_string('completionpercentdesc', 'simplevideotracker', (int)$instance->completionpercent),
        ];
    }

    /**
     * Order in which custom rules are shown.
     *
     * @return array
     */
    public function get_sort_order(): array {
        return ['completionpercent'];
    }
}
