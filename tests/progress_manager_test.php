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
 * Tests for Simple Video Tracker progress verification.
 *
 * @package   mod_simplevideotracker
 * @copyright 2026 onwards Simple Video Tracker contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_simplevideotracker;

defined('MOODLE_INTERNAL') || die();

use mod_simplevideotracker\local\progress_manager;

/**
 * Progress-verification tests.
 */
final class progress_manager_test extends \advanced_testcase {
    /**
     * Prepare database isolation for each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Browser metadata is only accepted when it agrees with the stored trusted duration.
     */
    public function test_reported_duration_must_match_trusted_duration(): void {
        $this->assertTrue(progress_manager::reported_duration_matches(600.0, 600.0));
        $this->assertTrue(progress_manager::reported_duration_matches(600.0, 600.9));
        $this->assertFalse(progress_manager::reported_duration_matches(600.0, 30.0));
        $this->assertFalse(progress_manager::reported_duration_matches(600.0, 0.0));
        $this->assertFalse(progress_manager::reported_duration_matches(0.0, 600.0));
    }

    /**
     * Long media receive a small bounded tolerance, not a percentage-sized loophole.
     */
    public function test_duration_mismatch_tolerance_is_bounded(): void {
        $this->assertTrue(progress_manager::reported_duration_matches(7200.0, 7204.9));
        $this->assertFalse(progress_manager::reported_duration_matches(7200.0, 7205.1));
    }

    /**
     * Ordinary forward playback credits the unique watched range.
     */
    public function test_save_credits_natural_playback(): void {
        [$cm, $instance, $user] = $this->create_progress_fixture();
        $this->set_elapsed((int)$instance->id, (int)$user->id, 10);

        $result = progress_manager::save($cm, $instance, (int)$user->id, 0.0, 5.0, 5.0, 100.0);

        $this->assertTrue($result['accepted']);
        $this->assertSame('ok', $result['reason']);
        $this->assertEqualsWithDelta(5.0, $result['watchedseconds'], 0.001);
        $this->assertEqualsWithDelta(5.0, $result['alloweduntil'], 0.001);
        $this->assertEqualsWithDelta(5.0, $result['progress'], 0.001);
        $this->assertFalse($result['completed']);
    }

    /**
     * Seek protection refuses ranges that begin well ahead of verified progress.
     */
    public function test_save_rejects_range_ahead_of_verified_frontier(): void {
        [$cm, $instance, $user] = $this->create_progress_fixture();
        $this->set_elapsed((int)$instance->id, (int)$user->id, 10);

        $result = progress_manager::save($cm, $instance, (int)$user->id, 20.0, 25.0, 25.0, 100.0);

        $this->assertFalse($result['accepted']);
        $this->assertSame('ahead_of_verified_progress', $result['reason']);
        $this->assertEqualsWithDelta(0.0, $result['watchedseconds'], 0.001);
        $this->assertEqualsWithDelta(0.0, $result['alloweduntil'], 0.001);
    }

    /**
     * Disabling seek prevention does not disable the server-side real-time credit cap.
     */
    public function test_save_caps_faster_than_realtime_when_seek_prevention_is_disabled(): void {
        [$cm, $instance, $user] = $this->create_progress_fixture(['preventseeking' => 0]);
        $this->set_elapsed((int)$instance->id, (int)$user->id, 4);

        $result = progress_manager::save($cm, $instance, (int)$user->id, 0.0, 20.0, 20.0, 100.0);

        $this->assertFalse($result['accepted']);
        $this->assertSame('capped_to_elapsed_time', $result['reason']);
        $this->assertGreaterThanOrEqual(5.0, $result['watchedseconds']);
        $this->assertLessThan(8.0, $result['watchedseconds']);
        $this->assertEqualsWithDelta(0.0, $result['creditedfrom'], 0.001);
        $this->assertEqualsWithDelta($result['watchedseconds'], $result['creditedto'], 0.001);
        $this->assertEqualsWithDelta($result['watchedseconds'], $result['alloweduntil'], 0.001);
    }

    /**
     * Replaying an already watched overlap does not inflate unique watched seconds.
     */
    public function test_replayed_overlap_does_not_inflate_progress(): void {
        [$cm, $instance, $user] = $this->create_progress_fixture();
        $this->set_elapsed((int)$instance->id, (int)$user->id, 30);
        $first = progress_manager::save($cm, $instance, (int)$user->id, 0.0, 10.0, 10.0, 100.0);
        $this->assertEqualsWithDelta(10.0, $first['watchedseconds'], 0.001);

        $this->set_elapsed((int)$instance->id, (int)$user->id, 30);
        $second = progress_manager::save($cm, $instance, (int)$user->id, 5.0, 10.0, 10.0, 100.0);

        $this->assertEqualsWithDelta(10.0, $second['watchedseconds'], 0.001);
        $this->assertEqualsWithDelta(10.0, $second['progress'], 0.001);
    }

    /**
     * Reaching the configured watched percentage marks the progress row complete.
     */
    public function test_completion_threshold_is_applied_to_unique_watched_seconds(): void {
        [$cm, $instance, $user] = $this->create_progress_fixture(['completionpercent' => 80]);
        $this->set_elapsed((int)$instance->id, (int)$user->id, 60);

        $result = progress_manager::save($cm, $instance, (int)$user->id, 0.0, 80.0, 80.0, 100.0);

        $this->assertTrue($result['accepted']);
        $this->assertEqualsWithDelta(80.0, $result['watchedseconds'], 0.001);
        $this->assertEqualsWithDelta(80.0, $result['progress'], 0.001);
        $this->assertTrue($result['completed']);
    }


