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
 * Library functions for Simple Video Tracker.
 *
 * @package   mod_simplevideotracker
 * @copyright 2026 onwards Simple Video Tracker contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Return feature support for this activity.
 *
 * @param string $feature Feature constant.
 * @return mixed
 */
function simplevideotracker_supports($feature) {
    return match ($feature) {
        FEATURE_MOD_INTRO => true,
        FEATURE_SHOW_DESCRIPTION => true,
        FEATURE_COMPLETION => true,
        FEATURE_COMPLETION_TRACKS_VIEWS => true,
        FEATURE_COMPLETION_HAS_RULES => true,
        FEATURE_BACKUP_MOODLE2 => true,
        FEATURE_MOD_PURPOSE => MOD_PURPOSE_CONTENT,
        default => null,
    };
}

/**
 * File manager options used by the activity.
 *
 * @return array
 */
function simplevideotracker_file_options(): array {
    return [
        'subdirs' => false,
        'maxbytes' => 0,
        'maxfiles' => 1,
        'accepted_types' => ['.mp4', '.webm', '.ogv', '.ogg', '.m4v'],
        'return_types' => FILE_INTERNAL,
    ];
}

/**
 * Add an activity instance.
 *
 * @param stdClass $data Submitted module data.
 * @param mod_simplevideotracker_mod_form|null $mform Module form.
 * @return int New instance ID.
 */
function simplevideotracker_add_instance($data, $mform = null): int {
    global $DB, $USER;

    $now = time();
    $data->timecreated = $now;
    $data->timemodified = $now;
    $data->completionpercent = max(1, min(100, (int)($data->completionpercent ?? 80)));
    $data->preventseeking = empty($data->preventseeking) ? 0 : 1;
    $data->seektolerance = max(0, min(10, (int)($data->seektolerance ?? 3)));
    $data->heartbeat = max(3, min(30, (int)($data->heartbeat ?? 5)));
    $data->videoduration = !empty($data->videoduration)
        ? \mod_simplevideotracker\local\video_validator::validate_duration((float)$data->videoduration)
        : 0.0;

    if (!empty($data->videofile)) {
        \mod_simplevideotracker\local\video_validator::validate_draft_item((int)$data->videofile, (int)$USER->id);
    }

    $id = $DB->insert_record('simplevideotracker', $data);
    $data->id = $id;

    if (!empty($data->coursemodule) && !empty($data->videofile)) {
        $context = context_module::instance($data->coursemodule);
        file_save_draft_area_files(
            $data->videofile,
            $context->id,
            'mod_simplevideotracker',
            'video',
            0,
            simplevideotracker_file_options()
        );
    }

    return $id;
}

/**
 * Update an activity instance.
 *
 * @param stdClass $data Submitted module data.
 * @param mod_simplevideotracker_mod_form|null $mform Module form.
 * @return bool
 */
function simplevideotracker_update_instance($data, $mform = null): bool {
    global $DB, $USER;

    $existing = $DB->get_record('simplevideotracker', ['id' => $data->instance], '*', MUST_EXIST);
    $data->id = $data->instance;
    $data->timemodified = time();
    $data->completionpercent = isset($data->completionpercent)
        ? max(1, min(100, (int)$data->completionpercent))
        : (int)$existing->completionpercent;
    $data->preventseeking = empty($data->preventseeking) ? 0 : 1;
    $data->seektolerance = max(0, min(10, (int)($data->seektolerance ?? 3)));
    $data->heartbeat = max(3, min(30, (int)($data->heartbeat ?? 5)));
    $durationraw = trim((string)($data->videoduration ?? ''));
    $data->videoduration = $durationraw !== ''
        ? \mod_simplevideotracker\local\video_validator::validate_duration((float)$durationraw)
        : (float)$existing->videoduration;

    $context = context_module::instance($data->coursemodule);
    $oldhash = simplevideotracker_get_video_contenthash($context->id);

    if (!empty($data->videofile)) {
        \mod_simplevideotracker\local\video_validator::validate_draft_item((int)$data->videofile, (int)$USER->id);
    }

    $result = $DB->update_record('simplevideotracker', $data);

    if (!empty($data->videofile)) {
        file_save_draft_area_files(
            $data->videofile,
            $context->id,
            'mod_simplevideotracker',
            'video',
            0,
            simplevideotracker_file_options()
        );
    }

    $newhash = simplevideotracker_get_video_contenthash($context->id);
    if ($oldhash !== $newhash) {
        $userids = $DB->get_fieldset_select(
            'simplevideotracker_progress',
            'userid',
            'simplevideotrackerid = :id',
            ['id' => $data->id]
        );
        \mod_simplevideotracker\local\progress_manager::reset_instance((int)$data->id);
        if ($userids) {
            $course = get_course($existing->course);
            $cminfo = get_fast_modinfo($course)->get_cm($data->coursemodule);
            $completion = new completion_info($course);
            foreach ($userids as $userid) {
                if ($completion->is_enabled($cminfo)) {
                    $completion->update_state($cminfo, COMPLETION_UNKNOWN, (int)$userid);
                }
            }
        }
    } else {
        \mod_simplevideotracker\local\progress_manager::recalculate_instance((int)$data->id);
    }

    return $result;
}

