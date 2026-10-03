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
 * Privacy provider for Simple Video Tracker.
 *
 * @package   mod_simplevideotracker
 * @copyright 2026 onwards Simple Video Tracker contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_simplevideotracker\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for Simple Video Tracker.
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    /**
     * Describe personal data stored by this plugin.
     *
     * @param collection $collection Metadata collection.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('simplevideotracker_progress', [
            'userid' => 'privacy:metadata:simplevideotracker_progress:userid',
            'simplevideotrackerid' => 'privacy:metadata:simplevideotracker_progress:simplevideotrackerid',
            'duration' => 'privacy:metadata:simplevideotracker_progress:duration',
            'alloweduntil' => 'privacy:metadata:simplevideotracker_progress:alloweduntil',
            'lastposition' => 'privacy:metadata:simplevideotracker_progress:lastposition',
            'watchedseconds' => 'privacy:metadata:simplevideotracker_progress:watchedseconds',
            'progress' => 'privacy:metadata:simplevideotracker_progress:progress',
            'completed' => 'privacy:metadata:simplevideotracker_progress:completed',
            'lastping' => 'privacy:metadata:simplevideotracker_progress:lastping',
            'timecreated' => 'privacy:metadata:simplevideotracker_progress:timecreated',
            'timemodified' => 'privacy:metadata:simplevideotracker_progress:timemodified',
        ], 'privacy:metadata:simplevideotracker_progress');
        $collection->add_database_table('simplevideotracker_segments', [
            'progressid' => 'privacy:metadata:simplevideotracker_segments:progressid',
            'starttime' => 'privacy:metadata:simplevideotracker_segments:starttime',
            'endtime' => 'privacy:metadata:simplevideotracker_segments:endtime',
            'timecreated' => 'privacy:metadata:simplevideotracker_segments:timecreated',
            'timemodified' => 'privacy:metadata:simplevideotracker_segments:timemodified',
        ], 'privacy:metadata:simplevideotracker_segments');
        return $collection;
    }

    /**
     * Find module contexts containing progress for a user.
     *
     * @param int $userid User ID.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {simplevideotracker} v ON v.id = cm.instance
                  JOIN {simplevideotracker_progress} p ON p.simplevideotrackerid = v.id
                 WHERE ctx.contextlevel = :contextlevel AND p.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'modname' => 'simplevideotracker',
            'contextlevel' => CONTEXT_MODULE,
            'userid' => $userid,
        ]);
        return $contextlist;
    }

    /**
     * Export user data from approved contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('simplevideotracker', $context->instanceid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }
            $progress = $DB->get_record('simplevideotracker_progress', [
                'simplevideotrackerid' => $cm->instance,
                'userid' => $userid,
            ]);
            if (!$progress) {
                continue;
            }
            $segments = $DB->get_records('simplevideotracker_segments', ['progressid' => $progress->id], 'starttime ASC');
            $data = (object)[
                'duration' => $progress->duration,
                'alloweduntil' => $progress->alloweduntil,
                'lastposition' => $progress->lastposition,
                'watchedseconds' => $progress->watchedseconds,
                'progress' => $progress->progress,
                'completed' => transform::yesno((bool)$progress->completed),
                'lastping' => transform::datetime($progress->lastping),
                'timecreated' => transform::datetime($progress->timecreated),
                'timemodified' => transform::datetime($progress->timemodified),
                'segments' => array_values(array_map(static function($segment) {
                    return (object)[
                        'start' => $segment->starttime,
                        'end' => $segment->endtime,
                        'timecreated' => transform::datetime($segment->timecreated),
                        'timemodified' => transform::datetime($segment->timemodified),
                    ];
                }, $segments)),
            ];
            writer::with_context($context)->export_data([get_string('pluginname', 'simplevideotracker')], $data);
        }
    }

    /**
     * Delete all user data in a module context.
     *
     * @param \context $context Context to delete.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('simplevideotracker', $context->instanceid, 0, false, IGNORE_MISSING);
        if ($cm) {
            \mod_simplevideotracker\local\progress_manager::reset_instance((int)$cm->instance);
        }
    }

    /**
     * Delete one user's approved context data.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('simplevideotracker', $context->instanceid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }
            $progress = $DB->get_record('simplevideotracker_progress', [
                'simplevideotrackerid' => $cm->instance,
                'userid' => $userid,
            ]);
            if ($progress) {
                $DB->delete_records('simplevideotracker_segments', ['progressid' => $progress->id]);
                $DB->delete_records('simplevideotracker_progress', ['id' => $progress->id]);
            }
        }
    }

    /**
     * Add users with data in a module context to the supplied user list.
     *
     * @param userlist $userlist User list.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $sql = "SELECT p.userid
                  FROM {simplevideotracker_progress} p
                  JOIN {course_modules} cm ON cm.instance = p.simplevideotrackerid
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                 WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', $sql, ['modname' => 'simplevideotracker', 'cmid' => $context->instanceid]);
    }

    /**
     * Delete data for approved users in a module context.
     *
     * @param approved_userlist $userlist Approved users.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('simplevideotracker', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['instanceid'] = $cm->instance;
        $progressids = $DB->get_fieldset_select(
            'simplevideotracker_progress',
            'id',
            "simplevideotrackerid = :instanceid AND userid $insql",
            $params
        );
        if ($progressids) {
            $DB->delete_records_list('simplevideotracker_segments', 'progressid', array_map('intval', $progressids));
            $DB->delete_records_list('simplevideotracker_progress', 'id', array_map('intval', $progressids));
        }
    }
}
