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
 * Progress accounting for Simple Video Tracker.
 *
 * @package   mod_simplevideotracker
 * @copyright 2026 onwards Simple Video Tracker contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_simplevideotracker\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Server-side progress verification and watched-range accounting.
 */
class progress_manager {
    /** Maximum time gap considered for one server-side advancement budget. */
    private const MAX_ELAPSED_CREDIT = 60;

    /** Allow a small drift above real-time to accommodate browser/network timing. */
    private const REALTIME_MULTIPLIER = 1.35;

    /** Maximum absolute duration mismatch accepted from browser metadata. */
    private const MAX_DURATION_MISMATCH = 5.0;

    /** Maximum time to wait for another heartbeat for the same learner/activity. */
    private const LOCK_TIMEOUT = 10;

    /** Relative duration mismatch accepted from browser metadata. */
    private const DURATION_MISMATCH_RATIO = 0.002;

    /**
     * Get or create a user progress record.
     *
     * @param int $instanceid Activity instance ID.
     * @param int $userid User ID.
     * @return \stdClass
     */
    public static function get_or_create(int $instanceid, int $userid): \stdClass {
        global $DB;

        $progress = $DB->get_record('simplevideotracker_progress', [
            'simplevideotrackerid' => $instanceid,
            'userid' => $userid,
        ]);
        if ($progress) {
            return $progress;
        }

        $now = time();
        $record = (object)[
            'simplevideotrackerid' => $instanceid,
            'userid' => $userid,
            'duration' => 0,
            'alloweduntil' => 0,
            'lastposition' => 0,
            'watchedseconds' => 0,
            'progress' => 0,
            'completed' => 0,
            'lastping' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('simplevideotracker_progress', $record);
        return $record;
    }

    /**
     * Begin a browser playback session without granting any progress.
     *
     * @param int $instanceid Activity instance ID.
     * @param int $userid User ID.
     * @return \stdClass
     */
    public static function start_session(int $instanceid, int $userid): \stdClass {
        $lock = self::acquire_progress_lock($instanceid, $userid);
        try {
            return self::start_session_locked($instanceid, $userid);
        } finally {
            $lock->release();
        }
    }

    /**
     * Begin a browser playback session while the caller holds the progress lock.
     *
     * @param int $instanceid Activity instance ID.
     * @param int $userid User ID.
     * @return \stdClass
     */
    private static function start_session_locked(int $instanceid, int $userid): \stdClass {
        global $DB;

        $progress = self::get_or_create($instanceid, $userid);
        $now = time();
        $progress->lastping = $now;
        $progress->timemodified = $now;
        $DB->update_record('simplevideotracker_progress', $progress);
        return $progress;
    }

    /**
     * Check browser media metadata against the privileged, stored duration.
     *
     * The browser duration is never used as the completion denominator. It is
     * only a consistency signal to catch stale or mismatched media.
     *
     * @param float $trustedduration Authoritative activity duration.
     * @param float $reportedduration Browser-reported duration.
     * @return bool
     */
    public static function reported_duration_matches(float $trustedduration, float $reportedduration): bool {
        if (!video_validator::is_valid_duration($trustedduration) || !is_finite($reportedduration)
                || $reportedduration <= 0.0) {
            return false;
        }

        $tolerance = min(
            self::MAX_DURATION_MISMATCH,
            max(1.0, $trustedduration * self::DURATION_MISMATCH_RATIO)
        );
        return abs($trustedduration - $reportedduration) <= $tolerance;
    }

    /**
     * Validate and save a playback heartbeat.
     *
     * Heartbeats for the same activity/user pair are serialised with Moodle's
     * Lock API. Without this, parallel requests could all observe the same
     * lastping value and each receive an independent real-time credit budget.
     *
     * @param \cm_info $cm Course-module information.
     * @param \stdClass $instance Activity instance.
     * @param int $userid User ID.
     * @param float $from Natural playback segment start.
     * @param float $to Natural playback segment end.
     * @param float $position Current playback position.
     * @param float $reportedduration Browser-reported duration for consistency checking only.
     * @param bool $bypassseek Whether this user may bypass seek protection.
     * @return array Response state for the browser.
     */
    public static function save(
        \cm_info $cm,
        \stdClass $instance,
        int $userid,
        float $from,
        float $to,
        float $position,
        float $reportedduration,
        bool $bypassseek = false
    ): array {
        $lock = self::acquire_progress_lock((int)$instance->id, $userid);
        try {
            return self::save_locked(
                $cm,
                $instance,
                $userid,
                $from,
                $to,
                $position,
                $reportedduration,
                $bypassseek
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * Validate and save a playback heartbeat while the progress lock is held.
     *
     * @param \cm_info $cm Course-module information.
     * @param \stdClass $instance Activity instance.
     * @param int $userid User ID.
     * @param float $from Natural playback segment start.
     * @param float $to Natural playback segment end.
     * @param float $position Current playback position.
     * @param float $reportedduration Browser-reported duration for consistency checking only.
     * @param bool $bypassseek Whether this user may bypass seek protection.
     * @return array Response state for the browser.
     */
    private static function save_locked(
        \cm_info $cm,
        \stdClass $instance,
        int $userid,
        float $from,
        float $to,
        float $position,
        float $reportedduration,
        bool $bypassseek = false
    ): array {
        global $DB;

        $now = time();
        $transaction = $DB->start_delegated_transaction();

        // Settings/video may have changed while this request was waiting for its lock.
        // Always re-read the activity inside the critical section so stale browser
        // requests cannot recreate progress using pre-change settings.
        $instance = $DB->get_record('simplevideotracker', ['id' => (int)$instance->id], '*', MUST_EXIST);
        $progress = self::get_or_create((int)$instance->id, $userid);
        $effectiveduration = (float)($instance->videoduration ?? 0.0);

        if (!video_validator::is_valid_duration($effectiveduration)) {
            $progress->duration = 0.0;
            $progress->progress = 0.0;
            $progress->completed = 0;
            $progress->lastping = $now;
            $progress->timemodified = $now;
            $DB->update_record('simplevideotracker_progress', $progress);
            $transaction->allow_commit();
            self::update_completion($cm, $userid);
            return self::response($progress, false, 'duration_unset', 0.0, 0.0);
        }

        // Store a snapshot of the trusted duration for reporting/exports. Never derive it from the learner request.
        $progress->duration = round($effectiveduration, 3);

        $from = max(0.0, min($effectiveduration, $from));
        $to = max(0.0, min($effectiveduration, $to));
        $position = max(0.0, min($effectiveduration, $position));

        if (!self::reported_duration_matches($effectiveduration, $reportedduration)) {
            $progress->lastping = $now;
            $progress->timemodified = $now;
            $DB->update_record('simplevideotracker_progress', $progress);
            $transaction->allow_commit();
            return self::response($progress, false, 'duration_mismatch', 0.0, 0.0);
        }

        $accepted = true;
        $reason = 'ok';
        $creditedfrom = 0.0;
        $creditedto = 0.0;
        $elapsed = max(0, $now - (int)$progress->lastping);
        $elapsedcredit = min(self::MAX_ELAPSED_CREDIT, $elapsed);
        $maxnaturaladvance = $elapsedcredit * self::REALTIME_MULTIPLIER;

        // A heartbeat only represents forward natural playback. Backward seeks save position but no range.
        $segmentstart = $from;
        $segmentend = $to;
        if ($segmentend < $segmentstart) {
            $segmentend = $segmentstart;
        }
        $requestedstart = $segmentstart;
        $requestedend = $segmentend;

        $preventseeking = !empty($instance->preventseeking) && !$bypassseek;
        $tolerance = max(0.0, min(10.0, (float)$instance->seektolerance));

        if ($segmentend > $segmentstart) {
            if ($preventseeking && $segmentstart > (float)$progress->alloweduntil + $tolerance) {
                $accepted = false;
                $reason = 'ahead_of_verified_progress';
            } else {
                $segmentlength = $segmentend - $segmentstart;
                if ($maxnaturaladvance <= 0.0) {
                    $accepted = false;
                    $reason = 'no_elapsed_time';
                } else {
                    // Do not credit faster-than-realtime jumps, even when seek prevention is disabled.
                    if ($segmentlength > $maxnaturaladvance) {
                        $segmentend = $segmentstart + $maxnaturaladvance;
                    }

                    if ($preventseeking && $segmentend > (float)$progress->alloweduntil) {
                        // When extending the verified frontier, eliminate a tiny timing gap at the boundary.
                        $segmentstart = min($segmentstart, (float)$progress->alloweduntil);
                        $maxend = (float)$progress->alloweduntil + $maxnaturaladvance;
                        $segmentend = min($segmentend, $maxend);
                    }

                    $segmentend = min($segmentend, $effectiveduration);
                    if ($segmentend > $segmentstart) {
                        self::merge_segment((int)$progress->id, $segmentstart, $segmentend, $now);
                        $creditedfrom = $segmentstart;
                        $creditedto = $segmentend;
                    }

                    // "accepted" means the full requested range was accounted for.
                    // Partial credit is reported explicitly so the browser can retry only the remainder.
                    if ($creditedto + 0.0001 < $requestedend
                            || ($creditedto > 0.0 && $creditedfrom > $requestedstart + 0.0001)) {
                        $accepted = false;
                        $reason = 'capped_to_elapsed_time';
                    }
                }
            }
        }

        $progress->watchedseconds = self::calculate_watched_seconds((int)$progress->id, $effectiveduration);
        $progress->alloweduntil = self::calculate_contiguous_frontier((int)$progress->id, $effectiveduration);

        if ($preventseeking && $position > (float)$progress->alloweduntil + $tolerance) {
            $progress->lastposition = (float)$progress->alloweduntil;
            $accepted = false;
            if ($reason === 'ok') {
                $reason = 'position_ahead_of_verified_progress';
            }
        } else {
            $progress->lastposition = $position;
        }

        $progress->progress = min(100.0, ((float)$progress->watchedseconds / $effectiveduration) * 100.0);
        $progress->completed = ((float)$progress->progress + 0.0001 >= (float)$instance->completionpercent) ? 1 : 0;
        $progress->lastping = $now;
        $progress->timemodified = $now;
        $DB->update_record('simplevideotracker_progress', $progress);
        $transaction->allow_commit();

        self::update_completion($cm, $userid);

        return self::response($progress, $accepted, $reason, $creditedfrom, $creditedto);
    }

    /**
     * Acquire the cross-node lock for one learner's progress row.
     *
     * A short-lived instance guard serialises lock acquisition with instance-wide
     * maintenance (recalculate/reset). The guard is released as soon as the user
     * lock is held, so heartbeats from different learners do not serialize for
     * the duration of their database work.
     *
     * @param int $instanceid Activity instance ID.
     * @param int $userid User ID.
     * @return \core\lock\lock
     */
    private static function acquire_progress_lock(int $instanceid, int $userid): \core\lock\lock {
        $guard = self::acquire_instance_guard($instanceid);
        try {
            $lock = self::acquire_progress_lock_direct($instanceid, $userid);
            try {
                // Register the learner row before releasing the instance guard.
                // Instance-wide maintenance can then discover and wait on every
                // heartbeat that has already passed the guard.
                self::get_or_create($instanceid, $userid);
                return $lock;
            } catch (\Throwable $exception) {
                $lock->release();
                throw $exception;
            }
        } finally {
            $guard->release();
        }
    }

    /**
     * Acquire the instance-wide maintenance lock.
     *
     * External mutators which wrap Simple Video Tracker changes in their own database
     * transaction must hold this lock until that outer transaction is committed.
     * This keeps learner heartbeats from observing a half-applied media/settings change.
     *
     * @param int $instanceid Activity instance ID.
     * @return \core\lock\lock
     */
    public static function acquire_instance_maintenance_lock(int $instanceid): \core\lock\lock {
        return self::acquire_instance_guard($instanceid);
    }

    /**
     * Acquire the instance coordination guard used by maintenance and heartbeat lock acquisition.
     *
     * @param int $instanceid Activity instance ID.
     * @return \core\lock\lock
     */
    private static function acquire_instance_guard(int $instanceid): \core\lock\lock {
        $factory = \core\lock\lock_config::get_lock_factory('mod_simplevideotracker_progress');
        $lock = $factory->get_lock('instance:' . $instanceid . ':maintenance', self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }
        return $lock;
    }

    /**
     * Acquire a learner lock without taking the instance guard.
     *
     * Callers must already hold the instance guard, or be called by acquire_progress_lock().
     *
     * @param int $instanceid Activity instance ID.
     * @param int $userid User ID.
     * @return \core\lock\lock
     */
    private static function acquire_progress_lock_direct(int $instanceid, int $userid): \core\lock\lock {
        $factory = \core\lock\lock_config::get_lock_factory('mod_simplevideotracker_progress');
        $resource = 'instance:' . $instanceid . ':user:' . $userid;
        $lock = $factory->get_lock($resource, self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }
        return $lock;
    }

    /**
     * Build a browser response from a progress row.
     *
     * @param \stdClass $progress Progress record.
     * @param bool $accepted Whether the request was fully accepted.
     * @param string $reason Verification result code.
     * @param float $creditedfrom Start of the range actually credited by this request, or 0.
     * @param float $creditedto End of the range actually credited by this request, or 0.
     * @return array
     */
    private static function response(
        \stdClass $progress,
        bool $accepted,
        string $reason,
        float $creditedfrom,
        float $creditedto
    ): array {
        return [
            'accepted' => $accepted,
            'reason' => $reason,
            'creditedfrom' => $creditedfrom,
            'creditedto' => $creditedto,
            'alloweduntil' => (float)$progress->alloweduntil,
            'lastposition' => (float)$progress->lastposition,
            'watchedseconds' => (float)$progress->watchedseconds,
            'duration' => (float)$progress->duration,
            'progress' => (float)$progress->progress,
            'completed' => (bool)$progress->completed,
        ];
    }

    /**
     * Merge a watched range with overlapping/adjacent ranges.
     *
     * @param int $progressid Progress row ID.
     * @param float $start Range start.
     * @param float $end Range end.
     * @param int $now Timestamp.
     */
    private static function merge_segment(int $progressid, float $start, float $end, int $now): void {
        global $DB;

        if ($end <= $start) {
            return;
        }
        $adjacency = 0.75;
        $sql = 'progressid = :progressid AND endtime >= :minstart AND starttime <= :maxend';
        $segments = $DB->get_records_select(
            'simplevideotracker_segments',
            $sql,
            [
                'progressid' => $progressid,
                'minstart' => $start - $adjacency,
                'maxend' => $end + $adjacency,
            ],
            'starttime ASC'
        );

        $mergedstart = $start;
        $mergedend = $end;
        if ($segments) {
            $ids = [];
            foreach ($segments as $segment) {
                $mergedstart = min($mergedstart, (float)$segment->starttime);
                $mergedend = max($mergedend, (float)$segment->endtime);
                $ids[] = (int)$segment->id;
            }
            $DB->delete_records_list('simplevideotracker_segments', 'id', $ids);
        }

        $DB->insert_record('simplevideotracker_segments', (object)[
            'progressid' => $progressid,
            'starttime' => round($mergedstart, 3),
            'endtime' => round($mergedend, 3),
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Calculate unique watched seconds from merged segments.
     *
     * @param int $progressid Progress row ID.
     * @param float $duration Authoritative video duration.
     * @return float
     */
    private static function calculate_watched_seconds(int $progressid, float $duration): float {
        global $DB;

        $segments = $DB->get_records('simplevideotracker_segments', ['progressid' => $progressid], 'starttime ASC');
        $total = 0.0;
        foreach ($segments as $segment) {
            $start = max(0.0, min($duration, (float)$segment->starttime));
            $end = max(0.0, min($duration, (float)$segment->endtime));
            $total += max(0.0, $end - $start);
        }
        return round(min($duration, $total), 3);
    }

    /**
     * Calculate the continuous watched prefix from 0 seconds.
     *
     * @param int $progressid Progress row ID.
     * @param float $duration Authoritative video duration.
     * @return float
     */
    private static function calculate_contiguous_frontier(int $progressid, float $duration): float {
        global $DB;

        $segments = $DB->get_records('simplevideotracker_segments', ['progressid' => $progressid], 'starttime ASC');
        $frontier = 0.0;
        $adjacency = 0.75;
        foreach ($segments as $segment) {
            $start = max(0.0, min($duration, (float)$segment->starttime));
            $end = max(0.0, min($duration, (float)$segment->endtime));
            if ($start > $frontier + $adjacency) {
                break;
            }
            $frontier = max($frontier, $end);
        }
        return round(min($duration, $frontier), 3);
    }

    /**
     * Recalculate stored completion values after settings change.
     *
     * @param int $instanceid Activity instance ID.
     */
    public static function recalculate_instance(int $instanceid): void {
        $guard = self::acquire_instance_guard($instanceid);
        try {
            self::recalculate_instance_locked($instanceid);
        } finally {
            $guard->release();
        }
    }

    /**
     * Recalculate stored completion values while the caller holds the instance maintenance lock.
     *
     * This variant exists for external mutators which must keep the instance lock held
     * until their own outer database transaction commits.
     *
     * @param int $instanceid Activity instance ID.
     */
    public static function recalculate_instance_locked(int $instanceid): void {
        global $DB;

        $locks = [];
        $instance = null;
        $records = [];
        try {
            $userids = $DB->get_fieldset_select(
                'simplevideotracker_progress',
                'userid',
                'simplevideotrackerid = :id',
                ['id' => $instanceid]
            );
            sort($userids, SORT_NUMERIC);
            foreach ($userids as $userid) {
                $locks[] = self::acquire_progress_lock_direct($instanceid, (int)$userid);
            }

            $instance = $DB->get_record('simplevideotracker', ['id' => $instanceid], '*', MUST_EXIST);
            $duration = (float)($instance->videoduration ?? 0.0);
            $validduration = video_validator::is_valid_duration($duration);
            $records = $DB->get_records('simplevideotracker_progress', ['simplevideotrackerid' => $instanceid]);
            foreach ($records as $progress) {
                $progress->duration = $validduration ? round($duration, 3) : 0.0;
                if ($validduration) {
                    $progress->watchedseconds = self::calculate_watched_seconds((int)$progress->id, $duration);
                    $progress->alloweduntil = self::calculate_contiguous_frontier((int)$progress->id, $duration);
                    $progress->lastposition = min($duration, max(0.0, (float)$progress->lastposition));
                    $progress->progress = min(100.0, ((float)$progress->watchedseconds / $duration) * 100.0);
                } else {
                    $progress->watchedseconds = 0.0;
                    $progress->alloweduntil = 0.0;
                    $progress->lastposition = 0.0;
                    $progress->progress = 0.0;
                }
                $progress->completed = ((float)$progress->progress + 0.0001
                    >= (float)$instance->completionpercent) ? 1 : 0;
                $progress->timemodified = time();
                $DB->update_record('simplevideotracker_progress', $progress);
            }
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }

        if ($instance && ($cm = get_coursemodule_from_instance(
                'simplevideotracker',
                $instanceid,
                $instance->course,
                false,
                IGNORE_MISSING
            ))) {
            $course = get_course($instance->course);
            $cminfo = get_fast_modinfo($course)->get_cm($cm->id);
            foreach ($records as $progress) {
                self::update_completion($cminfo, (int)$progress->userid);
            }
        }
    }

    /**
     * Delete all progress for one activity instance.
     *
     * @param int $instanceid Activity instance ID.
     */
    public static function reset_instance(int $instanceid): void {
        $guard = self::acquire_instance_guard($instanceid);
        try {
            self::reset_instance_locked($instanceid);
        } finally {
            $guard->release();
        }
    }

    /**
     * Delete all progress while the caller holds the instance maintenance lock.
     *
     * This variant is used when an outer transaction changes the media/settings and
     * must keep heartbeats blocked until that transaction has committed.
     *
     * @param int $instanceid Activity instance ID.
     */
    public static function reset_instance_locked(int $instanceid): void {
        global $DB;

        $locks = [];
        try {
            $userids = $DB->get_fieldset_select(
                'simplevideotracker_progress',
                'userid',
                'simplevideotrackerid = :id',
                ['id' => $instanceid]
            );
            sort($userids, SORT_NUMERIC);
            foreach ($userids as $userid) {
                $locks[] = self::acquire_progress_lock_direct($instanceid, (int)$userid);
            }

            $transaction = $DB->start_delegated_transaction();
            $ids = $DB->get_fieldset_select(
                'simplevideotracker_progress',
                'id',
                'simplevideotrackerid = :id',
                ['id' => $instanceid]
            );
            if ($ids) {
                $DB->delete_records_list('simplevideotracker_segments', 'progressid', array_map('intval', $ids));
            }
            $DB->delete_records('simplevideotracker_progress', ['simplevideotrackerid' => $instanceid]);
            $transaction->allow_commit();
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }

    /**
     * Update Moodle's activity completion cache/state for a user.
     *
     * @param \cm_info $cm Course-module information.
     * @param int $userid User ID.
     */
    private static function update_completion(\cm_info $cm, int $userid): void {
        $course = get_course($cm->course);
        $completion = new \completion_info($course);
        if ($completion->is_enabled($cm)) {
            $completion->update_state($cm, COMPLETION_UNKNOWN, $userid);
        }
    }
}
