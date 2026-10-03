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
 * Tests for Simple Video Tracker external APIs.
 *
 * @package   mod_simplevideotracker
 * @category  test
 * @copyright 2026 onwards Simple Video Tracker contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_simplevideotracker;

defined('MOODLE_INTERNAL') || die();

use mod_simplevideotracker\external\create_activity;
use mod_simplevideotracker\external\set_video;

/**
 * External API regression tests.
 */
final class external_test extends \advanced_testcase {
    /**
     * API-created intro text survives add_moduleinfo() editor processing.
     */
    public function test_create_activity_preserves_intro(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $result = create_activity::execute(
            (int)$course->id,
            0,
            'API video',
            '<p>API introduction</p>'
        );

        $instance = $DB->get_record('simplevideotracker', ['id' => $result['instanceid']], '*', MUST_EXIST);
        $this->assertSame('<p>API introduction</p>', $instance->intro);
        $this->assertSame(FORMAT_HTML, (int)$instance->introformat);
        $this->assertEqualsWithDelta(0.0, (float)$instance->videoduration, 0.001);
    }

    /**
     * Privileged set_video stores the trusted duration and media file.
     */
    public function test_set_video_stores_authoritative_duration(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $created = create_activity::execute((int)$course->id, 0, 'API video');

        $usercontext = \context_user::instance($USER->id);
        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string((object)[
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/incoming/',
            'filename' => '강의 영상 01.mp4',
            'mimetype' => 'video/quicktime',
        ], 'fake video bytes');

        $result = set_video::execute((int)$created['cmid'], $draftitemid, '321.500000');
        $instance = $DB->get_record('simplevideotracker', ['id' => $created['instanceid']], '*', MUST_EXIST);

        $this->assertTrue($result['success']);
        $this->assertSame('강의 영상 01.mp4', $result['filename']);
        $this->assertEqualsWithDelta(321.5, (float)$result['duration'], 0.001);
        $this->assertEqualsWithDelta(321.5, (float)$instance->videoduration, 0.001);
    }
    /**
     * set_video can store media even when a client omits duration.
     */
    public function test_set_video_can_store_file_before_duration_is_known(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $created = create_activity::execute((int)$course->id, 0, 'API video without duration');

        $usercontext = \context_user::instance($USER->id);
        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string((object)[
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'upload-first.mp4',
            'mimetype' => 'video/mp4',
        ], 'fake video bytes');

        $result = set_video::execute((int)$created['cmid'], $draftitemid);
        $instance = $DB->get_record('simplevideotracker', ['id' => $created['instanceid']], '*', MUST_EXIST);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['durationconfigured']);
        $this->assertEqualsWithDelta(0.0, (float)$instance->videoduration, 0.001);
        $context = \context_module::instance((int)$created['cmid']);
        $this->assertNotNull(simplevideotracker_get_video_file($context->id));
    }

}
