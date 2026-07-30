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
 * Gallery thumbnail management.
 *
 * @package   mod_photogallery
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_photogallery\local;

use core\context\module as context_module;
use stored_file;

/**
 * Creates and removes generated gallery previews.
 */
final class thumbnail_manager {
    /**
     * Available generated preview sizes.
     */
    private const SIZES = [
        'grid' => [
            'width' => 640,
            'height' => 480,
        ],
        'mosaic' => [
            'width' => 960,
            'height' => 720,
        ],
    ];

    /**
     * Returns an existing preview or creates a new one.
     *
     * @param stored_file $source Original image.
     * @param context_module $context Activity context.
     * @param string $mode Preview mode.
     * @return stored_file|null
     */
    public static function get_resized_preview(
        stored_file $source,
        context_module $context,
        string $mode
    ): ?stored_file {
        if (!isset(self::SIZES[$mode])) {
            throw new \coding_exception(
                'Invalid photo gallery preview mode: ' . $mode
            );
        }

        /*
         * SVG images are already scalable and should not
         * be processed by the image resizing library.
         */
        if ($source->get_mimetype() === 'image/svg+xml') {
            return $source;
        }

        $filestorage = get_file_storage();
        $filepath = '/' . $mode . '/';
        $filename = $source->get_contenthash();

        $existing = $filestorage->get_file(
            $context->id,
            'mod_photogallery',
            'thumbs',
            0,
            $filepath,
            $filename
        );

        if ($existing instanceof stored_file) {
            return $existing;
        }

        $size = self::SIZES[$mode];

        $previewdata = $source->resize_image(
            $size['width'],
            $size['height']
        );

        if ($previewdata === false) {
            return null;
        }

        /*
         * A concurrent request may have created the same
         * preview while this request was resizing it.
         */
        $existing = $filestorage->get_file(
            $context->id,
            'mod_photogallery',
            'thumbs',
            0,
            $filepath,
            $filename
        );

        if ($existing instanceof stored_file) {
            return $existing;
        }

        $imageinfo = @getimagesizefromstring(
            $previewdata
        );

        $filerecord = [
            'contextid' => $context->id,
            'component' => 'mod_photogallery',
            'filearea' => 'thumbs',
            'itemid' => 0,
            'filepath' => $filepath,
            'filename' => $filename,
            'mimetype' => $imageinfo['mime']
                ?? 'image/png',
            'source' => $source->get_filename(),
            'timecreated' => time(),
            'timemodified' => time(),
        ];

        return $filestorage->create_file_from_string(
            $filerecord,
            $previewdata
        );
    }

    /**
     * Generates any missing previews.
     *
     * @param stored_file[] $images Ordered gallery images.
     * @param context_module $context Activity context.
     * @param int $previewcount Number shown in the course mosaic.
     * @return void
     */
    public static function generate_missing_previews(
        array $images,
        context_module $context,
        int $previewcount
    ): void {
        $previewcount = max(
            1,
            min(12, $previewcount)
        );

        $images = array_values(
            array_filter(
                $images,
                static fn($file): bool =>
                    $file instanceof stored_file
            )
        );

        if (empty($images)) {
            return;
        }

        /*
         * Every image receives a grid thumbnail.
         */
        foreach ($images as $file) {
            self::get_resized_preview(
                $file,
                $context,
                'grid'
            );
        }

        /*
         * Only images which may appear in the course-page
         * mosaic need the larger mosaic preview.
         */
        $mosaicimages = array_slice(
            $images,
            0,
            $previewcount
        );

        foreach ($mosaicimages as $file) {
            self::get_resized_preview(
                $file,
                $context,
                'mosaic'
            );
        }
    }

    /**
     * Removes generated previews without a source image.
     *
     * @param context_module $context Activity context.
     * @return int Number of deleted preview files.
     */
    public static function cleanup_generated_previews(
        context_module $context
    ): int {
        $filestorage = get_file_storage();
        $validcontenthashes = [];

        foreach (['images', 'cover'] as $filearea) {
            $sourcefiles = $filestorage->get_area_files(
                $context->id,
                'mod_photogallery',
                $filearea,
                0,
                'id ASC',
                false
            );

            foreach ($sourcefiles as $sourcefile) {
                $validcontenthashes[$sourcefile->get_contenthash()] = true;
            }
        }

        $previewfiles = $filestorage->get_area_files(
            $context->id,
            'mod_photogallery',
            'thumbs',
            0,
            'id ASC',
            false
        );

        $removed = 0;

        foreach ($previewfiles as $previewfile) {
            if (
                isset(
                    $validcontenthashes[$previewfile->get_filename()]
                )
            ) {
                continue;
            }

            $previewfile->delete();
            $removed++;
        }

        return $removed;
    }
}