/**
 * Delete an activity instance and associated user progress.
 *
 * @param int $id Instance ID.
 * @return bool
 */
function simplevideotracker_delete_instance($id): bool {
    global $DB;

    if (!$instance = $DB->get_record('simplevideotracker', ['id' => $id])) {
        return false;
    }

    $cm = get_coursemodule_from_instance('simplevideotracker', $id, $instance->course, false, IGNORE_MISSING);
    if ($cm) {
        $context = context_module::instance($cm->id);
        get_file_storage()->delete_area_files($context->id, 'mod_simplevideotracker');
    }

    \mod_simplevideotracker\local\progress_manager::reset_instance((int)$id);
    $DB->delete_records('simplevideotracker', ['id' => $id]);
    return true;
}

/**
 * Serve uploaded video files.
 *
 * Standard activity-intro files are handled by core pluginfile.php before
 * this callback is reached.
 *
 * @param stdClass $course Course record.
 * @param stdClass $cm Course-module record.
 * @param context $context File context.
 * @param string $filearea File area.
 * @param array $args Path arguments.
 * @param bool $forcedownload Whether to force download.
 * @param array $options File serving options.
 * @return bool|void
 */
function simplevideotracker_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel !== CONTEXT_MODULE || $filearea !== 'video') {
        return false;
    }

    require_course_login($course, true, $cm);
    require_capability('mod/simplevideotracker:view', $context);

    $itemid = (int)array_shift($args);
    if ($itemid !== 0 || empty($args)) {
        return false;
    }

    $filename = array_pop($args);
    $filepath = '/' . (empty($args) ? '' : implode('/', $args) . '/');
    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'mod_simplevideotracker', 'video', $itemid, $filepath, $filename);

    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, 0, 0, false, $options);
}

/**
 * Get the one uploaded video file.
 *
 * @param int $contextid Module context ID.
 * @return stored_file|null
 */
function simplevideotracker_get_video_file(int $contextid): ?stored_file {
    $files = get_file_storage()->get_area_files(
        $contextid,
        'mod_simplevideotracker',
        'video',
        0,
        'sortorder ASC, id ASC',
        false
    );
    if (!$files) {
        return null;
    }
    return reset($files) ?: null;
}

/**
 * Return the current video content hash, if any.
 *
 * @param int $contextid Module context ID.
 * @return string
 */
function simplevideotracker_get_video_contenthash(int $contextid): string {
    $file = simplevideotracker_get_video_file($contextid);
    return $file ? $file->get_contenthash() : '';
}

/**
 * Supply cached course-module data, including custom completion settings.
 *
 * @param stdClass $coursemodule Course-module record.
 * @return cached_cm_info|false
 */
function simplevideotracker_get_coursemodule_info($coursemodule) {
    global $DB;

    $instance = $DB->get_record(
        'simplevideotracker',
        ['id' => $coursemodule->instance],
        'id,name,intro,introformat,completionpercent'
    );
    if (!$instance) {
        return false;
    }

    $result = new cached_cm_info();
    $result->name = $instance->name;
    if (!empty($coursemodule->showdescription)) {
        $result->content = format_module_intro('simplevideotracker', $instance, $coursemodule->id, false);
    }
    if ((int)$coursemodule->completion === COMPLETION_TRACKING_AUTOMATIC) {
        $result->customdata['customcompletionrules']['completionpercent'] = (int)$instance->completionpercent;
    }
    return $result;
}

/**
 * Legacy custom completion callback retained for compatibility.
 *
 * @param stdClass $course Course record.
 * @param cm_info|stdClass $cm Course module.
 * @param int $userid User ID.
 * @param bool $type Expected completion state.
 * @return bool
 */
function simplevideotracker_get_completion_state($course, $cm, $userid, $type) {
    global $DB;

    $instance = $DB->get_record(
        'simplevideotracker',
        ['id' => $cm->instance],
        'id,completionpercent,videoduration',
        MUST_EXIST
    );
    if (empty($instance->completionpercent)) {
        return $type;
    }
    if (!\mod_simplevideotracker\local\video_validator::is_valid_duration((float)$instance->videoduration)) {
        return false;
    }
    $progress = $DB->get_record(
        'simplevideotracker_progress',
        ['simplevideotrackerid' => $instance->id, 'userid' => $userid],
        'progress'
    );
    return $progress && (float)$progress->progress >= (float)$instance->completionpercent;
}

/**
 * Human-readable active completion rule descriptions.
 *
 * @param cm_info|stdClass $cm Course module.
 * @return array
 */
function mod_simplevideotracker_get_completion_active_rule_descriptions($cm): array {
    if (empty($cm->customdata['customcompletionrules']['completionpercent'])
            || (int)$cm->completion !== COMPLETION_TRACKING_AUTOMATIC) {
        return [];
    }
    return [get_string(
        'completionpercentdesc',
        'simplevideotracker',
        (int)$cm->customdata['customcompletionrules']['completionpercent']
    )];
}
