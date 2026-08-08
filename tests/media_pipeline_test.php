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

use core\context\user as context_user;
use mod_photogallery\local\image_validator;
use mod_photogallery\local\thumbnail_manager;
use mod_photogallery\local\zip_importer;

/**
 * Security and asynchronous media-pipeline tests.
 *
 * @package   mod_photogallery
 * @category  test
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\Group('mod_photogallery')]
#[\PHPUnit\Framework\Attributes\CoversClass(image_validator::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(zip_importer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(thumbnail_manager::class)]
final class media_pipeline_test extends \advanced_testcase {
    /** Valid, GD-decodable 1x1 red PNG. */
    private const PNG_RED =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4b'
        . 'AAAADElEQVQImWP4z8AAAAMBAQCc479ZAAAAAElFTkSuQmCC';

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
     * Sanitisation returns an identity mapping and persists its source marker.
     */
    public function test_sanitize_draft_returns_mapping_and_marker(): void {
        $course = $this->getDataGenerator()->create_course();
        $draftitemid = $this->create_draft_file(
            'photo.png',
            base64_decode(self::PNG_RED)
        );

        $mappings = image_validator::sanitize_draft_area(
            $draftitemid,
            photogallery_get_filemanager_options($course)
        );
        $files = zip_importer::get_draft_files($draftitemid);

        $this->assertCount(1, $mappings);
        $this->assertCount(1, $files);
        $this->assertSame('photo.png', $mappings[0]['filename']);
        $this->assertSame(
            reset($files)->get_contenthash(),
            $mappings[0]['newcontenthash']
        );
        $this->assertTrue(image_validator::is_sanitised(reset($files)));

        $this->assertSame(
            [],
            image_validator::sanitize_draft_area(
                $draftitemid,
                photogallery_get_filemanager_options($course)
            )
        );
    }

    /**
     * A source value supplied by a repository or backup cannot forge the marker.
     */
    public function test_plain_sanitisation_marker_is_not_trusted(): void {
        $draftitemid = $this->create_draft_file(
            'photo.png',
            base64_decode(self::PNG_RED)
        );
        $files = zip_importer::get_draft_files($draftitemid);
        $file = reset($files);
        $file->set_source(serialize((object) [
            'source' => image_validator::SANITISED_SOURCE,
        ]));

        $this->assertFalse(image_validator::is_sanitised($file));
    }

    /**
     * ZIP staging ignores unsupported files and is idempotent by clean content.
     */
    public function test_zip_staging_is_idempotent(): void {
        $course = $this->getDataGenerator()->create_course();
        $zipdraftitemid = $this->create_zip_draft([
            'folder/photo.png' => base64_decode(self::PNG_RED),
            'notes.txt' => str_repeat('ignored', 1000),
        ]);
        $imagesdraftitemid = file_get_unused_draft_itemid();

        $this->assertSame(
            1,
            zip_importer::import_to_draft(
                $zipdraftitemid,
                $imagesdraftitemid,
                $course
            )
        );
        $this->assertSame(
            0,
            zip_importer::import_to_draft(
                $zipdraftitemid,
                $imagesdraftitemid,
                $course
            )
        );

        $files = zip_importer::get_draft_files($imagesdraftitemid);
        $this->assertCount(1, $files);
        $this->assertTrue(image_validator::is_sanitised(reset($files)));
    }

    /**
     * Windows device names are rejected before extraction on every platform.
     */
    public function test_zip_rejects_windows_device_path(): void {
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        $directory = make_temp_directory('mod_photogallery/' . random_string(20));
        $zippath = $directory . DIRECTORY_SEPARATOR . 'unsafe.zip';
        $archive = new \ZipArchive();
        $this->assertTrue($archive->open($zippath, \ZipArchive::CREATE));
        $this->assertTrue($archive->addFromString(
            'CON.png',
            base64_decode(self::PNG_RED)
        ));
        $archive->close();

        $draftitemid = file_get_unused_draft_itemid();
        $usercontext = context_user::instance($USER->id);
        get_file_storage()->create_file_from_pathname(
            [
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftitemid,
                'filepath' => '/',
                'filename' => 'unsafe.zip',
                'userid' => $USER->id,
            ],
            $zippath
        );

        try {
            zip_importer::validate($draftitemid, $course);
            $this->fail('An unsafe ZIP pathname was accepted.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('zipinvalidpath', $exception->errorcode);
        } finally {
            fulldelete($directory);
        }
    }

    /**
     * Streamed extraction rejects data that does not match the declared CRC.
     */
    public function test_zip_rejects_crc_mismatch(): void {
        $course = $this->getDataGenerator()->create_course();
        $directory = make_temp_directory('mod_photogallery/' . random_string(20));
        $zippath = $directory . DIRECTORY_SEPARATOR . 'bad-crc.zip';
        $archive = new \ZipArchive();
        $this->assertTrue($archive->open($zippath, \ZipArchive::CREATE));
        $this->assertTrue($archive->addFromString(
            'photo.png',
            base64_decode(self::PNG_RED)
        ));
        $archive->close();

        $zipcontent = file_get_contents($zippath);
        $this->assertIsString($zipcontent);
        $centraloffset = strpos($zipcontent, "PK\x01\x02");
        $this->assertIsInt($centraloffset);
        $crcdata = unpack('Vcrc', substr($zipcontent, $centraloffset + 16, 4));
        $this->assertIsArray($crcdata);
        $badcrc = ((int) $crcdata['crc']) ^ 0xffffffff;
        $zipcontent = substr_replace(
            $zipcontent,
            pack('V', $badcrc),
            $centraloffset + 16,
            4
        );
        $this->assertSame(strlen($zipcontent), file_put_contents($zippath, $zipcontent));

        $draftitemid = $this->create_zip_draft_from_path($zippath);

        try {
            zip_importer::validate($draftitemid, $course);
            $this->fail('A ZIP entry with an invalid CRC was accepted.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('invalidzip', $exception->errorcode);
        } finally {
            fulldelete($directory);
        }
    }

    /**
     * Real decompressed bytes are limited before a chunk reaches disk.
     */
    public function test_streaming_extraction_enforces_real_byte_limit(): void {
        $directory = make_temp_directory('mod_photogallery/' . random_string(20));
        $zippath = $directory . DIRECTORY_SEPARATOR . 'source.zip';
        $destination = make_temp_directory('mod_photogallery/' . random_string(20));
        $archive = new \ZipArchive();
        $this->assertTrue($archive->open($zippath, \ZipArchive::CREATE));
        $this->assertTrue($archive->addFromString(
            'photo.png',
            base64_decode(self::PNG_RED)
        ));
        $archive->close();

        $this->assertTrue($archive->open($zippath, \ZipArchive::CHECKCONS));
        $stat = $archive->statIndex(0);
        $this->assertIsArray($stat);
        $archive->close();
        $entry = (object) [
            'index' => 0,
            'archivename' => $stat['name'],
            'pathname' => $stat['name'],
            'filename' => basename($stat['name']),
            'size' => (int) $stat['size'],
            'compressedsize' => (int) $stat['comp_size'],
            'crc' => (int) $stat['crc'],
        ];
        $method = new \ReflectionMethod(
            zip_importer::class,
            'extract_entries_streaming'
        );

        try {
            $method->invoke(
                null,
                $zippath,
                $destination,
                [$entry],
                ['maxbytes' => 1, 'areamaxbytes' => 0],
                0
            );
            $this->fail('A streamed ZIP entry exceeded the real-byte limit.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('zipimagetoolarge', $exception->errorcode);
            $this->assertSame(
                0,
                filesize($destination . DIRECTORY_SEPARATOR . 'entry-0.tmp')
            );
        } finally {
            fulldelete($directory);
            fulldelete($destination);
        }
    }

    /**
     * Rendering returns the original fallback until a worker creates a preview.
     */
    public function test_thumbnail_generation_is_deferred_and_bulk_loaded(): void {
        $course = $this->getDataGenerator()->create_course();
        $draftitemid = $this->create_draft_file(
            'photo.png',
            base64_decode(self::PNG_RED)
        );
        $gallery = $this->getDataGenerator()->create_module(
            'photogallery',
            [
                'course' => $course->id,
                'images' => $draftitemid,
            ]
        );
        $context = \core\context\module::instance($gallery->cmid);
        $images = photogallery_get_images($context);
        $image = reset($images);
        $this->assertTrue(image_validator::is_sanitised($image));

        $this->assertNull(
            thumbnail_manager::get_resized_preview($image, $context, 'grid')
        );
        $previewdata = $image->resize_image(640, 480);
        $this->assertNotFalse($previewdata);
        $preview = get_file_storage()->create_file_from_string(
            [
                'contextid' => $context->id,
                'component' => 'mod_photogallery',
                'filearea' => 'thumbs',
                'itemid' => 0,
                'filepath' => '/grid/',
                'filename' => $image->get_contenthash(),
                'mimetype' => 'image/png',
            ],
            $previewdata
        );
        $this->assertInstanceOf(\stored_file::class, $preview);

        $previews = thumbnail_manager::queue_missing_for_mode(
            $images,
            $context,
            'grid'
        );
        $this->assertSame(
            (int) $preview->get_id(),
            (int) $previews[$image->get_contenthash()]->get_id()
        );
    }

    /**
     * Creates one regular file in a user draft area.
     *
     * @param string $filename Filename.
     * @param string $content File content.
     * @return int Draft item ID.
     */
    private function create_draft_file(string $filename, string $content): int {
        global $USER;

        $draftitemid = file_get_unused_draft_itemid();
        $usercontext = context_user::instance($USER->id);
        get_file_storage()->create_file_from_string(
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
        return $draftitemid;
    }

    /**
     * Creates a ZIP file in a user draft area.
     *
     * @param array $entries Archive pathname to content map.
     * @return int Draft item ID.
     */
    private function create_zip_draft(array $entries): int {
        global $USER;

        $archiveentries = [];
        foreach ($entries as $pathname => $content) {
            $archiveentries[$pathname] = [$content];
        }

        $draftitemid = file_get_unused_draft_itemid();
        $usercontext = context_user::instance($USER->id);
        $zipfile = get_file_packer('application/zip')->archive_to_storage(
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
     * Copies a local ZIP into a user draft area.
     *
     * @param string $zippath Archive pathname.
     * @return int Draft item ID.
     */
    private function create_zip_draft_from_path(string $zippath): int {
        global $USER;

        $draftitemid = file_get_unused_draft_itemid();
        $usercontext = context_user::instance($USER->id);
        $zipfile = get_file_storage()->create_file_from_pathname(
            [
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftitemid,
                'filepath' => '/',
                'filename' => 'gallery.zip',
                'userid' => $USER->id,
            ],
            $zippath
        );
        $this->assertInstanceOf(\stored_file::class, $zipfile);
        return $draftitemid;
    }
}
