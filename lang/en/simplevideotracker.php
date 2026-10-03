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
 * English strings for Simple Video Tracker.
 *
 * @package   mod_simplevideotracker
 * @copyright 2026 onwards Simple Video Tracker contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Simple Video Tracker';
$string['modulename'] = 'Simple Video Tracker';
$string['modulenameplural'] = 'Simple Video Trackers';
$string['modulename_summary'] = 'Upload a video, prevent learners from seeking beyond verified progress, and track watched progress.';
$string['modulename_help'] = '<p><strong>Key features:</strong> verified progress tracking, resume playback, forward-seek prevention, completion by watched percentage, and teacher reports.</p><p><strong>Ways to use it:</strong> course videos where learners must watch sequentially before later portions become seekable.</p>';
$string['modulename_tip'] = 'Use MP4 (H.264/AAC) for the broadest browser compatibility.';
$string['pluginadministration'] = 'Simple Video Tracker administration';
$string['simplevideotrackername'] = 'Activity name';
$string['videofile'] = 'Video file';
$string['videofile_help'] = 'Upload exactly one MP4, WebM, OGV/Ogg, or M4V video. MP4 is recommended.';
$string['videoduration'] = 'Video duration (seconds)';
$string['videoduration_help'] = 'The duration is detected automatically from the selected video. You may enter the real duration manually only if browser metadata detection is unavailable. This trusted activity setting is used as the completion denominator.';
$string['invalidvideoduration'] = 'If a duration is entered, it must be greater than 0 and no more than 172800 seconds (48 hours). You may leave it blank; the video can still be saved.';
$string['videodurationautohint'] = 'Video duration is detected automatically when possible. This field may be left blank; the video upload and activity save are not blocked.';
$string['videodurationdetecting'] = 'Detecting video duration…';
$string['videodurationdetected'] = 'Detected video duration: {$a} seconds.';
$string['videodurationdetectfailed'] = 'Automatic duration detection was not available before save. The video can still be saved; a teacher or manager opening the activity will retry detection from the stored video.';
$string['durationnotconfigured'] = 'The authoritative video duration has not been configured for this activity. A teacher or manager must edit the activity or upload the video again through the Web Service.';
$string['invalidvideofile'] = 'The uploaded draft does not contain a usable non-empty video file.';
$string['invalidvideodraftid'] = 'The draft item ID is missing or invalid. Use the itemid returned by /webservice/upload.php.';
$string['videodraftempty'] = 'No file was found in that draft item. Use the itemid returned by /webservice/upload.php for the same authenticated user.';
$string['invalidvideofilecount'] = 'The upload must contain exactly one video file.';
$string['invalidvideofiletype'] = 'The uploaded filename has an unsupported extension. Use MP4, WebM, OGV/Ogg, or M4V.';
$string['invalidvideomimetype'] = 'The uploaded file MIME type ({$a}) does not look like video data for the allowed filename extension.';
$string['videostorefailed'] = 'The draft video was validated but could not be stored in the Simple Video Tracker activity.';
$string['playbacksettings'] = 'Playback protection';
$string['preventseeking'] = 'Prevent seeking beyond verified progress';
$string['preventseeking_help'] = 'When enabled, learners can seek backward or within already verified progress, but cannot seek forward beyond it. The server also rejects implausible progress jumps.';
$string['seektolerance'] = 'Seek tolerance (seconds)';
$string['seektolerance_help'] = 'Small tolerance for browser timing differences. This does not grant completion progress by itself.';
$string['heartbeat'] = 'Progress save interval (seconds)';
$string['heartbeat_help'] = 'How frequently the player sends a verified playback heartbeat to Moodle.';
$string['completionpercent'] = 'Require watched progress';
$string['completionpercentdesc'] = 'Watch at least {$a}% of the video';
$string['progress'] = 'Progress';
$string['watched'] = 'Unique watched';
$string['alloweduntil'] = 'Verified frontier';
$string['lastposition'] = 'Last position';
$string['completed'] = 'Completed';
$string['lastupdated'] = 'Last updated';
$string['report'] = 'Viewing report';
$string['viewreport'] = 'View progress report';
$string['noprogress'] = 'No progress has been recorded yet.';
$string['novideo'] = 'No video file is attached to this activity. If this activity was created by Web Service, the set_video step did not complete successfully.';
$string['resume'] = 'Resuming from your last verified position.';
$string['seekblocked'] = 'You cannot skip beyond your verified viewing progress.';
$string['saving'] = 'Saving progress…';
$string['saved'] = 'Progress saved';
$string['savefailed'] = 'Progress could not be saved. Playback protection remains active.';
$string['savepartial'] = 'Progress partially saved; the remaining viewing range will retry automatically.';
$string['savepending'] = 'Progress save pending; it will retry automatically.';
$string['privacy:metadata:simplevideotracker_progress'] = 'Stores each user’s progress for a Simple Video Tracker activity.';
$string['privacy:metadata:simplevideotracker_progress:userid'] = 'The user whose progress is stored.';
$string['privacy:metadata:simplevideotracker_progress:simplevideotrackerid'] = 'The Simple Video Tracker activity instance.';
$string['privacy:metadata:simplevideotracker_progress:duration'] = 'A snapshot of the authoritative video duration used for this progress row.';
$string['privacy:metadata:simplevideotracker_progress:alloweduntil'] = 'The furthest position verified as sequentially reachable.';
$string['privacy:metadata:simplevideotracker_progress:lastposition'] = 'The user’s most recent playback position.';
$string['privacy:metadata:simplevideotracker_progress:watchedseconds'] = 'Unique watched seconds.';
$string['privacy:metadata:simplevideotracker_progress:progress'] = 'Calculated watched percentage.';
$string['privacy:metadata:simplevideotracker_progress:completed'] = 'Whether the configured completion threshold has been met.';
$string['privacy:metadata:simplevideotracker_progress:lastping'] = 'The time of the most recent playback heartbeat.';
$string['privacy:metadata:simplevideotracker_progress:timecreated'] = 'The time the progress record was created.';
$string['privacy:metadata:simplevideotracker_progress:timemodified'] = 'The time the progress record was last modified.';
$string['privacy:metadata:simplevideotracker_segments'] = 'Stores merged ranges of video time verified as watched.';
$string['privacy:metadata:simplevideotracker_segments:progressid'] = 'The progress record to which this watched range belongs.';
$string['privacy:metadata:simplevideotracker_segments:starttime'] = 'Start of a watched range.';
$string['privacy:metadata:simplevideotracker_segments:endtime'] = 'End of a watched range.';
$string['privacy:metadata:simplevideotracker_segments:timecreated'] = 'The time the watched range was created.';
$string['privacy:metadata:simplevideotracker_segments:timemodified'] = 'The time the watched range was last modified.';
$string['simplevideotracker:addinstance'] = 'Add a new Simple Video Tracker activity';
$string['simplevideotracker:view'] = 'View Simple Video Tracker activity';
$string['simplevideotracker:viewreport'] = 'View Simple Video Tracker progress report';
$string['simplevideotracker:bypassseek'] = 'Bypass forward-seek restriction';
$string['resetonreplace'] = 'Learner progress was reset because the video file was replaced.';
$string['user'] = 'User';
$string['email'] = 'Email';
$string['yes'] = 'Yes';
$string['no'] = 'No';
$string['seconds'] = '{$a} sec';
$string['invalidheartbeat'] = 'Invalid progress heartbeat.';

$string['videodurationrepairing'] = 'The video is stored. Its duration is not configured yet, so Simple Video Tracker is reading the stored video metadata now.';
$string['videodurationrepairsaving'] = 'Detecting and saving video duration…';
$string['videodurationrepairsaved'] = 'Video duration saved. Reloading…';
$string['videodurationrepairfailed'] = 'The video is stored, but its duration could not be configured automatically. Edit the activity and enter the duration manually.';
