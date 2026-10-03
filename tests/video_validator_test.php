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
 * Tests for Simple Video Tracker media validation.
 *
 * @package   mod_simplevideotracker
 * @copyright 2026 onwards Simple Video Tracker contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_simplevideotracker;

defined('MOODLE_INTERNAL') || die();

use mod_simplevideotracker\local\video_validator;

/**
 * Media validation tests.
 */
final class video_validator_test extends \advanced_testcase {
    /**
     * Trusted duration must be positive and bounded.
     */
    public function test_duration_validation(): void {
        $this->assertFalse(video_validator::is_valid_duration(0.0));
        $this->assertFalse(video_validator::is_valid_duration(-1.0));
        $this->assertTrue(video_validator::is_valid_duration(1.0));
        $this->assertTrue(video_validator::is_valid_duration(3600.125));
        $this->assertTrue(video_validator::is_valid_duration(video_validator::MAX_DURATION));
        $this->assertFalse(video_validator::is_valid_duration(video_validator::MAX_DURATION + 0.001));
    }

    /**
     * A single supported file in the user's draft area is accepted.
     */
    public function test_validate_single_supported_draft_file(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $context = \context_user::instance($user->id);
        $draftitemid = file_get_unused_draft_itemid();
        $file = get_file_storage()->create_file_from_string((object)[
            'contextid' => $context->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'lecture.mp4',
        ], 'test video bytes');

        $validated = video_validator::validate_draft_item($draftitemid, (int)$user->id);
        $this->assertSame($file->get_id(), $validated->get_id());
    }

    /**
     * Unsupported extensions are rejected even when placed directly in a draft area.
     */
    public function test_reject_unsupported_draft_file(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $context = \context_user::instance($user->id);
        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string((object)[
            'contextid' => $context->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'payload.html',
        ], '<script>alert(1)</script>');

        $this->expectException(\moodle_exception::class);
        video_validator::validate_draft_item($draftitemid, (int)$user->id);
    }

    /**
     * More than one file is rejected because the activity has a single-video invariant.
     */
    public function test_reject_multiple_draft_files(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $context = \context_user::instance($user->id);
        $draftitemid = file_get_unused_draft_itemid();
        $fs = get_file_storage();
        foreach (['one.mp4', 'two.webm'] as $filename) {
            $fs->create_file_from_string((object)[
                'contextid' => $context->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftitemid,
                'filepath' => '/',
                'filename' => $filename,
            ], 'test video bytes');
        }

        $this->expectException(\moodle_exception::class);
        video_validator::validate_draft_item($draftitemid, (int)$user->id);
    }

    /**
     * Allowed video extensions tolerate video/* MIME variants from upload clients.
     */
    public function test_accept_video_mime_variant_for_allowed_extension(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $context = \context_user::instance($user->id);
        $draftitemid = file_get_unused_draft_itemid();
        $file = get_file_storage()->create_file_from_string((object)[
            'contextid' => $context->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/incoming/',
            'filename' => 'lecture.mp4',
            'mimetype' => 'video/quicktime',
        ], 'test video bytes');

        $validated = video_validator::validate_draft_item($draftitemid, (int)$user->id);
        $this->assertSame($file->get_id(), $validated->get_id());
    }


    /**
     * Browser source hints are canonicalized from the allow-listed extension, not upload MIME metadata.
     */
    public function test_browser_mimetype_is_derived_from_extension(): void {
        $this->assertSame('video/mp4', video_validator::get_browser_mimetype('lecture.mp4'));
        $this->assertSame('video/mp4', video_validator::get_browser_mimetype('lecture.M4V'));
        $this->assertSame('video/webm', video_validator::get_browser_mimetype('lecture.webm'));
        $this->assertSame('video/ogg', video_validator::get_browser_mimetype('lecture.ogv'));
        $this->assertSame('', video_validator::get_browser_mimetype('lecture.mov'));
    }

    /**
     * Empty draft IDs report a plugin-specific validation error.
     */
    public function test_reject_empty_draft_with_specific_error(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $draftitemid = file_get_unused_draft_itemid();

        try {
            video_validator::validate_draft_item($draftitemid, (int)$user->id);
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('videodraftempty', $exception->errorcode);
        }
    }

    /**
     * External duration accepts numeric strings but rejects non-numeric input.
     */
    public function test_external_duration_validation(): void {
        $this->assertEqualsWithDelta(321.5, video_validator::validate_external_duration('321.500000'), 0.001);

        try {
            video_validator::validate_external_duration('not-a-number');
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('invalidvideoduration', $exception->errorcode);
        }
    }

}
