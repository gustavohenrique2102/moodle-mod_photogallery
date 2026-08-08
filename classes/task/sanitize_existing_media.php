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
 * Existing gallery media privacy migration.
 *
 * @package   mod_photogallery
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_photogallery\task;

use core\context\module as context_module;
use core\task\adhoc_task;
use core\task\manager;
use mod_photogallery\local\image_validator;
use mod_photogallery\local\thumbnail_manager;
use moodle_exception;
use stored_file;

/**
 * Re-encodes legacy originals in bounded batches and rebuilds previews.
 */
final class sanitize_existing_media extends adhoc_task {
    /** Current marker/config migration generation. */
    public const MIGRATION_VERSION = 1;

    /** Maximum galleries handled in one task execution. */
    private const BATCH_SIZE = 20;

    /**
     * Executes one migration batch.
     *
     * @return void
     */
    #[\Override]
    public function execute(): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/photogallery/lib.php');

        $customdata = $this->get_custom_data();
        $targetgalleryid = (int) ($customdata->galleryid ?? 0);
        $afterid = (int) ($customdata->afterid ?? 0);

        if ($targetgalleryid > 0) {
            $gallery = $DB->get_record(
                'photogallery',
                ['id' => $targetgalleryid],
                'id, course, previewcount',
                IGNORE_MISSING
            );
            $galleries = $gallery ? [$gallery->id => $gallery] : [];
        } else {
            $galleries = $DB->get_records_select(
                'photogallery',
                'id > :afterid',
                ['afterid' => $afterid],
                'id ASC',
                'id, course, previewcount',
                0,
                self::BATCH_SIZE
            );
        }

        if (empty($galleries)) {
            if ($targetgalleryid <= 0) {
                set_config(
                    'mediasanitizedversion',
                    self::MIGRATION_VERSION,
                    'mod_photogallery'
                );
            }
            return;
        }

        $lastid = $afterid;
        $filestorage = get_file_storage();

        foreach ($galleries as $gallery) {
            $lastid = (int) $gallery->id;
            $cm = get_coursemodule_from_instance(
                'photogallery',
                $gallery->id,
                $gallery->course,
                false,
                IGNORE_MISSING
            );
            if (!$cm) {
                continue;
            }

            $context = context_module::instance($cm->id, IGNORE_MISSING);
            if (!$context instanceof context_module) {
                continue;
            }

            $changed = false;
            foreach (['images', 'cover'] as $filearea) {
                $files = $filestorage->get_area_files(
                    $context->id,
                    'mod_photogallery',
                    $filearea,
                    0,
                    'id ASC',
                    false
                );

                foreach ($files as $file) {
                    try {
                        $mapping = image_validator::sanitize_stored_file($file);
                        if ($mapping !== null) {
                            $changed = true;
                        }

                        if ($filearea === 'images' && image_validator::is_sanitised($file)) {
                            self::adopt_sanitised_content(
                                (int) $gallery->id,
                                $file
                            );
                        }
                    } catch (moodle_exception $exception) {
                        mtrace(
                            'Photo gallery legacy media kept unchanged: gallery '
                            . $gallery->id . ', file ' . $file->get_id()
                            . ' (' . $exception->getMessage() . ')'
                        );
                    }
                }
            }

            if ($changed) {
                thumbnail_manager::cleanup_generated_previews($context);
                thumbnail_manager::generate_missing_previews(
                    photogallery_get_display_images(
                        $context,
                        (int) $gallery->id
                    ),
                    $context,
                    (int) $gallery->previewcount
                );
            }
        }

        if ($targetgalleryid <= 0) {
            self::queue($lastid);
        }
    }

    /**
     * Queues the migration from a gallery ID cursor.
     *
     * @param int $afterid Last fully processed gallery ID.
     * @return void
     */
    public static function queue(int $afterid = 0): void {
        $task = new self();
        $task->set_component('mod_photogallery');
        $task->set_custom_data((object) ['afterid' => max(0, $afterid)]);
        manager::queue_adhoc_task($task, true);
    }

    /**
     * Queues sanitisation of one restored gallery without scanning others.
     *
     * @param int $galleryid Gallery instance ID.
     * @return void
     */
    public static function queue_gallery(int $galleryid): void {
        if ($galleryid <= 0) {
            return;
        }

        $task = new self();
        $task->set_component('mod_photogallery');
        $task->set_custom_data((object) ['galleryid' => $galleryid]);
        manager::queue_adhoc_task($task, true);
    }

    /**
     * Updates only the content identity of metadata for an intentional rewrite.
     *
     * @param int $galleryid Gallery instance ID.
     * @param stored_file $file Sanitised image.
     * @return void
     */
    private static function adopt_sanitised_content(
        int $galleryid,
        stored_file $file
    ): void {
        global $DB;

        $record = $DB->get_record(
            'photogallery_image',
            [
                'photogalleryid' => $galleryid,
                'pathnamehash' => $file->get_pathnamehash(),
            ],
            'id, contenthash',
            IGNORE_MISSING
        );
        if (!$record || hash_equals((string) $record->contenthash, $file->get_contenthash())) {
            return;
        }

        $record->contenthash = $file->get_contenthash();
        $record->timemodified = time();
        $DB->update_record('photogallery_image', $record);
    }
}
