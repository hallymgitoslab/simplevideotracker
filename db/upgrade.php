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
 * Upgrade steps for Simple Video Tracker.
 *
 * @package   mod_simplevideotracker
 * @copyright 2026 onwards Simple Video Tracker contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade mod_simplevideotracker.
 *
 * @param int $oldversion Installed plugin version.
 * @return bool
 */
function xmldb_simplevideotracker_upgrade(int $oldversion): bool {
    global $DB;

    if ($oldversion < 2026091301) {
        // Code-only fix: correct pluginfile itemid/path handling for video delivery.
        upgrade_mod_savepoint(true, 2026091301, 'simplevideotracker');
    }

    if ($oldversion < 2026092200) {
        // Code-only upgrade: register set_video external API.
        upgrade_mod_savepoint(true, 2026092200, 'simplevideotracker');
    }

    if ($oldversion < 2026092201) {
        // Code-only upgrade: register create_activity external API.
        upgrade_mod_savepoint(true, 2026092201, 'simplevideotracker');
    }

    if ($oldversion < 2026092700) {
        $dbman = $DB->get_manager();
        $table = new xmldb_table('simplevideotracker');
        $field = new xmldb_field(
            'videoduration',
            XMLDB_TYPE_NUMBER,
            '12, 3',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'introformat'
        );

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Existing activities intentionally remain at duration 0. Their legacy
        // per-user duration values came from learner browsers and are therefore
        // not safe to promote into the new authoritative activity setting.
        upgrade_mod_savepoint(true, 2026092700, 'simplevideotracker');
    }


    if ($oldversion < 2026092701) {
        // Code-only upgrade: make Web Service video upload validation tolerant of
        // real-world MIME/path variations and return actionable upload errors.
        upgrade_mod_savepoint(true, 2026092701, 'simplevideotracker');
    }

    if ($oldversion < 2026092703) {
        // Code-only upgrade: decouple media storage from duration discovery.
        // Duration recovery now uses a normal sesskey-protected endpoint rather
        // than a form-time external/AJAX function.
        upgrade_mod_savepoint(true, 2026092703, 'simplevideotracker');
    }

    if ($oldversion < 2026092704) {
        // Code-only hardening build for the 2.3.3 release: serialise progress
        // heartbeats, expand regression tests, and paginate large reports.
        upgrade_mod_savepoint(true, 2026092704, 'simplevideotracker');
    }

    return true;
}
