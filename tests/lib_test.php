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

namespace mod_photogallery;

/**
 * Tests for the Photo gallery activity library functions.
 *
 * @package   mod_photogallery
 * @category  test
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\Group('mod_photogallery')]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_photogallery\task\generate_previews::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('photogallery_add_instance')]
#[\PHPUnit\Framework\Attributes\CoversFunction('photogallery_update_instance')]
#[\PHPUnit\Framework\Attributes\CoversFunction('photogallery_delete_instance')]
#[\PHPUnit\Framework\Attributes\CoversFunction('photogallery_supports')]
#[\PHPUnit\Framework\Attributes\CoversFunction('photogallery_get_filemanager_options')]
#[\PHPUnit\Framework\Attributes\CoversFunction('photogallery_get_cover_filemanager_options')]
#[\PHPUnit\Framework\Attributes\CoversFunction('photogallery_get_zip_filepicker_options')]
#[\PHPUnit\Framework\Attributes\CoversFunction('photogallery_prepare_image_drafts')]
#[\PHPUnit\Framework\Attributes\CoversFunction('photogallery_get_file_areas')]
#[\PHPUnit\Framework\Attributes\CoversFunction('photogallery_get_file_info')]
#[\PHPUnit\Framework\Attributes\CoversFunction('photogallery_import_zip')]
#[\PHPUnit\Framework\Attributes\CoversFunction('photogallery_cleanup_image_metadata')]
#[\PHPUnit\Framework\Attributes\CoversFunction('photogallery_cm_info_view')]
#[\PHPUnit\Framework\Attributes\CoversFunction('photogallery_queue_missing_previews')]
#[\PHPUnit\Framework\Attributes\CoversFunction('photogallery_view')]
final class lib_test extends \advanced_testcase {
    /** A valid 1x1 PNG image. */
    private const PNG_ONE =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQMAAAAl21bKAAAAA1BMVEUAAACnej3aAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAACklEQVQImWNgAAAAAgAB9HFkpgAAAABJRU5ErkJggg==';

    /** A second valid 1x1 PNG image with different content. */
    private const PNG_TWO =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQMAAAAl21bKAAAAA1BMVEX///+nxBvIAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAACklEQVQImWNgAAAAAgAB9HFkpgAAAABJRU5ErkJggg==';

    /** A third valid 1x1 PNG image with different content. */
    private const PNG_THREE =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQMAAAAl21bKAAAAA1BMVEX/AAAZ4gk3AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAACklEQVQImWNgAAAAAgAB9HFkpgAAAABJRU5ErkJggg==';

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        global $CFG;
        require_once($CFG->dirroot . '/mod/photogallery/lib.php');
    }

    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Tests creation of an instance with regular images and a cover.
     */
    public function test_create_instance_stores_settings_and_files(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();

        $imagesdraftid = $this->create_draft_area([
            'photo-a.png' => $this->png_one(),
            'photo-b.png' => $this->png_two(),
        ]);

        $coverdraftid = $this->create_draft_area([
            'cover.png' => $this->png_three(),
        ]);

        $gallery = $this->getDataGenerator()->create_module(
            'photogallery',
            [
                'course' => $course->id,
                'name' => 'Test gallery',
                'intro' => 'Gallery introduction',
                'introformat' => FORMAT_HTML,
                'previewcount' => 8,
                'images' => $imagesdraftid,
                'coverimage' => $coverdraftid,
            ]
        );

        $record = $DB->get_record(
            'photogallery',
            ['id' => $gallery->id],
            '*',
            MUST_EXIST
        );

        $this->assertSame('Test gallery', $record->name);
        $this->assertSame(8, (int) $record->previewcount);

        $context = \core\context\module::instance($gallery->cmid);
        $images = photogallery_get_images($context);
        $image = reset($images);
        $cover = photogallery_get_cover_image($context);

        $this->assertCount(2, $images);
        $this->assertInstanceOf(\stored_file::class, $cover);
        $this->assertSame('cover.png', $cover->get_filename());

        $displaynames = array_map(
            static fn(\stored_file $file): string => $file->get_filename(),
            photogallery_get_display_images($context, (int) $gallery->id)
        );

        $this->assertSame('cover.png', $displaynames[0]);
        $this->assertContains('photo-a.png', $displaynames);
        $this->assertContains('photo-b.png', $displaynames);
    }

    /**
     * Tests settings update, file synchronization and metadata cleanup.
     */
    public function test_update_instance_synchronises_images_and_metadata(): void {
        global $DB, $USER;

        $course = $this->getDataGenerator()->create_course();
        $imagesdraftid = $this->create_draft_area([
            'photo-a.png' => $this->png_one(),
            'photo-b.png' => $this->png_two(),
        ]);

        $gallery = $this->getDataGenerator()->create_module(
            'photogallery',
            [
                'course' => $course->id,
                'name' => 'Original gallery',
                'previewcount' => 6,
                'images' => $imagesdraftid,
            ]
        );

        $context = \core\context\module::instance($gallery->cmid);
        $filesbyname = $this->index_files_by_name(
            photogallery_get_images($context)
        );

        $this->create_metadata(
            (int) $gallery->id,
            $filesbyname['photo-a.png'],
            'Caption A',
            'Alternative A',
            1
        );

        $preparedraftid = 0;

        file_prepare_draft_area(
            $preparedraftid,
            $context->id,
            'mod_photogallery',
            'images',
            0,
            photogallery_get_filemanager_options($course)
        );

        $usercontext = \core\context\user::instance($USER->id);
        $filestorage = get_file_storage();
        $draftfiles = $filestorage->get_area_files(
            $usercontext->id,
            'user',
            'draft',
            $preparedraftid,
            'id ASC',
            false
        );

        $draftfilesbyname = $this->index_files_by_name(
            $draftfiles
        );

        /*
        * Confirms that the current gallery files were correctly
        * copied into the draft area before simulating an edit.
        */
        $this->assertArrayHasKey(
            'photo-a.png',
            $draftfilesbyname
        );

        $this->assertArrayHasKey(
            'photo-b.png',
            $draftfilesbyname
        );

        /*
        * Simulates the user removing only photo-a.png.
        */
        $draftfilesbyname['photo-a.png']->delete();

        $filestorage->create_file_from_string(
            [
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $preparedraftid,
                'filepath' => '/',
                'filename' => 'photo-c.png',
                'userid' => $USER->id,
            ],
            $this->png_one()
        );

        $updated = (object) [
            'instance' => $gallery->id,
            'coursemodule' => $gallery->cmid,
            'course' => $course->id,
            'name' => 'Updated gallery',
            'intro' => 'Updated introduction',
            'introformat' => FORMAT_HTML,
            'previewcount' => 9,
            'images' => $preparedraftid,
            'coverimage' => 0,
            'importzip' => 0,
        ];

        $this->assertTrue(photogallery_update_instance($updated));

        $record = $DB->get_record(
            'photogallery',
            ['id' => $gallery->id],
            '*',
            MUST_EXIST
        );

        $this->assertSame('Updated gallery', $record->name);
        $this->assertSame(9, (int) $record->previewcount);

        $remainingfiles = $this->index_files_by_name(
            photogallery_get_images($context)
        );

        $this->assertArrayNotHasKey('photo-a.png', $remainingfiles);
        $this->assertArrayHasKey('photo-b.png', $remainingfiles);
        $this->assertArrayHasKey('photo-c.png', $remainingfiles);

        $this->assertFalse(
            $DB->record_exists(
                'photogallery_image',
                [
                    'photogalleryid' => $gallery->id,
                    'pathnamehash' => $filesbyname['photo-a.png']->get_pathnamehash(),
                ]
            )
        );
    }

    /**
     * Tests that an invalid ZIP cannot partially update a gallery.
     */
    public function test_update_validates_zip_before_persistent_changes(): void {
        global $DB, $USER;

        $course = $this->getDataGenerator()->create_course();
        $imagesdraftid = $this->create_draft_area([
            'photo-a.png' => $this->png_one(),
            'photo-b.png' => $this->png_two(),
        ]);
        $gallery = $this->getDataGenerator()->create_module(
            'photogallery',
            [
                'course' => $course->id,
                'name' => 'Original gallery',
                'images' => $imagesdraftid,
            ]
        );
        $context = \core\context\module::instance($gallery->cmid);

        $preparedraftid = 0;
        file_prepare_draft_area(
            $preparedraftid,
            $context->id,
            'mod_photogallery',
            'images',
            0,
            photogallery_get_filemanager_options($course)
        );
        $draftfiles = get_file_storage()->get_area_files(
            \core\context\user::instance($USER->id)->id,
            'user',
            'draft',
            $preparedraftid,
            'id ASC',
            false
        );
        $draftfiles = $this->index_files_by_name($draftfiles);
        $draftfiles['photo-a.png']->delete();

        $zipdraftid = $this->create_zip_draft([
            'invalid.png' => 'This is not an image.',
        ]);
        $updated = (object) [
            'instance' => $gallery->id,
            'coursemodule' => $gallery->cmid,
            'course' => $course->id,
            'name' => 'Must not persist',
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'previewcount' => 9,
            'images' => $preparedraftid,
            'coverimage' => 0,
            'importzip' => $zipdraftid,
        ];

        $this->assert_moodle_exception_errorcode(
            static function () use ($updated): void {
                photogallery_update_instance($updated);
            },
            'invalidzipimage'
        );

        $record = $DB->get_record(
            'photogallery',
            ['id' => $gallery->id],
            '*',
            MUST_EXIST
        );
        $this->assertSame('Original gallery', $record->name);
        $this->assertSame(6, (int) $record->previewcount);

        $files = $this->index_files_by_name(
            photogallery_get_images($context)
        );
        $this->assertSame(
            ['photo-a.png', 'photo-b.png'],
            array_keys($files)
        );
    }

    /**
     * Tests complete deletion of records, metadata and files.
     */
    public function test_delete_instance_removes_records_metadata_and_files(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $imagesdraftid = $this->create_draft_area([
            'photo.png' => $this->png_one(),
        ]);
        $coverdraftid = $this->create_draft_area([
            'cover.png' => $this->png_one(),
        ]);

        $gallery = $this->getDataGenerator()->create_module(
            'photogallery',
            [
                'course' => $course->id,
                'images' => $imagesdraftid,
                'coverimage' => $coverdraftid,
            ]
        );

        $context = \core\context\module::instance($gallery->cmid);
        $images = photogallery_get_images($context);
        $image = reset($images);
        $cover = photogallery_get_cover_image($context);

        $this->assertInstanceOf(\stored_file::class, $image);
        $this->assertInstanceOf(\stored_file::class, $cover);

        $this->create_metadata(
            (int) $gallery->id,
            $image,
            'Photo caption',
            'Photo alternative',
            2
        );
        $this->create_metadata(
            (int) $gallery->id,
            $cover,
            'Cover caption',
            'Cover alternative',
            1
        );

        $this->assertTrue(photogallery_delete_instance((int) $gallery->id));
        $this->assertFalse(
            $DB->record_exists('photogallery', ['id' => $gallery->id])
        );
        $this->assertSame(
            0,
            $DB->count_records(
                'photogallery_image',
                ['photogalleryid' => $gallery->id]
            )
        );

        $filestorage = get_file_storage();
        foreach (['images', 'cover', 'thumbs'] as $filearea) {
            $files = $filestorage->get_area_files(
                $context->id,
                'mod_photogallery',
                $filearea,
                0,
                'id ASC',
                false
            );
            $this->assertCount(0, $files);
        }

        $this->assertFalse(photogallery_delete_instance((int) $gallery->id));
    }

    /**
     * Tests configured gallery and ZIP limits.
     */
    public function test_filemanager_limits_are_configured(): void {
        $course = $this->getDataGenerator()->create_course();
        $options = photogallery_get_filemanager_options($course);

        $this->assertSame(100, $options['maxfiles']);
        $this->assertSame(200 * 1024 * 1024, $options['areamaxbytes']);
        $this->assertGreaterThan(0, $options['maxbytes']);
        $this->assertLessThanOrEqual(10 * 1024 * 1024, $options['maxbytes']);
        $this->assertSame(
            ['.jpg', '.jpeg', '.png', '.webp'],
            $options['accepted_types']
        );

        $coveroptions = photogallery_get_cover_filemanager_options($course);
        $this->assertSame(
            ['.jpg', '.jpeg', '.png', '.webp'],
            $coveroptions['accepted_types']
        );
    }

    /**
     * Tests strict feature values used by Moodle's resource overview.
     */
    public function test_supports_returns_resource_archetype_as_integer(): void {
        $this->assertIsInt(MOD_ARCHETYPE_RESOURCE);
        $this->assertSame(
            MOD_ARCHETYPE_RESOURCE,
            photogallery_supports(FEATURE_MOD_ARCHETYPE)
        );
    }

    /**
     * Tests read-only integration with Moodle's file browser.
     */
    public function test_file_browser_exposes_source_areas_read_only(): void {
        $course = $this->getDataGenerator()->create_course();
        $imagesdraftid = $this->create_draft_area([
            'photo.png' => $this->png_one(),
        ]);
        $gallery = $this->getDataGenerator()->create_module(
            'photogallery',
            [
                'course' => $course->id,
                'images' => $imagesdraftid,
            ]
        );

        $cm = get_coursemodule_from_instance(
            'photogallery',
            $gallery->id,
            $course->id,
            false,
            MUST_EXIST
        );
        $context = \core\context\module::instance($cm->id);
        $areas = photogallery_get_file_areas($course, $cm, $context);

        $this->assertSame(
            ['images', 'cover'],
            array_keys($areas)
        );

        $rootinfo = photogallery_get_file_info(
            get_file_browser(),
            $areas,
            $course,
            $cm,
            $context,
            'images',
            0,
            null,
            null
        );

        $this->assertInstanceOf(\file_info::class, $rootinfo);
        $this->assertTrue($rootinfo->is_directory());
        $this->assertNull($rootinfo->get_url());
        $this->assertCount(1, $rootinfo->get_children());

        $fileinfo = photogallery_get_file_info(
            get_file_browser(),
            $areas,
            $course,
            $cm,
            $context,
            'images',
            0,
            '/',
            'photo.png'
        );

        $this->assertInstanceOf(\file_info::class, $fileinfo);
        $this->assertTrue($fileinfo->is_readable());
        $this->assertFalse($fileinfo->is_writable());
        $this->assertStringContainsString(
            '/mod_photogallery/images/0/photo.png',
            $fileinfo->get_url()
        );

        $this->assertNull(
            photogallery_get_file_info(
                get_file_browser(),
                $areas,
                $course,
                $cm,
                $context,
                'thumbs',
                0,
                '/',
                'photo.png'
            )
        );
    }

    /**
     * Tests lifecycle synchronisation of expected-completion events.
     */
    public function test_instance_lifecycle_synchronises_completion_event(): void {
        global $CFG, $DB;

        $CFG->enablecompletion = true;
        $course = $this->getDataGenerator()->create_course([
            'enablecompletion' => true,
        ]);
        $firstexpected = time() + DAYSECS;
        $gallery = $this->getDataGenerator()->create_module(
            'photogallery',
            ['course' => $course->id],
            [
                'completion' => COMPLETION_TRACKING_AUTOMATIC,
                'completionview' => COMPLETION_VIEW_REQUIRED,
                'completionexpected' => $firstexpected,
            ]
        );

        $eventparams = [
            'modulename' => 'photogallery',
            'instance' => $gallery->id,
            'eventtype' =>
                \core_completion\api::COMPLETION_EVENT_TYPE_DATE_COMPLETION_EXPECTED,
        ];
        $event = $DB->get_record('event', $eventparams, '*', MUST_EXIST);
        $this->assertSame($firstexpected, (int) $event->timestart);

        $secondexpected = $firstexpected + DAYSECS;
        $updated = (object) [
            'instance' => $gallery->id,
            'coursemodule' => $gallery->cmid,
            'course' => $course->id,
            'name' => $gallery->name,
            'intro' => $gallery->intro,
            'introformat' => $gallery->introformat,
            'previewcount' => $gallery->previewcount,
            'images' => 0,
            'coverimage' => 0,
            'importzip' => 0,
            'completionexpected' => $secondexpected,
        ];

        $this->assertTrue(photogallery_update_instance($updated));
        $event = $DB->get_record('event', $eventparams, '*', MUST_EXIST);
        $this->assertSame($secondexpected, (int) $event->timestart);

        $this->assertTrue(
            photogallery_delete_instance((int) $gallery->id)
        );
        $this->assertFalse($DB->record_exists('event', $eventparams));
    }

    /**
     * Tests that previews are generated only by the queued adhoc task.
     */
    public function test_preview_generation_is_queued(): void {
        $course = $this->getDataGenerator()->create_course();
        $imagesdraftid = $this->create_draft_area([
            'photo.png' => $this->png_one(),
        ]);
        $gallery = $this->getDataGenerator()->create_module(
            'photogallery',
            [
                'course' => $course->id,
                'images' => $imagesdraftid,
            ]
        );
        $context = \core\context\module::instance($gallery->cmid);
        $image = reset(photogallery_get_images($context));

        $this->assertInstanceOf(\stored_file::class, $image);
        $this->assertCount(
            0,
            get_file_storage()->get_area_files(
                $context->id,
                'mod_photogallery',
                'thumbs',
                0,
                'id ASC',
                false
            )
        );

        $tasks = array_values(\core\task\manager::get_adhoc_tasks(
            \mod_photogallery\task\generate_previews::class
        ));
        $this->assertCount(2, $tasks);

        $tasks[0]->execute();
        $tasks[1]->execute();

        $thumbs = get_file_storage()->get_area_files(
            $context->id,
            'mod_photogallery',
            'thumbs',
            0,
            'id ASC',
            false
        );
        $this->assertCount(2, $thumbs);
        $this->assertInstanceOf(
            \stored_file::class,
            photogallery_get_resized_preview($image, $context, 'grid')
        );
    }

    /**
     * Tests successful import and filtering of unsupported entries.
     */
    public function test_import_zip_imports_only_supported_images(): void {
        $course = $this->getDataGenerator()->create_course();
        $gallery = $this->getDataGenerator()->create_module(
            'photogallery',
            ['course' => $course->id]
        );
        $context = \core\context\module::instance($gallery->cmid);

        $zipdraftid = $this->create_zip_draft([
            'folder/photo-a.png' => $this->png_one(),
            'photo-b.png' => $this->png_two(),
            'notes.txt' => 'This entry must be ignored.',
        ]);

        $imported = photogallery_import_zip(
            $zipdraftid,
            $context,
            $course
        );

        $this->assertSame(2, $imported);

        $filesbyname = $this->index_files_by_name(
            photogallery_get_images($context)
        );

        $this->assertCount(2, $filesbyname);
        $this->assertArrayHasKey('photo-a.png', $filesbyname);
        $this->assertArrayHasKey('photo-b.png', $filesbyname);
        $this->assertArrayNotHasKey('notes.txt', $filesbyname);
    }

    /**
     * Tests rejection of a ZIP exceeding the 100-image limit.
     */
    public function test_import_zip_rejects_more_than_maximum_images(): void {
        $course = $this->getDataGenerator()->create_course();
        $gallery = $this->getDataGenerator()->create_module(
            'photogallery',
            ['course' => $course->id]
        );
        $context = \core\context\module::instance($gallery->cmid);

        $entries = [];
        for ($index = 1; $index <= 101; $index++) {
            $entries[sprintf('photo-%03d.png', $index)] = $this->png_one();
        }

        $zipdraftid = $this->create_zip_draft($entries);

        $this->assert_moodle_exception_errorcode(
            static function () use ($zipdraftid, $context, $course): void {
                photogallery_import_zip($zipdraftid, $context, $course);
            },
            'toomanyimages'
        );

        $this->assertCount(0, photogallery_get_images($context));
    }

    /**
     * Tests rejection of a ZIP image exceeding the per-file size limit.
     */
    public function test_import_zip_rejects_image_larger_than_limit(): void {
        $course = $this->getDataGenerator()->create_course();
        $gallery = $this->getDataGenerator()->create_module(
            'photogallery',
            ['course' => $course->id]
        );
        $context = \core\context\module::instance($gallery->cmid);
        $options = photogallery_get_filemanager_options($course);

        $this->assertGreaterThan(0, $options['maxbytes']);

        $zipdraftid = $this->create_zip_draft([
            'oversized.png' => str_repeat('0', $options['maxbytes'] + 1),
        ]);

        $this->assert_moodle_exception_errorcode(
            static function () use ($zipdraftid, $context, $course): void {
                photogallery_import_zip($zipdraftid, $context, $course);
            },
            'zipimagetoolarge'
        );

        $this->assertCount(0, photogallery_get_images($context));
    }

    /**
     * Tests that an invalid image does not leave a partial ZIP import.
     */
    public function test_import_zip_rolls_back_when_an_image_is_invalid(): void {
        $course = $this->getDataGenerator()->create_course();
        $gallery = $this->getDataGenerator()->create_module(
            'photogallery',
            ['course' => $course->id]
        );
        $context = \core\context\module::instance($gallery->cmid);

        $zipdraftid = $this->create_zip_draft([
            'valid.png' => $this->png_one(),
            'invalid.png' => 'This is not a real image.',
        ]);

        $this->assert_moodle_exception_errorcode(
            static function () use ($zipdraftid, $context, $course): void {
                photogallery_import_zip($zipdraftid, $context, $course);
            },
            'invalidzipimage'
        );

        $this->assertCount(0, photogallery_get_images($context));
    }

    /**
     * Tests that metadata cleanup removes only records without source files.
     */
    public function test_cleanup_image_metadata_removes_only_orphans(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $imagesdraftid = $this->create_draft_area([
            'photo-a.png' => $this->png_one(),
            'photo-b.png' => $this->png_two(),
        ]);

        $gallery = $this->getDataGenerator()->create_module(
            'photogallery',
            [
                'course' => $course->id,
                'images' => $imagesdraftid,
            ]
        );

        $context = \core\context\module::instance($gallery->cmid);
        $filesbyname = $this->index_files_by_name(
            photogallery_get_images($context)
        );

        $this->create_metadata(
            (int) $gallery->id,
            $filesbyname['photo-a.png'],
            'Caption A',
            'Alternative A',
            1
        );
        $this->create_metadata(
            (int) $gallery->id,
            $filesbyname['photo-b.png'],
            'Caption B',
            'Alternative B',
            2
        );

        $deletedhash = $filesbyname['photo-a.png']->get_pathnamehash();
        $remaininghash = $filesbyname['photo-b.png']->get_pathnamehash();
        $filesbyname['photo-a.png']->delete();

        $this->assertSame(
            1,
            photogallery_cleanup_image_metadata(
                (int) $gallery->id,
                $context
            )
        );

        $this->assertFalse(
            $DB->record_exists(
                'photogallery_image',
                [
                    'photogalleryid' => $gallery->id,
                    'pathnamehash' => $deletedhash,
                ]
            )
        );
        $this->assertTrue(
            $DB->record_exists(
                'photogallery_image',
                [
                    'photogalleryid' => $gallery->id,
                    'pathnamehash' => $remaininghash,
                ]
            )
        );

        $this->assertSame(
            0,
            photogallery_cleanup_image_metadata(
                (int) $gallery->id,
                $context
            )
        );
    }

    /**
     * Creates files in a user draft area.
     *
     * @param array $files Filename-to-content map.
     * @return int Draft item ID.
     */
    private function create_draft_area(array $files): int {
        global $USER;

        $draftitemid = file_get_unused_draft_itemid();
        $usercontext = \core\context\user::instance($USER->id);
        $filestorage = get_file_storage();

        foreach ($files as $filename => $content) {
            $filestorage->create_file_from_string(
                [
                    'contextid' => $usercontext->id,
                    'component' => 'user',
                    'filearea' => 'draft',
                    'itemid' => $draftitemid,
                    'filepath' => '/',
                    'filename' => $filename,
                    'userid' => $USER->id,
                ],
                $content
            );
        }

        return $draftitemid;
    }

    /**
     * Creates a ZIP file in a user draft area.
     *
     * @param array $entries Archive-path-to-content map.
     * @return int Draft item ID.
     */
    private function create_zip_draft(array $entries): int {
        global $USER;

        $draftitemid = file_get_unused_draft_itemid();
        $usercontext = \core\context\user::instance($USER->id);
        $archiveentries = [];

        foreach ($entries as $pathname => $content) {
            $archiveentries[$pathname] = [$content];
        }

        $packer = get_file_packer('application/zip');
        $zipfile = $packer->archive_to_storage(
            $archiveentries,
            $usercontext->id,
            'user',
            'draft',
            $draftitemid,
            '/',
            'gallery.zip',
            $USER->id,
            false
        );

        $this->assertInstanceOf(\stored_file::class, $zipfile);

        return $draftitemid;
    }

    /**
     * Creates a metadata record for an image.
     *
     * @param int $galleryid Gallery instance ID.
     * @param \stored_file $file Stored image file.
     * @param string $caption Image caption.
     * @param string $alttext Image alternative text.
     * @param int $sortorder Image sort order.
     * @return \stdClass Created metadata record.
     */
    private function create_metadata(
        int $galleryid,
        \stored_file $file,
        string $caption,
        string $alttext,
        int $sortorder
    ): \stdClass {
        global $DB;

        $now = time();
        $record = (object) [
            'photogalleryid' => $galleryid,
            'pathnamehash' => $file->get_pathnamehash(),
            'contenthash' => $file->get_contenthash(),
            'caption' => $caption,
            'alttext' => $alttext,
            'sortorder' => $sortorder,
            'timecreated' => $now,
            'timemodified' => $now,
        ];

        $record->id = $DB->get_field(
            'photogallery_image',
            'id',
            [
                'photogalleryid' => $galleryid,
                'pathnamehash' => $file->get_pathnamehash()
            ]
        );

        if ($record->id) {
            $DB->update_record('photogallery_image', $record);
        } else {
            $record->id = $DB->insert_record('photogallery_image', $record);
        }

        return $record;
    }

    /**
     * Indexes stored files by filename.
     *
     * @param stored_file[] $files Files to index.
     * @return array<string, stored_file>
     */
    private function index_files_by_name(array $files): array {
        $indexed = [];

        foreach ($files as $file) {
            $indexed[$file->get_filename()] = $file;
        }

        return $indexed;
    }

    /**
     * Asserts the Moodle error code produced by a callback.
     *
     * @param callable $callback Callback expected to throw a Moodle exception.
     * @param string $expectederrorcode Expected Moodle error code.
     * @return void
     */
    private function assert_moodle_exception_errorcode(
        callable $callback,
        string $expectederrorcode
    ): void {
        try {
            $callback();
        } catch (\moodle_exception $exception) {
            $this->assertSame($expectederrorcode, $exception->errorcode);
            return;
        }

        $this->fail(
            'Expected moodle_exception with error code: ' . $expectederrorcode
        );
    }

    /**
     * Returns the first valid PNG fixture.
     *
     * @return string
     */
    private function png_one(): string {
        return base64_decode(self::PNG_ONE, true);
    }

    /**
     * Returns the second valid PNG fixture.
     *
     * @return string
     */
    private function png_two(): string {
        return base64_decode(self::PNG_TWO, true);
    }

    /**
     * Returns the third valid PNG fixture.
     *
     * @return string
     */
    private function png_three(): string {
        return base64_decode(self::PNG_THREE, true);
    }

    /**
     * Tests the gallery viewed event and completion by view.
     */
    public function test_view_triggers_event_and_completion(): void {
        global $CFG;

        $this->resetAfterTest();

        $CFG->enablecompletion = true;

        $course =
            $this->getDataGenerator()->create_course([
                'enablecompletion' => true,
            ]);

        $gallery =
            $this->getDataGenerator()->create_module(
                'photogallery',
                [
                    'course' => $course->id,
                    'name' => 'Gallery completion test',
                    'previewcount' => 6,
                ],
                [
                    'completion' =>
                        COMPLETION_TRACKING_AUTOMATIC,

                    'completionview' =>
                        COMPLETION_VIEW_REQUIRED,
                ]
            );

        $cm = get_coursemodule_from_instance(
            'photogallery',
            $gallery->id,
            $course->id,
            false,
            MUST_EXIST
        );

        $context =
            \core\context\module::instance(
                $cm->id
            );

        $this->setAdminUser();

        /*
        * Confirm that the module declares completion
        * tracking by view.
        */
        $this->assertTrue(
            photogallery_supports(
                FEATURE_COMPLETION_TRACKS_VIEWS
            )
        );

        /*
        * Capture all events triggered by the view.
        */
        $sink = $this->redirectEvents();

        photogallery_view(
            $gallery,
            $course,
            $cm,
            $context
        );

        $events = $sink->get_events();
        $sink->close();

        /*
        * Completion may trigger other core events.
        * Filter only the Photo gallery view event.
        */
        $viewevents = array_values(
            array_filter(
                $events,
                static fn(\core\event\base $event): bool =>
                    $event instanceof
                    \mod_photogallery\event\course_module_viewed
            )
        );

        $this->assertCount(
            1,
            $viewevents
        );

        $event = $viewevents[0];

        $this->assertSame(
            (int) $gallery->id,
            (int) $event->objectid
        );

        $this->assertEquals(
            $context,
            $event->get_context()
        );

        $expectedurl = new \moodle_url(
            '/mod/photogallery/view.php',
            ['id' => $cm->id]
        );

        $this->assertEquals(
            $expectedurl,
            $event->get_url()
        );

        $this->assertEventContextNotUsed(
            $event
        );

        /*
        * Confirm that the activity was marked complete.
        */
        $completion = new \completion_info(
            $course
        );

        $completiondata =
            $completion->get_data(
                $cm
            );

        $this->assertSame(
            COMPLETION_COMPLETE,
            (int) $completiondata->completionstate
        );
    }
}
