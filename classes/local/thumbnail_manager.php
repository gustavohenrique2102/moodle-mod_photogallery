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
use core\lock\lock_config;
use mod_photogallery\task\generate_previews;
use moodle_exception;
use stored_file;

/**
 * Queues, creates and removes generated gallery previews.
 */
final class thumbnail_manager {
    /** Available generated preview sizes. */
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
     * Returns an existing preview and queues generation when it is missing.
     *
     * This method never decodes an image in the web request.
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
        self::require_valid_mode($mode);

        $existing = self::find_preview($source, $context, $mode);
        if ($existing instanceof stored_file) {
            return $existing;
        }
        if (self::has_failure_marker($source, $context, $mode)) {
            return null;
        }

        generate_previews::queue(
            $context,
            [
                [
                    'fileid' => $source->get_id(),
                    'mode' => $mode,
                ],
            ]
        );
        return null;
    }

    /**
     * Loads existing previews for many sources in a single file-area query.
     *
     * @param stored_file[] $sources Source images.
     * @param context_module $context Activity context.
     * @param string $mode Preview mode.
     * @return stored_file[] Previews indexed by source content hash.
     */
    public static function get_existing_previews(
        array $sources,
        context_module $context,
        string $mode
    ): array {
        self::require_valid_mode($mode);

        $wantedhashes = [];
        foreach ($sources as $source) {
            if ($source instanceof stored_file && !$source->is_directory()) {
                $wantedhashes[$source->get_contenthash()] = true;
            }
        }
        if (empty($wantedhashes)) {
            return [];
        }

        $files = get_file_storage()->get_area_files(
            $context->id,
            'mod_photogallery',
            'thumbs',
            0,
            'id ASC',
            false
        );
        $previews = [];
        $expectedpath = '/' . $mode . '/';

        foreach ($files as $file) {
            if (
                $file->get_filepath() === $expectedpath
                && isset($wantedhashes[$file->get_filename()])
            ) {
                $previews[$file->get_filename()] = $file;
            }
        }

        return $previews;
    }

    /**
     * Loads preview state once and queues all missing files in one batch.
     *
     * Renderables can use the returned map directly, avoiding both N+1 file
     * lookups and one ad-hoc task per image.
     *
     * @param stored_file[] $sources Source images.
     * @param context_module $context Activity context.
     * @param string $mode Preview mode.
     * @return stored_file[] Existing previews indexed by source content hash.
     */
    public static function queue_missing_for_mode(
        array $sources,
        context_module $context,
        string $mode
    ): array {
        self::require_valid_mode($mode);

        $sourcesbyhash = [];
        foreach ($sources as $source) {
            if ($source instanceof stored_file && !$source->is_directory()) {
                $sourcesbyhash[$source->get_contenthash()] ??= $source;
            }
        }
        if (empty($sourcesbyhash)) {
            return [];
        }

        $previews = [];
        $failed = [];
        $previewpath = '/' . $mode . '/';
        $failurepath = '/failed-' . $mode . '/';
        $files = get_file_storage()->get_area_files(
            $context->id,
            'mod_photogallery',
            'thumbs',
            0,
            'id ASC',
            false
        );

        foreach ($files as $file) {
            $contenthash = $file->get_filename();
            if (!isset($sourcesbyhash[$contenthash])) {
                continue;
            }
            if ($file->get_filepath() === $previewpath) {
                $previews[$contenthash] = $file;
            } else if ($file->get_filepath() === $failurepath) {
                $failed[$contenthash] = true;
            }
        }

        $requests = [];
        foreach ($sourcesbyhash as $contenthash => $source) {
            if (!isset($previews[$contenthash]) && !isset($failed[$contenthash])) {
                $requests[] = [
                    'fileid' => $source->get_id(),
                    'mode' => $mode,
                ];
            }
        }
        if (!empty($requests)) {
            generate_previews::queue($context, $requests);
        }

        return $previews;
    }

    /**
     * Queues any missing previews as one deduplicated ad-hoc task.
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
        $previewcount = max(1, min(12, $previewcount));
        $images = array_values(array_filter(
            $images,
            static fn($file): bool => $file instanceof stored_file && !$file->is_directory()
        ));
        if (empty($images)) {
            return;
        }

        self::queue_missing_for_mode($images, $context, 'grid');

        $mosaicimages = array_slice($images, 0, $previewcount);
        self::queue_missing_for_mode(
            $mosaicimages,
            $context,
            'mosaic'
        );
    }

    /**
     * Generates one preview in a worker process.
     *
     * Permanent validation failures create a marker so page views do not
     * repeatedly queue the same impossible work.
     *
     * @param stored_file $source Original image.
     * @param context_module $context Activity context.
     * @param string $mode Preview mode.
     * @return stored_file|null
     */
    public static function generate_preview_now(
        stored_file $source,
        context_module $context,
        string $mode
    ): ?stored_file {
        self::require_valid_mode($mode);

        if (
            $source->get_contextid() !== $context->id
            || $source->get_component() !== 'mod_photogallery'
            || !in_array($source->get_filearea(), ['images', 'cover'], true)
            || $source->is_directory()
        ) {
            return null;
        }

        $lockfactory = lock_config::get_lock_factory(
            'mod_photogallery_thumbnail_generation'
        );
        $lockkey = $context->id . ':' . $source->get_contenthash() . ':' . $mode;
        $lock = $lockfactory->get_lock($lockkey, 10);
        if (!$lock) {
            return self::find_preview($source, $context, $mode);
        }

        $sourcepath = null;
        $temporarydirectory = null;

        try {
            $existing = self::find_preview($source, $context, $mode);
            if ($existing instanceof stored_file) {
                return $existing;
            }
            if (self::has_failure_marker($source, $context, $mode)) {
                return null;
            }

            try {
                image_validator::validate_stored_file($source);
            } catch (moodle_exception $exception) {
                self::create_failure_marker($source, $context, $mode);
                mtrace(
                    'Photo gallery thumbnail skipped for file '
                    . $source->get_id() . ': ' . $exception->getMessage()
                );
                return null;
            }

            $size = self::SIZES[$mode];
            if (image_validator::is_sanitised($source)) {
                $previewdata = $source->resize_image(
                    $size['width'],
                    $size['height']
                );
            } else {
                $sourcepath = $source->copy_content_to_temp(
                    'mod_photogallery',
                    'thumbsource_'
                );
                if (!$sourcepath) {
                    return null;
                }
                $temporarydirectory = make_temp_directory(
                    'mod_photogallery/' . random_string(20)
                );
                $sanitisedpath = $temporarydirectory . DIRECTORY_SEPARATOR . 'source';
                try {
                    image_validator::sanitize_pathname(
                        $sourcepath,
                        $source->get_filename(),
                        $sanitisedpath
                    );
                } catch (moodle_exception $exception) {
                    self::create_failure_marker($source, $context, $mode);
                    mtrace(
                        'Photo gallery thumbnail skipped for file '
                        . $source->get_id() . ': ' . $exception->getMessage()
                    );
                    return null;
                }

                global $CFG;
                require_once($CFG->libdir . '/gdlib.php');
                $previewdata = \resize_image(
                    $sanitisedpath,
                    $size['width'],
                    $size['height']
                );
            }

            if ($previewdata === false) {
                self::create_failure_marker($source, $context, $mode);
                return null;
            }

            $imageinfo = @getimagesizefromstring($previewdata);
            if ($imageinfo === false) {
                self::create_failure_marker($source, $context, $mode);
                return null;
            }

            $filestorage = get_file_storage();
            $filerecord = [
                'contextid' => $context->id,
                'component' => 'mod_photogallery',
                'filearea' => 'thumbs',
                'itemid' => 0,
                'filepath' => '/' . $mode . '/',
                'filename' => $source->get_contenthash(),
                'mimetype' => $imageinfo['mime'] ?? 'image/png',
                'source' => $source->get_filename(),
                'timecreated' => time(),
                'timemodified' => time(),
            ];

            try {
                $preview = $filestorage->create_file_from_string(
                    $filerecord,
                    $previewdata
                );
            } catch (\stored_file_creation_exception $exception) {
                $preview = self::find_preview($source, $context, $mode);
                if (!$preview instanceof stored_file) {
                    throw $exception;
                }
            }

            self::delete_failure_marker($source, $context, $mode);
            return $preview;
        } finally {
            if ($sourcepath !== null) {
                @unlink($sourcepath);
            }
            if ($temporarydirectory !== null) {
                fulldelete($temporarydirectory);
            }
            $lock->release();
        }
    }

    /**
     * Removes generated previews and failure markers without a source image.
     *
     * @param context_module $context Activity context.
     * @return int Number of deleted files.
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
            if (isset($validcontenthashes[$previewfile->get_filename()])) {
                continue;
            }
            $previewfile->delete();
            $removed++;
        }

        return $removed;
    }

    /**
     * Finds one generated preview.
     *
     * @param stored_file $source Source image.
     * @param context_module $context Activity context.
     * @param string $mode Preview mode.
     * @return stored_file|null
     */
    private static function find_preview(
        stored_file $source,
        context_module $context,
        string $mode
    ): ?stored_file {
        $file = get_file_storage()->get_file(
            $context->id,
            'mod_photogallery',
            'thumbs',
            0,
            '/' . $mode . '/',
            $source->get_contenthash()
        );
        return $file instanceof stored_file ? $file : null;
    }

    /**
     * Returns whether generation permanently failed for this content and mode.
     *
     * @param stored_file $source Source image.
     * @param context_module $context Activity context.
     * @param string $mode Preview mode.
     * @return bool
     */
    private static function has_failure_marker(
        stored_file $source,
        context_module $context,
        string $mode
    ): bool {
        return get_file_storage()->file_exists(
            $context->id,
            'mod_photogallery',
            'thumbs',
            0,
            '/failed-' . $mode . '/',
            $source->get_contenthash()
        );
    }

    /**
     * Creates a negative-cache marker for a permanent generation failure.
     *
     * @param stored_file $source Source image.
     * @param context_module $context Activity context.
     * @param string $mode Preview mode.
     * @return void
     */
    private static function create_failure_marker(
        stored_file $source,
        context_module $context,
        string $mode
    ): void {
        if (self::has_failure_marker($source, $context, $mode)) {
            return;
        }

        try {
            get_file_storage()->create_file_from_string(
                [
                    'contextid' => $context->id,
                    'component' => 'mod_photogallery',
                    'filearea' => 'thumbs',
                    'itemid' => 0,
                    'filepath' => '/failed-' . $mode . '/',
                    'filename' => $source->get_contenthash(),
                    'mimetype' => 'text/plain',
                    'source' => $source->get_filename(),
                ],
                'Thumbnail generation failed validation.'
            );
        } catch (\stored_file_creation_exception $exception) {
            if (!self::has_failure_marker($source, $context, $mode)) {
                throw $exception;
            }
        }
    }

    /**
     * Deletes a negative-cache marker after successful generation.
     *
     * @param stored_file $source Source image.
     * @param context_module $context Activity context.
     * @param string $mode Preview mode.
     * @return void
     */
    private static function delete_failure_marker(
        stored_file $source,
        context_module $context,
        string $mode
    ): void {
        $marker = get_file_storage()->get_file(
            $context->id,
            'mod_photogallery',
            'thumbs',
            0,
            '/failed-' . $mode . '/',
            $source->get_contenthash()
        );
        if ($marker instanceof stored_file) {
            $marker->delete();
        }
    }

    /**
     * Rejects an unknown preview mode.
     *
     * @param string $mode Preview mode.
     * @return void
     */
    private static function require_valid_mode(string $mode): void {
        if (!isset(self::SIZES[$mode])) {
            throw new \coding_exception(
                'Invalid photo gallery preview mode: ' . $mode
            );
        }
    }
}
