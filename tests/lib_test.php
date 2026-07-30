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
#[\PHPUnit\Framework\Attributes\CoversFunction('photogallery_add_instance')]
#[\PHPUnit\Framework\Attributes\CoversFunction('photogallery_update_instance')]
#[\PHPUnit\Framework\Attributes\CoversFunction('photogallery_delete_instance')]
#[\PHPUnit\Framework\Attributes\CoversFunction('photogallery_get_filemanager_options')]
#[\PHPUnit\Framework\Attributes\CoversFunction('photogallery_get_zip_filepicker_options')]
#[\PHPUnit\Framework\Attributes\CoversFunction('photogallery_import_zip')]
#[\PHPUnit\Framework\Attributes\CoversFunction('photogallery_cleanup_image_metadata')]
#[\PHPUnit\Framework\Attributes\CoversFunction('photogallery_view')]
final class lib_test extends \advanced_testcase {
    /** A valid 1x1 PNG image. */
    private const PNG_ONE =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6Y1sAAAAASUVORK5CYII=';

    /** A second valid 1x1 PNG image with different content. */
    private const PNG_TWO =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    /** A valid 1x1 GIF image. */
    private const GIF_ONE =
        'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';

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
            'cover.gif' => $this->gif_one(),
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
        $this->assertSame('cover.gif', $cover->get_filename());

        $displaynames = array_map(
            static fn(\stored_file $file): string => $file->get_filename(),
            photogallery_get_display_images($context, (int) $gallery->id)
        );

        $this->assertSame('cover.gif', $displaynames[0]);
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
                'filename' => 'photo-c.gif',
                'userid' => $USER->id,
            ],
            $this->gif_one()
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
        $this->assertArrayHasKey('photo-c.gif', $remainingfiles);

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
     * Tests complete deletion of records, metadata and files.
     */
    public function test_delete_instance_removes_records_metadata_and_files(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $imagesdraftid = $this->create_draft_area([
            'photo.png' => $this->png_one(),
        ]);
        $coverdraftid = $this->create_draft_area([
            'cover.gif' => $this->gif_one(),
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
        $this->assertSame(['web_image'], $options['accepted_types']);
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
     * @param array<string, string> $files Filename-to-content map.
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
     * @param array<string, string> $entries Archive-path-to-content map.
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
            'caption' => $caption,
            'alttext' => $alttext,
            'sortorder' => $sortorder,
            'timecreated' => $now,
            'timemodified' => $now,
        ];

        $record->id = $DB->insert_record('photogallery_image', $record);

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
     * Returns the valid GIF fixture.
     *
     * @return string
     */
    private function gif_one(): string {
        return base64_decode(self::GIF_ONE, true);
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
