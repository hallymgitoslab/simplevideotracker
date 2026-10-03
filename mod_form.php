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
 * Activity settings form for Simple Video Tracker.
 *
 * @package   mod_simplevideotracker
 * @copyright 2026 onwards Simple Video Tracker contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once(__DIR__ . '/lib.php');

/**
 * Activity settings form.
 */
class mod_simplevideotracker_mod_form extends moodleform_mod {
    /**
     * Define the activity form.
     */
    public function definition(): void {
        global $PAGE;

        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('simplevideotrackername', 'simplevideotracker'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        $mform->addElement(
            'filemanager',
            'videofile',
            get_string('videofile', 'simplevideotracker'),
            null,
            simplevideotracker_file_options()
        );
        $mform->addHelpButton('videofile', 'videofile', 'simplevideotracker');

        $mform->addElement(
            'text',
            'videoduration',
            get_string('videoduration', 'simplevideotracker'),
            ['size' => 12]
        );
        $mform->setType('videoduration', PARAM_FLOAT);
        $mform->addRule('videoduration', null, 'numeric', null, 'client');
        $mform->addHelpButton('videoduration', 'videoduration', 'simplevideotracker');
        $mform->addElement(
            'html',
            '<div id="simplevideotracker-duration-status" class="form-text text-muted mb-3">'
                . s(get_string('videodurationautohint', 'simplevideotracker')) . '</div>'
        );

        $PAGE->requires->js_call_amd('mod_simplevideotracker/duration_autofill', 'init', [[
            'durationInputId' => 'id_videoduration',
            'statusId' => 'simplevideotracker-duration-status',
            'detectingText' => get_string('videodurationdetecting', 'simplevideotracker'),
            'detectedText' => get_string('videodurationdetected', 'simplevideotracker'),
            'failedText' => get_string('videodurationdetectfailed', 'simplevideotracker'),
        ]]);

        $mform->addElement('header', 'playbacksettings', get_string('playbacksettings', 'simplevideotracker'));
        $mform->addElement(
            'advcheckbox',
            'preventseeking',
            get_string('preventseeking', 'simplevideotracker')
        );
        $mform->setDefault('preventseeking', 1);
        $mform->addHelpButton('preventseeking', 'preventseeking', 'simplevideotracker');

        $toleranceoptions = [];
        for ($i = 0; $i <= 10; $i++) {
            $toleranceoptions[$i] = get_string('seconds', 'simplevideotracker', $i);
        }
        $mform->addElement(
            'select',
            'seektolerance',
            get_string('seektolerance', 'simplevideotracker'),
            $toleranceoptions
        );
        $mform->setDefault('seektolerance', 3);
        $mform->addHelpButton('seektolerance', 'seektolerance', 'simplevideotracker');

        $heartbeatoptions = [
            3 => get_string('seconds', 'simplevideotracker', 3),
            5 => get_string('seconds', 'simplevideotracker', 5),
            10 => get_string('seconds', 'simplevideotracker', 10),
            15 => get_string('seconds', 'simplevideotracker', 15),
            30 => get_string('seconds', 'simplevideotracker', 30),
        ];
        $mform->addElement(
            'select',
            'heartbeat',
            get_string('heartbeat', 'simplevideotracker'),
            $heartbeatoptions
        );
        $mform->setDefault('heartbeat', 5);
        $mform->addHelpButton('heartbeat', 'heartbeat', 'simplevideotracker');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Prepare the file manager draft area when editing an existing instance.
     *
     * @param array $defaultvalues Default form values.
     */
    public function data_preprocessing(&$defaultvalues): void {
        if ($this->current && !empty($this->current->instance) && $this->context) {
            $draftitemid = file_get_submitted_draft_itemid('videofile');
            file_prepare_draft_area(
                $draftitemid,
                $this->context->id,
                'mod_simplevideotracker',
                'video',
                0,
                simplevideotracker_file_options()
            );
            $defaultvalues['videofile'] = $draftitemid;
        }
    }

    /**
     * Add the custom watched-percentage completion rule.
     *
     * @return array
     */
    public function add_completion_rules(): array {
        $mform = $this->_form;
        $name = $this->get_suffixed_name('completionpercent');
        $options = [];
        for ($percent = 10; $percent <= 100; $percent += 5) {
            $options[$percent] = $percent . '%';
        }
        $mform->addElement('select', $name, get_string('completionpercent', 'simplevideotracker'), $options);
        $mform->setDefault($name, 80);
        return [$name];
    }

    /**
     * Whether this module has an enabled custom completion rule.
     *
     * @param array $data Submitted data.
     * @return bool
     */
    public function completion_rule_enabled($data): bool {
        return !empty($data[$this->get_suffixed_name('completionpercent')]);
    }

    /**
     * Validate video file presence, media duration, and settings.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array
     */
    public function validation($data, $files): array {
        global $USER;

        $errors = parent::validation($data, $files);

        if (!empty($data['videofile'])) {
            try {
                \mod_simplevideotracker\local\video_validator::validate_draft_item((int)$data['videofile'], (int)$USER->id);
            } catch (\moodle_exception $exception) {
                $errors['videofile'] = $exception->getMessage();
            }
        } else if (empty($this->_instance)) {
            $errors['videofile'] = get_string('required');
        }

        $durationraw = trim((string)($data['videoduration'] ?? ''));
        if ($durationraw !== '') {
            $duration = (float)$durationraw;
            if (!\mod_simplevideotracker\local\video_validator::is_valid_duration($duration)) {
                $errors['videoduration'] = get_string('invalidvideoduration', 'simplevideotracker');
            }
        }

        return $errors;
    }

    /**
     * Return a completion form field name with Moodle's current suffix.
     *
     * @param string $fieldname Base field name.
     * @return string
     */
    protected function get_suffixed_name(string $fieldname): string {
        return $fieldname . $this->get_suffix();
    }
}
