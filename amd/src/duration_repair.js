// This file is part of Moodle - https://moodle.org/ - GPL v3 or later.

/**
 * Recover an unset authoritative duration from the stored video metadata.
 *
 * @module mod_simplevideotracker/duration_repair
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
