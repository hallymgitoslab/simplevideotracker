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
 * External API for replacing Simple Video Tracker media.
 *
 * @package   mod_simplevideotracker
 * @copyright 2026 onwards Simple Video Tracker contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_simplevideotracker\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/simplevideotracker/lib.php');

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_simplevideotracker\local\progress_manager;
use mod_simplevideotracker\local\video_validator;

/**
 * External API for replacing the single video file attached to a Simple Video Tracker.
 */
class set_video extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Simple Video Tracker course module ID'),
            'draftitemid' => new external_value(PARAM_INT, 'Moodle user draft item ID'),
            // REST clients commonly transmit numeric scalars as strings. Validate
            // this ourselves so malformed values produce a useful plugin error.
            'duration' => new external_value(
                PARAM_RAW_TRIMMED,
                'Authoritative video duration in seconds; omit to store the video first and configure duration later',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Copy a validated draft upload into the activity video file area.
     *
     * @param int $cmid Course-module ID.
     * @param int $draftitemid User draft item ID.
     * @param mixed $duration Authoritative media duration in seconds.
     * @return array
     */
    public static function execute(int $cmid, int $draftitemid, $duration = ''): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'draftitemid' => $draftitemid,
            'duration' => $duration,
        ]);

        $cmrecord = get_coursemodule_from_id(
            'simplevideotracker',
            (int)$params['cmid'],
            0,
            false,
            MUST_EXIST
        );

        $context = \context_module::instance($cmrecord->id);
        self::validate_context($context);

        $coursecontext = \context_course::instance($cmrecord->course);
        require_capability('moodle/course:manageactivities', $coursecontext);

        $instance = $DB->get_record(
            'simplevideotracker',
            ['id' => $cmrecord->instance],
            '*',
            MUST_EXIST
        );

        $durationraw = trim((string)$params['duration']);
        $durationprovided = ($durationraw !== '');
        $providedduration = $durationprovided
            ? video_validator::validate_external_duration($durationraw)
            : null;
        $draftfile = video_validator::validate_draft_item((int)$params['draftitemid'], (int)$USER->id);

        // Hold the instance-wide maintenance lock from the first state snapshot until
        // the outer transaction has actually committed. Heartbeats acquire this same
        // guard before their per-user lock, so they cannot observe a half-applied media change.
        $maintenancelock = progress_manager::acquire_instance_maintenance_lock((int)$instance->id);
        try {
            $oldhash = simplevideotracker_get_video_contenthash($context->id);
            $oldduration = (float)$instance->videoduration;

            // Capture affected users only after the maintenance guard is held. Any heartbeat
            // which passed the guard earlier has already registered its progress row and will
            // be awaited by reset/recalculation when the per-user locks are acquired.
            $userids = $DB->get_fieldset_select(
                'simplevideotracker_progress',
                'userid',
                'simplevideotrackerid = :id',
                ['id' => $instance->id]
            );

            $transaction = $DB->start_delegated_transaction();
            $fs = get_file_storage();

            // Copy the already-validated stored draft file directly. This avoids
            // differences in draft filepath/MIME handling between upload clients and
            // always normalises the activity file to the root of its single-file area.
            $fs->delete_area_files($context->id, 'mod_simplevideotracker', 'video', 0);
            try {
                $file = $fs->create_file_from_storedfile((object)[
                    'contextid' => $context->id,
                    'component' => 'mod_simplevideotracker',
                    'filearea' => 'video',
                    'itemid' => 0,
                    'filepath' => '/',
                    'filename' => $draftfile->get_filename(),
                    'userid' => (int)$USER->id,
                ], $draftfile);
            } catch (\Throwable $exception) {
                throw new \moodle_exception(
                    'videostorefailed',
                    'simplevideotracker',
                    '',
                    null,
                    $exception->getMessage()
                );
            }

            if (!$file || $file->is_directory() || $file->get_filesize() <= 0) {
                throw new \moodle_exception('videostorefailed', 'simplevideotracker');
            }

            $newhash = $file->get_contenthash();
            $changed = ($oldhash !== $newhash);
            $trustedduration = $durationprovided
                ? (float)$providedduration
                : ($changed ? 0.0 : $oldduration);
            $durationchanged = abs($oldduration - $trustedduration) > 0.0005;
            $progressreset = false;

            $timemodified = time();
            $DB->set_field('simplevideotracker', 'videoduration', $trustedduration, ['id' => $instance->id]);
            $DB->set_field('simplevideotracker', 'timemodified', $timemodified, ['id' => $instance->id]);

            if ($changed) {
                // The outer maintenance lock remains held until allow_commit() below.
                progress_manager::reset_instance_locked((int)$instance->id);
                $progressreset = true;

                if ($userids) {
                    $course = get_course($cmrecord->course);
                    $cminfo = get_fast_modinfo($course)->get_cm($cmrecord->id);
                    $completion = new \completion_info($course);

                    foreach ($userids as $userid) {
                        if ($completion->is_enabled($cminfo)) {
                            $completion->update_state($cminfo, COMPLETION_UNKNOWN, (int)$userid);
                        }
                    }
                }
            } else if ($durationchanged) {
                progress_manager::recalculate_instance_locked((int)$instance->id);
            }

            // Only after the database commit is allowed may waiting heartbeats acquire
            // the instance guard and reload the new media/settings state.
            $transaction->allow_commit();
        } finally {
            $maintenancelock->release();
        }

        return [
            'success' => true,
            'changed' => $changed,
            'progressreset' => $progressreset,
            'cmid' => (int)$cmrecord->id,
            'instanceid' => (int)$instance->id,
            'filename' => $file->get_filename(),
            'filesize' => (int)$file->get_filesize(),
            'mimetype' => (string)$file->get_mimetype(),
            'contenthash' => (string)$newhash,
            'duration' => $trustedduration,
            'durationconfigured' => video_validator::is_valid_duration($trustedduration),
            'timemodified' => $timemodified,
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the video was stored successfully'),
            'changed' => new external_value(PARAM_BOOL, 'Whether the video content changed'),
            'progressreset' => new external_value(PARAM_BOOL, 'Whether learner progress was reset'),
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'instanceid' => new external_value(PARAM_INT, 'Simple Video Tracker instance ID'),
            // Filename is output metadata, not a filesystem path supplied by the caller.
            // PARAM_RAW avoids false return-validation failures for Unicode filenames.
            'filename' => new external_value(PARAM_RAW, 'Stored filename'),
            'filesize' => new external_value(PARAM_INT, 'Stored file size in bytes'),
            'mimetype' => new external_value(PARAM_RAW, 'Stored MIME type'),
            'contenthash' => new external_value(PARAM_ALPHANUM, 'Stored file content hash'),
            'duration' => new external_value(PARAM_FLOAT, 'Stored authoritative video duration'),
            'durationconfigured' => new external_value(PARAM_BOOL, 'Whether the authoritative duration is configured'),
            'timemodified' => new external_value(PARAM_INT, 'Activity modified timestamp'),
        ]);
    }
}