    /**
     * A partially credited range can resume from the exact credited endpoint without losing the tail.
     */
    public function test_partial_credit_can_retry_remaining_range(): void {
        [$cm, $instance, $user] = $this->create_progress_fixture(['preventseeking' => 0]);
        $this->set_elapsed((int)$instance->id, (int)$user->id, 4);

        $first = progress_manager::save($cm, $instance, (int)$user->id, 0.0, 20.0, 20.0, 100.0);
        $this->assertFalse($first['accepted']);
        $this->assertSame('capped_to_elapsed_time', $first['reason']);
        $this->assertGreaterThan(0.0, $first['creditedto']);
        $this->assertLessThan(20.0, $first['creditedto']);

        $this->set_elapsed((int)$instance->id, (int)$user->id, 30);
        $second = progress_manager::save(
            $cm,
            $instance,
            (int)$user->id,
            (float)$first['creditedto'],
            20.0,
            20.0,
            100.0
        );

        $this->assertTrue($second['accepted']);
        $this->assertSame('ok', $second['reason']);
        $this->assertEqualsWithDelta(20.0, $second['watchedseconds'], 0.001);
    }

    /**
     * A save waiting behind a settings change re-reads the current authoritative instance values.
     */
    public function test_save_refreshes_instance_settings_inside_lock(): void {
        global $DB;

        [$cm, $instance, $user] = $this->create_progress_fixture();
        $this->set_elapsed((int)$instance->id, (int)$user->id, 10);
        $DB->set_field('simplevideotracker', 'videoduration', 200.0, ['id' => $instance->id]);

        $result = progress_manager::save($cm, $instance, (int)$user->id, 0.0, 5.0, 5.0, 100.0);

        $this->assertFalse($result['accepted']);
        $this->assertSame('duration_mismatch', $result['reason']);
        $this->assertEqualsWithDelta(200.0, $result['duration'], 0.001);
        $this->assertEqualsWithDelta(0.0, $result['watchedseconds'], 0.001);
    }

    /**
     * Recalculation uses current settings and reset removes both progress and segments.
     */
    public function test_recalculate_and_reset_instance_keep_progress_consistent(): void {
        global $DB;

        [$cm, $instance, $user] = $this->create_progress_fixture();
        $this->set_elapsed((int)$instance->id, (int)$user->id, 30);
        progress_manager::save($cm, $instance, (int)$user->id, 0.0, 10.0, 10.0, 100.0);

        $DB->set_field('simplevideotracker', 'completionpercent', 5, ['id' => $instance->id]);
        progress_manager::recalculate_instance((int)$instance->id);
        $progress = $DB->get_record('simplevideotracker_progress', [
            'simplevideotrackerid' => $instance->id,
            'userid' => $user->id,
        ], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(10.0, (float)$progress->watchedseconds, 0.001);
        $this->assertSame(1, (int)$progress->completed);

        progress_manager::reset_instance((int)$instance->id);
        $this->assertFalse($DB->record_exists('simplevideotracker_progress', ['simplevideotrackerid' => $instance->id]));
        $this->assertFalse($DB->record_exists('simplevideotracker_segments', ['progressid' => $progress->id]));
    }

    /**
     * A browser duration mismatch cannot add watched credit.
     */
    public function test_duration_mismatch_does_not_change_watched_progress(): void {
        [$cm, $instance, $user] = $this->create_progress_fixture();
        $this->set_elapsed((int)$instance->id, (int)$user->id, 30);

        $result = progress_manager::save($cm, $instance, (int)$user->id, 0.0, 10.0, 10.0, 20.0);

        $this->assertFalse($result['accepted']);
        $this->assertSame('duration_mismatch', $result['reason']);
        $this->assertEqualsWithDelta(0.0, $result['watchedseconds'], 0.001);
        $this->assertEqualsWithDelta(0.0, $result['progress'], 0.001);
    }

    /**
     * Create a course, learner, activity, cm_info, and initial progress row.
     *
     * @param array $overrides Activity field overrides.
     * @return array{0: \cm_info, 1: \stdClass, 2: \stdClass}
     */
    private function create_progress_fixture(array $overrides = []): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $data = array_merge([
            'course' => $course->id,
            'videoduration' => 100.0,
            'completionpercent' => 80,
            'preventseeking' => 1,
            'seektolerance' => 3,
            'heartbeat' => 5,
        ], $overrides);
        $activity = $this->getDataGenerator()->create_module('simplevideotracker', $data);
        $instance = $DB->get_record('simplevideotracker', ['id' => $activity->id], '*', MUST_EXIST);
        $cm = get_fast_modinfo($course)->get_cm((int)$activity->cmid);

        progress_manager::start_session((int)$instance->id, (int)$user->id);
        return [$cm, $instance, $user];
    }

    /**
     * Pretend the previous heartbeat occurred a fixed number of seconds ago.
     *
     * @param int $instanceid Activity instance ID.
     * @param int $userid User ID.
     * @param int $seconds Elapsed seconds.
     */
    private function set_elapsed(int $instanceid, int $userid, int $seconds): void {
        global $DB;

        $DB->set_field('simplevideotracker_progress', 'lastping', time() - $seconds, [
            'simplevideotrackerid' => $instanceid,
            'userid' => $userid,
        ]);
    }
}
