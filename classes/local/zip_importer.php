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
 * ZIP import management for the Photo gallery activity.
 *
 * @package   mod_photogallery
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_photogallery\local;

use core\context\module as context_module;
use core\context\user as context_user;
use moodle_exception;
use stdClass;
use stored_file;

/**
 * Imports photographs from ZIP archives.
 */
final class zip_importer {
    /**
     * Supported file extensions inside ZIP archives.
     */
    private const ALLOWED_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
    ];

    /**
     * Returns files from a user draft area.
     *
     * @param int $draftitemid Draft item ID.
     * @return stored_file[]
     */
    public static function get_draft_files(
        int $draftitemid
    ): array {
        global $USER;

        if ($draftitemid <= 0) {
            return [];
        }

        $usercontext = context_user::instance(
            $USER->id,
            MUST_EXIST
        );

        $filestorage = get_file_storage();

        return $filestorage->get_area_files(
            $usercontext->id,
            'user',
            'draft',
            $draftitemid,
            'id ASC',
            false
        );
    }

    /**
     * Returns the supported image entries inside an uploaded ZIP.
     *
     * @param int $draftitemid ZIP draft item ID.
     * @return string[]
     */
    public static function get_image_entries(
        int $draftitemid
    ): array {
        $draftfiles = self::get_draft_files(
            $draftitemid
        );

        if (empty($draftfiles)) {
            return [];
        }

        $zipfile = reset($draftfiles);

        if (!$zipfile instanceof stored_file) {
            return [];
        }

        $packer = get_file_packer(
            'application/zip'
        );

        $entries = $zipfile->list_files(
            $packer
        );

        if ($entries === false) {
            throw new moodle_exception(
                'invalidzip',
                'mod_photogallery'
            );
        }

        $imageentries = [];

        foreach ($entries as $entry) {
            if (!empty($entry->is_directory)) {
                continue;
            }

            $extension = strtolower(
                pathinfo(
                    $entry->pathname,
                    PATHINFO_EXTENSION
                )
            );

            if (
                !in_array(
                    $extension,
                    self::ALLOWED_EXTENSIONS,
                    true
                )
            ) {
                continue;
            }

            $imageentries[] = $entry->pathname;
        }

        return $imageentries;
    }

    /**
     * Generates a unique filename inside the gallery.
     *
     * @param context_module $context Activity context.
     * @param string $filename Original filename.
     * @return string
     */
    public static function get_unique_filename(
        context_module $context,
        string $filename
    ): string {
        $filestorage = get_file_storage();

        $filename = clean_param(
            $filename,
            PARAM_FILE
        );

        if ($filename === '') {
            $filename = 'photograph.jpg';
        }

        $extension = pathinfo(
            $filename,
            PATHINFO_EXTENSION
        );

        $basename = pathinfo(
            $filename,
            PATHINFO_FILENAME
        );

        $candidate = $filename;
        $counter = 2;

        while (
            $filestorage->file_exists(
                $context->id,
                'mod_photogallery',
                'images',
                0,
                '/',
                $candidate
            )
        ) {
            $suffix = $extension !== ''
                ? '.' . $extension
                : '';
            $candidate = $basename . '-' . $counter . $suffix;
            $counter++;
        }
        return $candidate;
    }

    /**
     * Imports photographs from an uploaded ZIP.
     *
     * @param int $draftitemid ZIP draft item ID.
     * @param context_module $context Activity context.
     * @param stdClass $course Course record.
     * @return int Number of imported images.
     */
    public static function import(
        int $draftitemid,
        context_module $context,
        stdClass $course
    ): int {
        global $USER;

        if ($draftitemid <= 0) {
            return 0;
        }

        $draftfiles = self::get_draft_files(
            $draftitemid
        );

        $zipfile = reset($draftfiles);

        if (!$zipfile instanceof stored_file) {
            return 0;
        }

        $entries = self::get_image_entries(
            $draftitemid
        );

        if (empty($entries)) {
            throw new moodle_exception(
                'zipnoimages',
                'mod_photogallery'
            );
        }

        $currentimages = photogallery_get_images(
            $context
        );

        $options =
            photogallery_get_filemanager_options(
                $course
            );

        if (
            count($currentimages) + count($entries)
                > $options['maxfiles']
        ) {
            throw new moodle_exception(
                'toomanyimages',
                'mod_photogallery',
                '',
                $options['maxfiles']
            );
        }

        $packer = get_file_packer(
            'application/zip'
        );

        $archivecontentsize =
            $zipfile->get_total_content_size(
                $packer
            );

        if ($archivecontentsize === null) {
            throw new moodle_exception(
                'invalidzip',
                'mod_photogallery'
            );
        }

        $existingbytes = 0;

        foreach ($currentimages as $currentimage) {
            $existingbytes +=
                $currentimage->get_filesize();
        }

        /*
         * Check the expanded size before extracting the archive.
         * This protects the temporary directory from ZIP bombs.
         */
        if (
            $options['areamaxbytes'] > 0
            && $existingbytes + $archivecontentsize
                > $options['areamaxbytes']
        ) {
            throw new moodle_exception(
                'zipareatoolarge',
                'mod_photogallery',
                '',
                display_size(
                    $options['areamaxbytes']
                )
            );
        }

        $zippath = $zipfile->copy_content_to_temp(
            'mod_photogallery',
            'galleryzip_'
        );

        if (!$zippath) {
            throw new moodle_exception(
                'invalidzip',
                'mod_photogallery'
            );
        }

        $temporarydirectory =
            make_temp_directory(
                'mod_photogallery/'
                . random_string(20)
            );

        try {
            $extracted =
                $packer->extract_to_pathname(
                    $zippath,
                    $temporarydirectory,
                    $entries,
                    null,
                    true
                );

            if (!$extracted) {
                throw new moodle_exception(
                    'invalidzip',
                    'mod_photogallery'
                );
            }

            $totalbytes = $existingbytes;
            $preparedfiles = [];

            /*
             * Validate every image before creating permanent files.
             * This prevents partial imports.
             */
            foreach ($entries as $entry) {
                $localpath =
                    $temporarydirectory
                    . DIRECTORY_SEPARATOR
                    . str_replace(
                        '/',
                        DIRECTORY_SEPARATOR,
                        $entry
                    );

                if (!is_file($localpath)) {
                    continue;
                }

                $filesize = filesize(
                    $localpath
                );

                if ($filesize === false) {
                    throw new moodle_exception(
                        'invalidzipimage',
                        'mod_photogallery',
                        '',
                        basename($entry)
                    );
                }

                if (
                    $filesize
                    > $options['maxbytes']
                ) {
                    throw new moodle_exception(
                        'zipimagetoolarge',
                        'mod_photogallery',
                        '',
                        basename($entry)
                    );
                }

                $totalbytes += $filesize;

                if (
                    $options['areamaxbytes'] > 0
                    && $totalbytes
                        > $options['areamaxbytes']
                ) {
                    throw new moodle_exception(
                        'zipareatoolarge',
                        'mod_photogallery',
                        '',
                        display_size(
                            $options['areamaxbytes']
                        )
                    );
                }

                if (
                    @getimagesize($localpath)
                        === false
                ) {
                    throw new moodle_exception(
                        'invalidzipimage',
                        'mod_photogallery',
                        '',
                        basename($entry)
                    );
                }

                $preparedfiles[] = (object) [
                    'localpath' => $localpath,
                    'filename' => basename($entry),
                ];
            }

            $filestorage = get_file_storage();
            $createdfiles = [];

            try {
                foreach ($preparedfiles as $preparedfile) {
                    $filename =
                        self::get_unique_filename(
                            $context,
                            $preparedfile->filename
                        );

                    $filerecord = [
                        'contextid' => $context->id,
                        'component' =>
                            'mod_photogallery',
                        'filearea' => 'images',
                        'itemid' => 0,
                        'filepath' => '/',
                        'filename' => $filename,
                        'userid' => $USER->id,
                    ];

                    $createdfiles[] = $filestorage->create_file_from_pathname(
                        $filerecord,
                        $preparedfile->localpath
                    );
                }
            } catch (\Throwable $exception) {
                foreach ($createdfiles as $createdfile) {
                    $createdfile->delete();
                }

                throw $exception;
            }

            return count($createdfiles);
        } finally {
            @unlink($zippath);

            fulldelete(
                $temporarydirectory
            );
        }
    }
}
