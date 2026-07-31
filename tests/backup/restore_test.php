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

namespace mod_photogallery\backup;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/phpunit/classes/restore_date_testcase.php');

/**
 * Backup and restore tests for the Photo gallery activity.
 *
 * @package   mod_photogallery
 * @category  test
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\Group('mod_photogallery')]
#[\PHPUnit\Framework\Attributes\Group('backup')]
#[\PHPUnit\Framework\Attributes\CoversClass(\backup_photogallery_activity_structure_step::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_photogallery_activity_structure_step::class)]
final class restore_test extends \restore_date_testcase {
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

    /**
     * Tests preservation of cover, order, captions and alternative text.
     */
    public function test_backup_and_restore_preserves_gallery_data(): void {
        global $DB;

        $imagesdraftid = $this->create_draft_area([
            'photo-a.png' => $this->png_one(),
            'photo-b.png' => $this->png_two(),
        ]);
        $coverdraftid = $this->create_draft_area([
            'cover.gif' => $this->gif_one(),
        ]);

        [$course, $gallery] = $this->create_course_and_module(
            'photogallery',
            [
                'name' => 'Gallery for backup',
                'intro' => 'Backup introduction',
                'introformat' => FORMAT_HTML,
                'previewcount' => 8,
                'images' => $imagesdraftid,
                'coverimage' => $coverdraftid,
            ]
        );

        $context = \core\context\module::instance($gallery->cmid);
        $filesbyname = $this->index_files_by_name(
            photogallery_get_images($context)
        );
        $cover = photogallery_get_cover_image($context);

        $this->assertInstanceOf(\stored_file::class, $cover);

        $this->create_metadata(
            (int) $gallery->id,
            $cover,
            'Featured cover caption',
            'Featured cover alternative text',
            1
        );
        $this->create_metadata(
            (int) $gallery->id,
            $filesbyname['photo-b.png'],
            'Second photo caption',
            'Second photo alternative text',
            2
        );
        $this->create_metadata(
            (int) $gallery->id,
            $filesbyname['photo-a.png'],
            'Third photo caption',
            'Third photo alternative text',
            3
        );

        $newcourseid = $this->backup_and_restore($course);

        $restoredgallery = $DB->get_record(
            'photogallery',
            ['course' => $newcourseid],
            '*',
            MUST_EXIST
        );

        $this->assertSame('Gallery for backup', $restoredgallery->name);
        $this->assertSame(8, (int) $restoredgallery->previewcount);

        $restoredcm = get_coursemodule_from_instance(
            'photogallery',
            $restoredgallery->id,
            $newcourseid,
            false,
            MUST_EXIST
        );
        $restoredcontext = \core\context\module::instance($restoredcm->id);
        $restoredcover = photogallery_get_cover_image($restoredcontext);
        $restoredfilesbyname = $this->index_files_by_name(
            photogallery_get_images($restoredcontext)
        );

        $this->assertInstanceOf(\stored_file::class, $restoredcover);
        $this->assertSame('cover.gif', $restoredcover->get_filename());
        $this->assertArrayHasKey('photo-a.png', $restoredfilesbyname);
        $this->assertArrayHasKey('photo-b.png', $restoredfilesbyname);

        $metadata = photogallery_get_image_metadata(
            (int) $restoredgallery->id
        );

        $this->assertCount(3, $metadata);
        $this->assert_metadata(
            $metadata[$restoredcover->get_pathnamehash()],
            'Featured cover caption',
            'Featured cover alternative text',
            1
        );
        $this->assert_metadata(
            $metadata[$restoredfilesbyname['photo-b.png']->get_pathnamehash()],
            'Second photo caption',
            'Second photo alternative text',
            2
        );
        $this->assert_metadata(
            $metadata[$restoredfilesbyname['photo-a.png']->get_pathnamehash()],
            'Third photo caption',
            'Third photo alternative text',
            3
        );

        $displaynames = array_map(
            static fn(\stored_file $file): string => $file->get_filename(),
            photogallery_get_display_images(
                $restoredcontext,
                (int) $restoredgallery->id
            )
        );

        $this->assertSame(
            ['cover.gif', 'photo-b.png', 'photo-a.png'],
            $displaynames
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
     * Creates a metadata record for an image.
     *
     * @param int $galleryid Gallery instance ID.
     * @param \stored_file $file Stored image file.
     * @param string $caption Image caption.
     * @param string $alttext Image alternative text.
     * @param int $sortorder Image sort order.
     * @return void
     */
    private function create_metadata(
        int $galleryid,
        \stored_file $file,
        string $caption,
        string $alttext,
        int $sortorder
    ): void {
        global $DB;

        $now = time();
        $DB->insert_record(
            'photogallery_image',
            (object) [
                'photogalleryid' => $galleryid,
                'pathnamehash' => $file->get_pathnamehash(),
                'caption' => $caption,
                'alttext' => $alttext,
                'sortorder' => $sortorder,
                'timecreated' => $now,
                'timemodified' => $now,
            ]
        );
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
     * Asserts restored metadata values.
     *
     * @param \stdClass $record Restored metadata record.
     * @param string $caption Expected image caption.
     * @param string $alttext Expected image alternative text.
     * @param int $sortorder Expected image sort order.
     * @return void
     */
    private function assert_metadata(
        \stdClass $record,
        string $caption,
        string $alttext,
        int $sortorder
    ): void {
        $this->assertSame($caption, $record->caption);
        $this->assertSame($alttext, $record->alttext);
        $this->assertSame($sortorder, (int) $record->sortorder);
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
}
