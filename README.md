# Moodle Simple Video Tracker (`mod_simplevideotracker`)

Simple Video Tracker is a Moodle 5.2 activity module for serving a single uploaded course video and tracking how much of it each learner has actually watched.

The plugin prevents normal forward seeking beyond verified progress, stores watched ranges without counting duplicate playback twice, and can use the watched percentage as an Activity Completion condition.

It also provides Web Service functions for creating activities and deploying videos from external systems.

## Compatibility

- Moodle: **5.2.x**
- Component: `mod_simplevideotracker`
- Version: **1.0.0**

## Features

- Supports MP4, WebM, OGV/Ogg, and M4V files.
- Stores the video using Moodle's File API.
- Resumes playback from the learner's last saved position.
- Prevents seeking ahead of verified viewing progress.
- Verifies playback progress on the server through heartbeat requests.
- Limits credited playback using server-side elapsed time.
- Merges watched ranges so replaying the same section does not increase progress twice.
- Supports completion thresholds from 10% to 100%.
- Includes a progress report for teachers and managers.
- Provides `mod/simplevideotracker:bypassseek` for users who should be allowed to seek freely.
- Implements Moodle's Privacy API.
- Supports Moodle backup and restore, including the uploaded video and optional user progress.
- Includes Web Service functions for automated activity creation and video deployment.

## Video duration

Each activity stores a `videoduration` value that is used as the authoritative duration for progress calculation.

The learner's browser also reports the media duration during heartbeat requests, but that value is only used to check that the loaded media matches the configured activity. Browser-reported duration is never used as the completion denominator.

The trusted duration can be configured by:

- editing the activity as a user with permission to manage it, or
- calling `mod_simplevideotracker_set_video`.

For upgraded installations, existing activities receive:

```text
videoduration = 0
```

Old per-user duration values are not migrated because they were originally supplied by learner browsers.

An activity with no configured duration will not allow playback, and its custom completion condition remains incomplete until a valid duration is set.

To update an existing activity, either edit it and enter the video's real duration in seconds or redeploy the video through the Web Service. Existing watched ranges are then recalculated against the configured duration.

## Installation

1. Copy or extract the plugin into:

   ```text
   moodle/mod/simplevideotracker
   ```

2. Sign in to Moodle as a site administrator.

3. Open:

   **Site administration → Notifications**

4. Complete the database upgrade.

5. Add a **Simple Video Tracker** activity to a course.

6. Upload a video and enter its duration in seconds.

The distribution ZIP contains `simplevideotracker/` as its top-level directory, so it can also be installed through:

**Site administration → Plugins → Install plugins**

## Recommended settings

A typical configuration is:

- **Prevent seeking beyond verified progress:** enabled
- **Seek tolerance:** 3 seconds
- **Heartbeat interval:** 5 seconds
- **Activity completion:** Require watched progress
- **Completion threshold:** 80%

The exact values can be adjusted depending on the course.

## Web Service deployment

The plugin includes two Web Service functions for automated deployment.

### `mod_simplevideotracker_create_activity`

Creates an empty Simple Video Tracker activity in a course section.

Required capabilities:

- `moodle/course:manageactivities`
- `mod/simplevideotracker:addinstance`

The activity introduction is passed through Moodle's `introeditor` structure so it is handled correctly by `add_moduleinfo()`.

### `mod_simplevideotracker_set_video`

Uploads a video from the authenticated caller's Moodle draft area into an existing Simple Video Tracker activity.

Required capability:

- `moodle/course:manageactivities`

Parameters:

- `cmid`  
  Course module ID of the Simple Video Tracker activity.

- `draftitemid`  
  Moodle draft item ID owned by the authenticated caller.

- `duration`  
  Video duration in seconds.

The duration must be greater than `0` and no greater than `172800` seconds. Numeric strings produced by common media tools are also accepted.

The draft area must contain exactly one non-empty video file with one of these extensions:

- `.mp4`
- `.webm`
- `.ogv`
- `.ogg`
- `.m4v`

The extension is used as the main allow-list because MIME detection can differ between browsers, operating systems, and upload clients.

The file is copied into the activity's single-video file area with a normalised filepath.

Replacing the video resets learner progress. Changing only the configured duration keeps the existing watched ranges and recalculates percentages against the new duration.

Validation errors are returned with plugin-specific messages where possible.

### Typical deployment flow

```text
mod_simplevideotracker_create_activity
        ↓
new CMID
        ↓
/webservice/upload.php
        ↓
draftitemid + video duration
        ↓
mod_simplevideotracker_set_video
```

## Progress tracking

Playback progress is tracked as watched time ranges rather than as a simple accumulated timer.

This means replaying an already watched section does not increase the learner's completion percentage.

The server also checks heartbeat timing and limits how much progress can be credited from each request. When seek prevention is enabled, learners cannot normally jump beyond their verified viewing frontier.

Users with the following capability are exempt from seek restrictions:

```text
mod/simplevideotracker:bypassseek
```

This is normally assigned to teachers and managers.

## Security notes

Simple Video Tracker is intended to enforce normal playback progress rules. It is not a DRM system and does not attempt to verify whether the learner is actively paying attention to the video.

The server controls the completion denominator, checks media duration consistency, limits progress using elapsed server time, and can prevent forward seeking beyond verified progress.

As with any browser-based video player, a sufficiently determined user may still reproduce valid playback requests or extract media that the browser is allowed to receive.

If stronger media protection is required, that should be handled separately with infrastructure such as:

- signed HLS or DASH delivery,
- short-lived media URLs or tokens,
- controlled media-processing pipelines,
- DRM-enabled streaming platforms,
- server-side video analytics.

## Backup and restore

Moodle backup and restore includes:

- activity settings,
- activity introduction and intro files,
- the uploaded video.

Per-user progress and watched ranges are included only when the Moodle backup is configured to include user information.

During restore, stored progress is mapped to the restored Moodle users.

## Privacy

The plugin implements Moodle's Privacy API.

Stored user data includes:

- playback progress,
- watched ranges,
- heartbeat timestamps,
- record creation and update timestamps.

The Privacy API supports exporting user data and deleting data by context or user.

## Tests

The plugin includes PHPUnit coverage for the main playback and deployment rules, including:

- trusted video duration validation,
- browser duration mismatch handling,
- normal playback credit,
- retry and partial-credit boundaries,
- forward-seek rejection,
- elapsed-time limits,
- watched-range de-duplication,
- completion thresholds,
- activity setting refresh,
- duration recalculation,
- progress reset when replacing media,
- browser MIME variations,
- Web Service draft validation,
- single-video enforcement,
- Web Service activity creation,
- privileged video deployment.

Run the PHPUnit tests inside a Moodle 5.2 development environment using Moodle's normal PHPUnit setup.

A small Node-based regression suite is also included for player event and queue behaviour:

```bash
node tests/js/player_mock_test.js
```

Run it from the plugin directory.

## Release history

### 1.0.0 — 2026-10-03

Initial public release as **Simple Video Tracker** (`mod_simplevideotracker`).

This release keeps the hardened playback and progress-tracking behaviour from the previous 2.3.3 package.

Web Service activity creation and video deployment are included.

No CDN or object-storage integration is included in this release.
