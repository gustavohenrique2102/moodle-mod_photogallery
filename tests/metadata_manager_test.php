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
 * Tests for image metadata management.
 *
 * @package   mod_photogallery
 * @category  test
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_photogallery;

use core\context\module as context_module;
use mod_photogallery\event\image_metadata_updated;
use mod_photogallery\local\metadata_manager;
use stored_file;

/**
 * Metadata persistence and identity tests.
 *
 * @covers \mod_photogallery\local\metadata_manager
 * @covers \mod_photogallery\event\image_metadata_updated
 */
final class metadata_manager_test extends \advanced_testcase {
    /**
     * Saving emits an auditable event and rejects a stale form revision.
     */
    public function test_save_is_audited_and_rejects_lost_update(): void {
        global $DB;

        [$gallery, $context, $images] = $this->create_gallery_with_image();
        metadata_manager::reconcile_files((int) $gallery->id, $context, true);

        $images = array_values(photogallery_get_display_images($context, (int) $gallery->id));
        $revision = metadata_manager::get_revision((int) $gallery->id, $context);
        $firstdata = $this->metadata_data($images, 'First caption', 'First alternative');

        $sink = $this->redirectEvents();
        $this->assertFalse(metadata_manager::save(
            (int) $gallery->id,
            $context,
            $images,
            $firstdata,
            $revision
        ));
        $events = array_values(array_filter(
            $sink->get_events(),
            static fn(\core\event\base $event): bool => $event instanceof image_metadata_updated
        ));
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertSame((int) $gallery->id, (int) $events[0]->objectid);
        $this->assertSame(count($images), $events[0]->other['imagecount']);
        $this->assertFalse($events[0]->other['orderchanged']);

        $saved = $DB->get_record(
            'photogallery_image',
            ['pathnamehash' => $images[0]->get_pathnamehash()],
            '*',
            MUST_EXIST
        );
        $this->assertSame('First caption', $saved->caption);
        $this->assertSame('First alternative', $saved->alttext);

        $stalewrite = $this->metadata_data($images, 'Overwritten caption', 'Overwritten alternative');
        try {
            metadata_manager::save(
                (int) $gallery->id,
                $context,
                $images,
                $stalewrite,
                $revision
            );
            $this->fail('A stale form revision should have been rejected.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('metadataconflict', $exception->errorcode);
        }

        $saved = $DB->get_record('photogallery_image', ['id' => $saved->id], '*', MUST_EXIST);
        $this->assertSame('First caption', $saved->caption);
    }

    /**
     * A rename preserves metadata while a replacement at the same path does not.
     */
    public function test_reconcile_distinguishes_rename_from_replacement(): void {
        global $DB;

        [$gallery, $context, $images] = $this->create_gallery_with_image();
        metadata_manager::reconcile_files((int) $gallery->id, $context, true);

        $images = array_values(photogallery_get_display_images($context, (int) $gallery->id));
        metadata_manager::save(
            (int) $gallery->id,
            $context,
            $images,
            $this->metadata_data($images, 'Persistent caption', 'Persistent alternative'),
            metadata_manager::get_revision((int) $gallery->id, $context)
        );

        $source = $images[0];
        $renamed = get_file_storage()->create_file_from_storedfile([
            'contextid' => $source->get_contextid(),
            'component' => $source->get_component(),
            'filearea' => $source->get_filearea(),
            'itemid' => $source->get_itemid(),
            'filepath' => $source->get_filepath(),
            'filename' => 'renamed.png',
        ], $source);
        $source->delete();

        $this->assertSame(0, metadata_manager::reconcile_files((int) $gallery->id, $context, true));
        $renamedmetadata = $DB->get_record(
            'photogallery_image',
            ['pathnamehash' => $renamed->get_pathnamehash()],
            '*',
            MUST_EXIST
        );
        $this->assertSame('Persistent caption', $renamedmetadata->caption);
        $this->assertSame($renamed->get_contenthash(), $renamedmetadata->contenthash);

        $filerecord = [
            'contextid' => $renamed->get_contextid(),
            'component' => $renamed->get_component(),
            'filearea' => $renamed->get_filearea(),
            'itemid' => $renamed->get_itemid(),
            'filepath' => $renamed->get_filepath(),
            'filename' => $renamed->get_filename(),
        ];
        $renamed->delete();
        $rawreplacement = get_file_storage()->create_file_from_string($filerecord, 'different raw contents');
        $rawcontenthash = $rawreplacement->get_contenthash();
        $rawreplacement->delete();
        $replacement = get_file_storage()->create_file_from_string($filerecord, 'sanitised replacement');

        $this->assertSame(0, metadata_manager::adopt_sanitized_content(
            (int) $gallery->id,
            $context,
            'images',
            [[
                'filepath' => $replacement->get_filepath(),
                'filename' => $replacement->get_filename(),
                'oldcontenthash' => $rawcontenthash,
                'newcontenthash' => $replacement->get_contenthash(),
            ]]
        ));

        $this->assertSame(1, metadata_manager::reconcile_files((int) $gallery->id, $context, true));
        $replacementmetadata = $DB->get_record(
            'photogallery_image',
            ['pathnamehash' => $replacement->get_pathnamehash()],
            '*',
            MUST_EXIST
        );
        $this->assertSame('', $replacementmetadata->caption);
        $this->assertSame('', $replacementmetadata->alttext);
        $this->assertSame($replacement->get_contenthash(), $replacementmetadata->contenthash);
    }

    /**
     * A trusted sanitiser mapping preserves metadata across re-encoding.
     */
    public function test_adopt_sanitized_content_preserves_metadata_safely(): void {
        global $DB;

        [$gallery, $context, $images] = $this->create_gallery_with_image();
        metadata_manager::reconcile_files((int) $gallery->id, $context, true);
        $images = array_values(photogallery_get_display_images($context, (int) $gallery->id));
        metadata_manager::save(
            (int) $gallery->id,
            $context,
            $images,
            $this->metadata_data($images, 'Preserved caption', 'Preserved alternative'),
            metadata_manager::get_revision((int) $gallery->id, $context)
        );

        $source = $images[0];
        $oldcontenthash = $source->get_contenthash();
        $filerecord = [
            'contextid' => $source->get_contextid(),
            'component' => $source->get_component(),
            'filearea' => $source->get_filearea(),
            'itemid' => $source->get_itemid(),
            'filepath' => $source->get_filepath(),
            'filename' => $source->get_filename(),
        ];
        $source->delete();
        $sanitised = get_file_storage()->create_file_from_string($filerecord, 're-encoded bytes');

        $this->assertSame(1, metadata_manager::adopt_sanitized_content(
            (int) $gallery->id,
            $context,
            'images',
            [[
                'filepath' => $sanitised->get_filepath(),
                'filename' => $sanitised->get_filename(),
                'oldcontenthash' => $oldcontenthash,
                'newcontenthash' => $sanitised->get_contenthash(),
            ]]
        ));

        metadata_manager::reconcile_files((int) $gallery->id, $context, true);
        $record = $DB->get_record(
            'photogallery_image',
            ['pathnamehash' => $sanitised->get_pathnamehash()],
            '*',
            MUST_EXIST
        );
        $this->assertSame('Preserved caption', $record->caption);
        $this->assertSame($sanitised->get_contenthash(), $record->contenthash);
    }

    /**
     * Server-side limits protect persistence even when the form is bypassed.
     */
    public function test_save_rejects_oversized_metadata(): void {
        [$gallery, $context, $images] = $this->create_gallery_with_image();
        metadata_manager::reconcile_files((int) $gallery->id, $context, true);

        $images = array_values(photogallery_get_display_images($context, (int) $gallery->id));
        $data = $this->metadata_data(
            $images,
            str_repeat('a', metadata_manager::CAPTION_MAX_LENGTH + 1),
            'Alternative'
        );

        try {
            metadata_manager::save(
                (int) $gallery->id,
                $context,
                $images,
                $data,
                metadata_manager::get_revision((int) $gallery->id, $context)
            );
            $this->fail('An oversized caption should have been rejected.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('metadatavaluetoolong', $exception->errorcode);
        }
    }

    /**
     * Ordering stays consistent between the metadata editor and file manager.
     */
    public function test_order_is_synchronised_in_both_directions(): void {
        [$gallery, $context, $images] = $this->create_gallery_with_image([
            'photo-a.png',
            'photo-b.png',
        ]);
        metadata_manager::reconcile_files((int) $gallery->id, $context, true);

        $images = array_values(photogallery_get_display_images($context, (int) $gallery->id));
        $originalfirsthash = $images[0]->get_pathnamehash();
        $originalsecondhash = $images[1]->get_pathnamehash();
        $data = $this->metadata_data($images, '', '');
        $data->moveto_0 = 1;
        $data->targetposition_0 = 2;

        $this->assertTrue(metadata_manager::save(
            (int) $gallery->id,
            $context,
            $images,
            $data,
            metadata_manager::get_revision((int) $gallery->id, $context)
        ));

        $fileorder = array_values(photogallery_get_images($context));
        $this->assertSame($originalsecondhash, $fileorder[0]->get_pathnamehash());
        $this->assertSame($originalfirsthash, $fileorder[1]->get_pathnamehash());

        // Simulate the activity file manager saving the original order.
        $filesbyhash = [];
        foreach ($fileorder as $file) {
            $filesbyhash[$file->get_pathnamehash()] = $file;
        }
        $filesbyhash[$originalfirsthash]->set_sortorder(0);
        $filesbyhash[$originalsecondhash]->set_sortorder(1);
        metadata_manager::sync_sortorder_from_files((int) $gallery->id, $context);

        $displayorder = array_values(photogallery_get_display_images($context, (int) $gallery->id));
        $this->assertSame($originalfirsthash, $displayorder[0]->get_pathnamehash());
        $this->assertSame($originalsecondhash, $displayorder[1]->get_pathnamehash());
    }

    /**
     * Creates a gallery with source photographs.
     *
     * @param string[] $filenames Source filenames.
     * @return array Gallery record, module context, and image list.
     */
    private function create_gallery_with_image(array $filenames = ['photo.png']): array {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $draftitemid = file_get_unused_draft_itemid();
        $usercontext = \core\context\user::instance($USER->id, MUST_EXIST);
        foreach ($filenames as $filename) {
            get_file_storage()->create_file_from_string([
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftitemid,
                'filepath' => '/',
                'filename' => $filename,
            ], $this->png());
        }

        $course = $this->getDataGenerator()->create_course();
        $gallery = $this->getDataGenerator()->create_module('photogallery', [
            'course' => $course->id,
            'name' => 'Metadata gallery',
            'images' => $draftitemid,
        ]);
        $context = context_module::instance($gallery->cmid, MUST_EXIST);
        $images = array_values(photogallery_get_display_images($context, (int) $gallery->id));
        $this->assertCount(count($filenames), $images);

        return [$gallery, $context, $images];
    }

    /**
     * Builds submitted metadata for all images.
     *
     * @param stored_file[] $images Images in display order.
     * @param string $caption Caption value.
     * @param string $alttext Alternative text value.
     * @return \stdClass
     */
    private function metadata_data(array $images, string $caption, string $alttext): \stdClass {
        $data = new \stdClass();
        foreach ($images as $index => $image) {
            $data->{'pathnamehash_' . $index} = $image->get_pathnamehash();
            $data->{'caption_' . $index} = $caption;
            $data->{'alttext_' . $index} = $alttext;
            $data->{'targetposition_' . $index} = $index + 1;
        }
        return $data;
    }

    /**
     * Returns a valid one-pixel PNG.
     *
     * @return string
     */
    private function png(): string {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQMAAAAl21bKAAAAA1BMVEUAAACnej3aAAAACXBIWXMAAA7EAAAOxAGVKw4b'
            . 'AAAACklEQVQImWNgAAAAAgAB9HFkpgAAAABJRU5ErkJggg==',
            true
        );
    }
}
