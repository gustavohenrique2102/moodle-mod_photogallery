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
 * Image metadata management.
 *
 * @package   mod_photogallery
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_photogallery\local;
use core\context\module as context_module;

/**
 * Reads and cleans gallery image metadata.
 */
final class metadata_manager {
    /**
     * Returns gallery metadata indexed by pathname hash.
     *
     * @param int $photogalleryid Gallery instance ID.
     * @return \stdClass[]
     */
    public static function get_by_pathnamehash(
        int $photogalleryid
    ): array {
        global $DB;

        $records = $DB->get_records(
            'photogallery_image',
            [
                'photogalleryid' => $photogalleryid,
            ],
            '',
            'id, photogalleryid, pathnamehash, caption, alttext, sortorder'
        );

        $recordsbyhash = [];

        foreach ($records as $record) {
            $recordsbyhash[$record->pathnamehash] = $record;
        }

        return $recordsbyhash;
    }

    /**
     * Removes metadata records whose source files no longer exist.
     *
     * The method checks both regular photographs and the featured image.
     *
     * @param int $photogalleryid Gallery instance ID.
     * @param context_module $context Activity context.
     * @return int Number of metadata records removed.
     */
    public static function cleanup_orphans(
        int $photogalleryid,
        context_module $context
    ): int {
        global $DB;

        $filestorage = get_file_storage();
        $validhashes = [];

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
                $validhashes[$file->get_pathnamehash()] = true;
            }
        }

        $records = $DB->get_records(
            'photogallery_image',
            [
                'photogalleryid' => $photogalleryid,
            ],
            '',
            'id, pathnamehash'
        );

        $removed = 0;

        foreach ($records as $record) {
            if (
                isset(
                    $validhashes[$record->pathnamehash]
                )
            ) {
                continue;
            }

            $DB->delete_records(
                'photogallery_image',
                [
                    'id' => $record->id,
                ]
            );

            $removed++;
        }

        return $removed;
    }
}
