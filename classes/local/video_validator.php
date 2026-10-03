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
 * Validation helpers for Simple Video Tracker media.
 *
 * @package   mod_simplevideotracker
 * @copyright 2026 onwards Simple Video Tracker contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_simplevideotracker\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Validates authoritative media duration and uploaded draft files.
 */
class video_validator {
    /** Maximum accepted video duration: 48 hours. */
    public const MAX_DURATION = 172800.0;

    /** @var array<string, array<int, string>> Allowed extensions and common MIME types. */
    private const ALLOWED_TYPES = [
        'mp4' => ['video/mp4', 'application/mp4'],
        'm4v' => ['video/mp4', 'video/x-m4v', 'application/mp4'],
        'webm' => ['video/webm'],
        'ogv' => ['video/ogg', 'application/ogg'],
        'ogg' => ['video/ogg', 'application/ogg'],
    ];

    /** @var string[] MIME types which are too generic to use as a rejection signal. */
    private const GENERIC_MIMETYPES = [
        '',
        'application/octet-stream',
        'binary/octet-stream',
    ];

    /**
     * Whether a duration is safe to use as the authoritative completion denominator.
     *
     * @param float $duration Duration in seconds.
     * @return bool
     */
    public static function is_valid_duration(float $duration): bool {
        return is_finite($duration) && $duration > 0.0 && $duration <= self::MAX_DURATION;
    }

    /**
     * Validate and normalize an authoritative duration.
     *
     * @param float $duration Duration in seconds.
     * @return float Rounded duration.
     */
    public static function validate_duration(float $duration): float {
        if (!self::is_valid_duration($duration)) {
            throw new \moodle_exception('invalidvideoduration', 'simplevideotracker');
        }
        return round($duration, 3);
    }

    /**
     * Validate a duration supplied by an external client.
     *
     * Web-service clients commonly send all scalar values as strings. Keeping the
     * external schema permissive here lets this method produce a useful plugin
     * error instead of Moodle's generic "Invalid parameter value detected".
     *
     * @param mixed $duration Raw external value.
     * @return float Rounded duration.
     */
    public static function validate_external_duration($duration): float {
        if ((!is_int($duration) && !is_float($duration) && !is_string($duration))
                || (is_string($duration) && trim($duration) === '')
                || !is_numeric($duration)) {
            throw new \moodle_exception('invalidvideoduration', 'simplevideotracker');
        }

        return self::validate_duration((float)$duration);
    }


    /**
     * Return the canonical browser MIME type for a supported filename.
     *
     * Uploaded/repository MIME metadata is not reliable enough to place directly
     * in a <source type> attribute. The extension is already hard allow-listed,
     * so derive the browser hint from that extension instead.
     *
     * @param string $filename Stored filename.
     * @return string Canonical MIME type, or an empty string for an unknown extension.
     */
    public static function get_browser_mimetype(string $filename): string {
        $extension = \core_text::strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return match ($extension) {
            'mp4', 'm4v' => 'video/mp4',
            'webm' => 'video/webm',
            'ogv', 'ogg' => 'video/ogg',
            default => '',
        };
    }

    /**
     * Validate that a draft area contains exactly one supported video.
     *
     * The draft belongs to the user making the privileged upload request. The
     * normal form filemanager already restricts extensions; this check is also
     * required for Web Service callers which can populate a draft area directly.
     * MIME detection varies across operating systems and upload clients, so the
     * extension is the hard allow-list while generic or video/* MIME values are
     * accepted for an allowed extension.
     *
     * @param int $draftitemid User draft item ID.
     * @param int $userid Owner of the draft area.
     * @return \stored_file The single validated draft file.
     */
    public static function validate_draft_item(int $draftitemid, int $userid): \stored_file {
        if ($draftitemid <= 0) {
            throw new \moodle_exception('invalidvideodraftid', 'simplevideotracker');
        }
        if ($userid <= 0) {
            throw new \moodle_exception('invalidvideofile', 'simplevideotracker');
        }

        $context = \context_user::instance($userid);
        $files = get_file_storage()->get_area_files(
            $context->id,
            'user',
            'draft',
            $draftitemid,
            'id ASC',
            false
        );

        if (!$files) {
            throw new \moodle_exception('videodraftempty', 'simplevideotracker');
        }
        if (count($files) !== 1) {
            throw new \moodle_exception('invalidvideofilecount', 'simplevideotracker');
        }

        /** @var \stored_file $file */
        $file = reset($files);
        if (!$file || $file->is_directory() || $file->get_filesize() <= 0) {
            throw new \moodle_exception('invalidvideofile', 'simplevideotracker');
        }

        $extension = \core_text::strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        if (!array_key_exists($extension, self::ALLOWED_TYPES)) {
            throw new \moodle_exception('invalidvideofiletype', 'simplevideotracker');
        }

        $mimetype = \core_text::strtolower(trim((string)$file->get_mimetype()));
        $isgeneric = in_array($mimetype, self::GENERIC_MIMETYPES, true);
        $isvideo = str_starts_with($mimetype, 'video/');
        $isknown = in_array($mimetype, self::ALLOWED_TYPES[$extension], true);

        if (!$isgeneric && !$isvideo && !$isknown) {
            throw new \moodle_exception('invalidvideomimetype', 'simplevideotracker', '', $mimetype ?: 'unknown');
        }

        return $file;
    }
}
