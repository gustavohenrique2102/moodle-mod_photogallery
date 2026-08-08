<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Tests for the activity settings form.
 *
 * @package   mod_photogallery
 * @category  test
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_photogallery;

/**
 * Server-side form validation tests.
 *
 * @covers \mod_photogallery_mod_form
 */
final class mod_form_test extends \advanced_testcase {
    /**
     * Long names are rejected while automatic view completion is accepted.
     */
    public function test_validation_rejects_long_name_and_accepts_view_completion(): void {
        [$form] = $this->create_form();
        $data = $this->base_data();
        $data['name'] = str_repeat('a', 256);
        $data['completion'] = COMPLETION_TRACKING_AUTOMATIC;
        $data['completionview'] = 1;

        $errors = $form->validation($data, []);

        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayNotHasKey('completion', $errors);
        $this->assertStringContainsString('255', $errors['name']);
    }

    /**
     * Invalid direct images and ZIP archives are attached to their fields.
     */
    public function test_validation_maps_invalid_media_to_form_fields(): void {
        [$form] = $this->create_form();
        $data = $this->base_data();
        $data['images'] = $this->create_draft_files([
            'not-an-image.jpg' => 'plain text',
        ]);
        $data['importzip'] = $this->create_draft_files([
            'not-a-zip.zip' => 'plain text',
        ]);

        $errors = $form->validation($data, []);

        $this->assertArrayHasKey('images', $errors);
        $this->assertArrayHasKey('importzip', $errors);
        $this->assertStringContainsString('not-an-image.jpg', $errors['images']);
    }

    /**
     * Featured and regular images share the gallery-wide count limit.
     */
    public function test_validation_enforces_combined_image_and_cover_limit(): void {
        [$form] = $this->create_form();
        $regularfiles = [];
        for ($index = 1; $index <= 100; $index++) {
            $regularfiles['photo-' . $index . '.jpg'] = 'invalid';
        }

        $data = $this->base_data();
        $data['images'] = $this->create_draft_files($regularfiles);
        $data['coverimage'] = $this->create_draft_files([
            'cover.jpg' => 'invalid',
        ]);

        $errors = $form->validation($data, []);

        $this->assertArrayHasKey('images', $errors);
        $this->assertSame(
            get_string('toomanyimages', 'mod_photogallery', 100),
            $errors['images']
        );
    }

    /**
     * Creates an existing activity settings form.
     *
     * @return array Form and course records.
     */
    private function create_form(): array {
        global $CFG, $DB, $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $gallery = $this->getDataGenerator()->create_module('photogallery', [
            'course' => $course->id,
            'name' => 'Form gallery',
        ]);
        $cm = get_coursemodule_from_instance('photogallery', $gallery->id, $course->id, false, MUST_EXIST);
        $current = $DB->get_record('photogallery', ['id' => $gallery->id], '*', MUST_EXIST);
        $current->availabilityconditionsjson = null;

        $PAGE->set_course($course);
        require_once($CFG->dirroot . '/mod/photogallery/mod_form.php');
        $form = new \mod_photogallery_mod_form($current, 0, $cm, $course);

        return [$form, $course];
    }

    /**
     * Returns minimum valid submission data.
     *
     * @return array
     */
    private function base_data(): array {
        return [
            'name' => 'Valid gallery',
            'images' => 0,
            'coverimage' => 0,
            'importzip' => 0,
            'completion' => COMPLETION_TRACKING_NONE,
            'completionview' => 0,
            'instance' => 1,
            'modulename' => 'photogallery',
            'coursemodule' => 1,
            'cmidnumber' => '',
            'availabilityconditionsjson' => '{"op":"&","c":[],"showc":[]}',
        ];
    }

    /**
     * Creates files in a user draft area.
     *
     * @param array $files Filename-to-content map.
     * @return int Draft item ID.
     */
    private function create_draft_files(array $files): int {
        global $USER;

        $draftitemid = file_get_unused_draft_itemid();
        $usercontext = \core\context\user::instance($USER->id, MUST_EXIST);
        $filestorage = get_file_storage();

        foreach ($files as $filename => $content) {
            $filestorage->create_file_from_string([
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftitemid,
                'filepath' => '/',
                'filename' => $filename,
            ], $content);
        }

        return $draftitemid;
    }
}
