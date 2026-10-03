# Moodle Simple Video Tracker (`mod_simplevideotracker`)

Activity module for Moodle 5.2 that serves one uploaded course video, prevents ordinary forward seeking, tracks unique watched ranges, and integrates watched percentage with Moodle Activity Completion.

Simple Video Tracker keeps this scope intentionally small. External systems can create activities and upload/deploy videos through Moodle Web Services using `mod_simplevideotracker_create_activity` and `mod_simplevideotracker_set_video`.

## Compatibility

- Moodle: **5.2.x**
- Plugin component: `mod_simplevideotracker`
- Release: **1.0.0**

## Features

- One MP4, WebM, OGV/Ogg, or M4V video stored through Moodle File API.
- Resume from the learner's last stored position.
- Forward-seek prevention beyond the contiguous verified viewing frontier.
- Server-side heartbeat verification with elapsed-time advancement limits.
- Merged watched ranges so replaying the same portion does not inflate progress.
- Completion threshold from 10% to 100%.
- Teacher/manager progress report.
- `mod/simplevideotracker:bypassseek` capability for teachers/managers.
- Privacy API implementation for stored user progress and watched ranges.
- Moodle 2 backup/restore support, including video files and optional user progress.
- Web Service endpoints for automated activity creation and video deployment.

## Trusted video duration

Simple Video Tracker does not use a learner browser's reported media duration as the completion denominator. The activity stores an authoritative `videoduration` value which can only be set through an activity edit by an authorised user or through `mod_simplevideotracker_set_video`, which requires course activity-management capability.

The browser still sends its observed duration with each heartbeat, but that value is used only as a consistency check. A mismatched duration is rejected and cannot alter stored completion state.

When upgrading an existing installation, old activities intentionally receive `videoduration = 0`. Legacy per-user durations are not migrated because they originated from untrusted learner browsers. Until a privileged user configures the real duration, the activity is blocked from playback and its custom completion rule treats legacy percentages as incomplete. Edit each existing activity and enter its real duration, or redeploy its video through the Web Service; existing watched segments are then recalculated against the trusted duration.

## Install

1. Copy/extract the folder to `moodle/mod/simplevideotracker`.
2. Sign in as a site administrator.
3. Visit **Site administration → Notifications** and complete the database upgrade.
4. Add a **Simple Video Tracker** activity to a course.
5. Upload the video and enter its real duration in seconds.

The distributable ZIP contains `simplevideotracker/` as its top-level directory and can be installed with **Site administration → Plugins → Install plugins**.

## Recommended settings

- Enable **Prevent seeking beyond verified progress**.
- Seek tolerance: **3 seconds**.
- Heartbeat: **5 seconds**.
- Automatic completion: **Require watched progress**, normally **80%**.

## Web Service deployment

### `mod_simplevideotracker_create_activity`

Creates an empty Simple Video Tracker activity in a course section. It requires:

- `moodle/course:manageactivities`
- `mod/simplevideotracker:addinstance`

The activity introduction is populated through Moodle's `introeditor` data so it is preserved by `add_moduleinfo()`.

### `mod_simplevideotracker_set_video`

Stores one validated user-draft video in the activity. It requires `moodle/course:manageactivities` and accepts:

- `cmid`: Simple Video Tracker course-module ID.
- `draftitemid`: the authenticated caller's Moodle draft item ID.
- `duration`: authoritative video duration in seconds, greater than 0 and no more than 172800 seconds. Numeric strings returned by common media tools are accepted.

The endpoint explicitly verifies that the caller's draft contains exactly one non-empty MP4/WebM/OGV/Ogg/M4V file before it is copied into the module file area. The extension is the hard allow-list; generic or `video/*` MIME values are tolerated because MIME detection differs across upload clients. The file is copied directly from the caller's draft into the activity's single-file area, normalising its filepath. Replacing the media resets learner progress. Correcting only the duration recalculates existing percentages using the trusted duration. Upload-validation failures return plugin-specific messages instead of collapsing into Moodle's generic `Invalid parameter value detected` error where possible.

Typical automated deployment flow:

```text
mod_simplevideotracker_create_activity
        ↓
new CMID
        ↓
/webservice/upload.php
        ↓
draftitemid + authoritative duration
        ↓
mod_simplevideotracker_set_video
```

## Security model and limitations

Simple Video Tracker is playback-progress enforcement, not DRM or human-attention verification. The server prevents the learner from choosing the completion denominator, rejects media-duration mismatches, limits progress credit using server elapsed time, and can prevent seeking past the verified frontier. A sufficiently motivated user can still emulate legitimate real-time playback traffic. The authenticated browser must also receive the media itself, so preventing extraction requires a protected streaming/DRM architecture outside this plugin.

For higher-assurance deployments, consider signed HLS/DASH segments, short-lived media tokens, a controlled media-processing pipeline that supplies authoritative duration, and/or a dedicated video platform with DRM and server-side analytics.

## Backup, restore, and privacy

Course backup/restore includes the activity settings, intro files, and uploaded video. Per-user progress and watched segments are included only when the Moodle backup is configured to include user information. Restored progress is remapped to restored Moodle users.

The Privacy API declares and exports the stored progress fields, heartbeat timestamps, record timestamps, and watched-segment timestamps, and supports context/user deletion.

## Tests

The package includes PHPUnit tests for authoritative-duration validation, browser-duration mismatch checks, natural playback credit, partial-credit retry boundaries, forward-seek rejection, elapsed-time capping, unique-range de-duplication, completion thresholds, stale-setting refresh, instance recalculation/reset, canonical browser MIME hints, Web Service draft-file validation, the single-video invariant, and regression coverage for API-created introductions and privileged video deployment. Run them inside a Moodle 5.2 development environment using Moodle's standard PHPUnit setup. A lightweight Node mock regression suite for player event/queue behaviour is also included at `tests/js/player_mock_test.js`; run it from the plugin directory with `node tests/js/player_mock_test.js`.

## 1.0.0 - 2026-10-03

- Public distribution as **Simple Video Tracker** (`mod_simplevideotracker`).
- Same hardened feature set as the original 2.3.3 package; no CDN or object-storage feature is added.
- Existing Moodle Web Service activity creation and external video upload/deployment remain available.
