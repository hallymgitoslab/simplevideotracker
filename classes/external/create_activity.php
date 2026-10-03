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
 * External API for creating Simple Video Tracker activities.
 *
 * @package   mod_simplevideotracker
 * @copyright 2026 onwards Simple Video Tracker contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_simplevideotracker\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/modlib.php');

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External API for creating a Simple Video Tracker activity.
 */
class create_activity extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'sectionnum' => new external_value(PARAM_INT, 'Relative course section number'),
            'name' => new external_value(PARAM_TEXT, 'Activity name'),
            'intro' => new external_value(PARAM_RAW, 'Activity introduction', VALUE_DEFAULT, ''),
            'completionpercent' => new external_value(
                PARAM_INT,
                'Completion threshold percentage',
                VALUE_DEFAULT,
                80
            ),
            'preventseeking' => new external_value(
                PARAM_BOOL,
                'Prevent seeking beyond verified progress',
                VALUE_DEFAULT,
                true
            ),
            'seektolerance' => new external_value(
                PARAM_INT,
                'Seek tolerance in seconds',
                VALUE_DEFAULT,
                3
            ),
            'heartbeat' => new external_value(
                PARAM_INT,
                'Heartbeat interval in seconds',
                VALUE_DEFAULT,
                5
            ),
        ]);
    }

    /**
     * Create a new activity in the requested course section.
     *
     * The media duration remains unset until mod_simplevideotracker_set_video is
     * called with the privileged duration supplied by the deployment service.
     *
     * @param int $courseid Course ID.
     * @param int $sectionnum Section number.
     * @param string $name Activity name.
     * @param string $intro Activity introduction.
     * @param int $completionpercent Completion threshold.
     * @param bool $preventseeking Whether seek prevention is enabled.
     * @param int $seektolerance Seek tolerance in seconds.
     * @param int $heartbeat Heartbeat interval in seconds.
     * @return array
     */
    public static function execute(
        int $courseid,
        int $sectionnum,
        string $name,
        string $intro = '',
        int $completionpercent = 80,
        bool $preventseeking = true,
        int $seektolerance = 3,
        int $heartbeat = 5
    ): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'sectionnum' => $sectionnum,
            'name' => $name,
            'intro' => $intro,
            'completionpercent' => $completionpercent,
            'preventseeking' => $preventseeking,
            'seektolerance' => $seektolerance,
            'heartbeat' => $heartbeat,
        ]);

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $coursecontext = \context_course::instance($course->id);

        self::validate_context($coursecontext);
        require_capability('moodle/course:manageactivities', $coursecontext);
        require_capability('mod/simplevideotracker:addinstance', $coursecontext);

        if ($params['sectionnum'] < 0) {
            throw new \invalid_parameter_exception('sectionnum must be zero or greater.');
        }

        $name = trim($params['name']);
        if ($name === '') {
            throw new \invalid_parameter_exception('Activity name must not be empty.');
        }

        [$module, $context, $sectioninfo, $cm, $moduleinfo] = prepare_new_moduleinfo_data(
            $course,
            'simplevideotracker',
            (int)$params['sectionnum']
        );

        $moduleinfo->name = $name;
        $moduleinfo->intro = $params['intro'];
        $moduleinfo->introformat = FORMAT_HTML;
        if (isset($moduleinfo->introeditor) && is_array($moduleinfo->introeditor)) {
            // add_moduleinfo() uses introeditor when it exists and copies it back
            // into intro/introformat. Populate the editor data rather than letting
            // the default empty editor overwrite the Web Service introduction.
            $moduleinfo->introeditor['text'] = $params['intro'];
            $moduleinfo->introeditor['format'] = FORMAT_HTML;
        }

        $moduleinfo->videoduration = 0.0;
        $moduleinfo->completionpercent = max(1, min(100, (int)$params['completionpercent']));
        $moduleinfo->preventseeking = empty($params['preventseeking']) ? 0 : 1;
        $moduleinfo->seektolerance = max(0, min(10, (int)$params['seektolerance']));
        $moduleinfo->heartbeat = max(3, min(30, (int)$params['heartbeat']));

        // No file is attached during creation. The deployment workflow uploads
        // the final media afterwards through mod_simplevideotracker_set_video.
        $moduleinfo->videofile = 0;

        $created = add_moduleinfo($moduleinfo, $course, null);

        $cmid = (int)$created->coursemodule;
        $instanceid = (int)$created->instance;

        $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $DB->get_record('simplevideotracker', ['id' => $instanceid], '*', MUST_EXIST);

        return [
            'success' => true,
            'courseid' => (int)$course->id,
            'sectionnum' => (int)$params['sectionnum'],
            'cmid' => $cmid,
            'instanceid' => $instanceid,
            'name' => $name,
            'url' => (new \moodle_url('/mod/simplevideotracker/view.php', ['id' => $cmid]))->out(false),
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether creation succeeded'),
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'sectionnum' => new external_value(PARAM_INT, 'Relative section number'),
            'cmid' => new external_value(PARAM_INT, 'Created course module ID'),
            'instanceid' => new external_value(PARAM_INT, 'Created Simple Video Tracker instance ID'),
            'name' => new external_value(PARAM_TEXT, 'Created activity name'),
            'url' => new external_value(PARAM_URL, 'Activity URL'),
        ]);
    }
}
