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
 * Recover an unset authoritative duration from the stored video metadata.
 *
 * @module     mod_simplevideotracker/duration_repair
 * @copyright  2026 onwards Simple Video Tracker contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const MAX_DURATION = 172800;

export const init = (config) => {
    const video = document.getElementById(config.playerId);
    const status = document.getElementById(config.statusId);
    if (!video) {
        return;
    }

    let saving = false;
    const setStatus = (message, isError = false) => {
        if (!status) {
            return;
        }
        status.textContent = message || '';
        status.classList.toggle('text-danger', Boolean(isError));
    };

    const save = () => {
        if (saving) {
            return;
        }
        const duration = Number(video.duration);
        if (!Number.isFinite(duration) || duration <= 0 || duration > MAX_DURATION) {
            setStatus(config.failedText, true);
            return;
        }
        saving = true;
        setStatus(config.savingText);
        const body = new URLSearchParams({
            sesskey: config.sesskey,
            cmid: String(config.cmid),
            duration: String(Math.round(duration * 1000) / 1000),
        });

        fetch(config.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
            body: body.toString(),
        }).then((response) => response.json().then((data) => ({ok: response.ok, data})))
            .then(({ok, data}) => {
                if (!ok || !data?.success) {
                    throw new Error(data?.message || 'duration-save-failed');
                }
                setStatus(config.savedText);
                window.location.reload();
            })
            .catch(() => {
                saving = false;
                setStatus(config.failedText, true);
            });
    };

    if (video.readyState >= 1) {
        save();
    } else {
        video.addEventListener('loadedmetadata', save, {once: true});
    }
};
