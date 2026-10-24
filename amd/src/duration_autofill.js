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
 * Best-effort local video duration detection for the activity form.
 *
 * This module deliberately does not call a Moodle external/AJAX function. The
 * file picker uploads independently; duration discovery must never be able to
 * block or break the actual media upload.
 *
 * @module     mod_simplevideotracker/duration_autofill
 * @copyright  2026 onwards Simple Video Tracker contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const MAX_DURATION = 172800;
const VIDEO_EXTENSIONS = ['mp4', 'm4v', 'webm', 'ogv', 'ogg'];
const validDuration = (value) => Number.isFinite(value) && value > 0 && value <= MAX_DURATION;

const looksLikeSupportedVideo = (file) => {
    if (!file) {
        return false;
    }
    const type = String(file.type || '').toLowerCase();
    if (type.startsWith('video/')) {
        return true;
    }
    const name = String(file.name || '').toLowerCase();
    const dot = name.lastIndexOf('.');
    return dot !== -1 && VIDEO_EXTENSIONS.includes(name.slice(dot + 1));
};

const readDuration = (file) => new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file);
    const video = document.createElement('video');
    let finished = false;

    const finish = (callback, value) => {
        if (finished) {
            return;
        }
        finished = true;
        window.clearTimeout(timeout);
        video.removeAttribute('src');
        video.load();
        URL.revokeObjectURL(url);
        callback(value);
    };

    const timeout = window.setTimeout(() => finish(reject, new Error('metadata-timeout')), 15000);
    video.preload = 'metadata';
    video.muted = true;
    video.addEventListener('loadedmetadata', () => {
        const duration = Number(video.duration);
        if (validDuration(duration)) {
            finish(resolve, duration);
        } else {
            finish(reject, new Error('invalid-duration'));
        }
    }, {once: true});
    video.addEventListener('error', () => finish(reject, new Error('metadata-error')), {once: true});
    video.src = url;
});

export const init = (config) => {
    const durationInput = document.getElementById(config.durationInputId || 'id_videoduration');
    const status = document.getElementById(config.statusId || 'simplevideotracker-duration-status');
    if (!durationInput) {
        return;
    }

    const setStatus = (message, isError = false) => {
        if (!status) {
            return;
        }
        status.textContent = message || '';
        status.classList.toggle('text-danger', Boolean(isError));
        status.classList.toggle('text-muted', !isError);
    };

    document.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLInputElement) || target.type !== 'file' || !target.files?.length) {
            return;
        }
        const file = Array.from(target.files).find(looksLikeSupportedVideo);
        if (!file) {
            return;
        }

        setStatus(config.detectingText);
        readDuration(file).then((seconds) => {
            const rounded = Math.round(seconds * 1000) / 1000;
            durationInput.value = String(rounded);
            durationInput.dispatchEvent(new Event('change', {bubbles: true}));
            setStatus(config.detectedText.replace('{$a}', String(rounded)));
        }).catch(() => {
            // Duration failure is informational only. The video upload itself is
            // allowed to complete and the activity can repair duration on first
            // teacher/manager view.
            setStatus(config.failedText, true);
        });
    }, true);
};
