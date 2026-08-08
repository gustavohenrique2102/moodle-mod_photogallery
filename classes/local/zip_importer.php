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
use core\lock\lock_config;
use moodle_exception;
use stdClass;
use stored_file;

/**
 * Imports validated, sanitised photographs from ZIP archives.
 */
final class zip_importer {
    /** Maximum number of central-directory entries, including ignored files. */
    public const MAX_ARCHIVE_ENTRIES = 1000;

    /** Maximum archive pathname length in bytes. */
    public const MAX_PATH_LENGTH = 512;

    /** Maximum length of one archive pathname segment in bytes. */
    public const MAX_PATH_SEGMENT_LENGTH = 255;

    /** Maximum nested-directory depth in an archive. */
    public const MAX_PATH_DEPTH = 10;

    /** Maximum uncompressed-to-compressed ratio for selected images. */
    public const MAX_COMPRESSION_RATIO = 200;

    /** Number of decompressed bytes read from an archive stream at once. */
    private const STREAM_CHUNK_SIZE = 65536;

    /** Supported static raster extensions inside ZIP archives. */
    private const ALLOWED_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'webp',
    ];

    /**
     * Returns regular files from a user draft area.
     *
     * @param int $draftitemid Draft item ID.
     * @return stored_file[]
     */
    public static function get_draft_files(int $draftitemid): array {
        global $USER;

        if ($draftitemid <= 0) {
            return [];
        }

        $usercontext = context_user::instance($USER->id, MUST_EXIST);
        return get_file_storage()->get_area_files(
            $usercontext->id,
            'user',
            'draft',
            $draftitemid,
            'id ASC',
            false
        );
    }

    /**
     * Returns supported image entries after a bounded central-directory scan.
     *
     * This method deliberately does not extract or validate image content. Use
     * {@see self::validate()} when accepting a form submission.
     *
     * @param int $draftitemid ZIP draft item ID.
     * @return string[]
     */
    public static function get_image_entries(int $draftitemid): array {
        $zipfile = self::get_zip_file($draftitemid);
        if ($zipfile === null) {
            return [];
        }

        $zippath = $zipfile->copy_content_to_temp(
            'mod_photogallery',
            'galleryzip_'
        );
        if (!$zippath) {
            throw new moodle_exception('invalidzip', 'mod_photogallery');
        }

        try {
            return array_map(
                static fn(stdClass $entry): string => $entry->pathname,
                self::inspect_archive($zippath, [])
            );
        } finally {
            @unlink($zippath);
        }
    }

    /**
     * Fully validates a ZIP without mutating any draft or permanent file area.
     *
     * @param int $draftitemid ZIP draft item ID.
     * @param stdClass $course Course record used to calculate upload limits.
     * @param int $currentcount Existing image count.
     * @param int $currentbytes Existing image bytes.
     * @param int $currentpixels Existing decoded pixels across images and cover.
     * @return string[] Validated selected archive pathnames.
     */
    public static function validate(
        int $draftitemid,
        stdClass $course,
        int $currentcount = 0,
        int $currentbytes = 0,
        int $currentpixels = 0
    ): array {
        if ($draftitemid <= 0) {
            return [];
        }

        $options = photogallery_get_filemanager_options($course);
        return self::with_prepared_archive(
            $draftitemid,
            $options,
            $currentcount,
            $currentbytes,
            $currentpixels,
            static fn(array $files): array => array_map(
                static fn(stdClass $file): string => $file->entry,
                $files
            )
        );
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
        return self::find_unique_filename(
            $filename,
            static fn(string $candidate): bool => get_file_storage()->file_exists(
                $context->id,
                'mod_photogallery',
                'images',
                0,
                '/',
                $candidate
            )
        );
    }

    /**
     * Stages a validated and sanitised ZIP in the form's images draft area.
     *
     * The method is idempotent by sanitised content hash. It must run before
     * file_save_draft_area_files() so a later failure does not mutate the
     * gallery's permanent file area.
     *
     * @param int $zipdraftitemid ZIP draft item ID.
     * @param int $imagesdraftitemid Destination images draft item ID.
     * @param stdClass $course Course record.
     * @return int Number of newly staged images.
     */
    public static function import_to_draft(
        int $zipdraftitemid,
        int $imagesdraftitemid,
        stdClass $course
    ): int {
        global $USER;

        if ($zipdraftitemid <= 0) {
            return 0;
        }
        if ($imagesdraftitemid <= 0) {
            throw new moodle_exception('invalidzip', 'mod_photogallery');
        }

        $options = photogallery_get_filemanager_options($course);

        return self::with_prepared_archive(
            $zipdraftitemid,
            $options,
            0,
            0,
            0,
            static function (array $preparedfiles) use (
                $USER,
                $imagesdraftitemid,
                $options
            ): int {
                $lockfactory = lock_config::get_lock_factory(
                    'mod_photogallery_draft_import'
                );
                $lock = $lockfactory->get_lock(
                    $USER->id . ':' . $imagesdraftitemid,
                    10
                );
                if (!$lock) {
                    throw new moodle_exception('invalidzip', 'mod_photogallery');
                }

                try {
                    $existing = self::get_draft_files($imagesdraftitemid);
                    $existingstats = image_validator::get_stored_files_stats(
                        $existing,
                        $options
                    );
                    $preparedfiles = self::sanitise_prepared_files($preparedfiles);
                    $preparedfiles = self::remove_duplicate_content(
                        $preparedfiles,
                        $existing
                    );
                    self::enforce_final_limits($existing, $preparedfiles, $options);
                    self::enforce_total_pixels(
                        $existingstats['pixels'],
                        $preparedfiles
                    );

                    $usercontext = context_user::instance($USER->id, MUST_EXIST);
                    $filestorage = get_file_storage();
                    $created = [];

                    try {
                        foreach ($preparedfiles as $preparedfile) {
                            $filename = self::find_unique_filename(
                                $preparedfile->filename,
                                static fn(string $candidate): bool => $filestorage->file_exists(
                                    $usercontext->id,
                                    'user',
                                    'draft',
                                    $imagesdraftitemid,
                                    '/',
                                    $candidate
                                )
                            );

                            $createdfile = $filestorage->create_file_from_pathname(
                                [
                                    'contextid' => $usercontext->id,
                                    'component' => 'user',
                                    'filearea' => 'draft',
                                    'itemid' => $imagesdraftitemid,
                                    'filepath' => '/',
                                    'filename' => $filename,
                                    'userid' => $USER->id,
                                ],
                                $preparedfile->localpath
                            );
                            image_validator::mark_sanitised($createdfile);
                            $created[] = $createdfile;
                        }
                    } catch (\Throwable $exception) {
                        foreach ($created as $createdfile) {
                            $createdfile->delete();
                        }
                        throw $exception;
                    }

                    return count($created);
                } finally {
                    $lock->release();
                }
            }
        );
    }

    /**
     * Imports photographs into the permanent gallery file area.
     *
     * This legacy entry point remains safe and idempotent. New add/update
     * flows should prefer {@see self::import_to_draft()} before saving files.
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

        $options = photogallery_get_filemanager_options($course);

        return self::with_prepared_archive(
            $draftitemid,
            $options,
            0,
            0,
            0,
            static function (array $preparedfiles) use (
                $USER,
                $context,
                $options
            ): int {
                $lockfactory = lock_config::get_lock_factory(
                    'mod_photogallery_gallery_import'
                );
                $lock = $lockfactory->get_lock((string) $context->id, 10);
                if (!$lock) {
                    throw new moodle_exception('invalidzip', 'mod_photogallery');
                }

                try {
                    $existing = photogallery_get_images($context);
                    $cover = photogallery_get_cover_image($context);
                    if ($cover instanceof stored_file) {
                        $existing[] = $cover;
                    }
                    $preparedfiles = self::sanitise_prepared_files($preparedfiles);
                    $preparedfiles = self::remove_duplicate_content(
                        $preparedfiles,
                        $existing
                    );
                    self::enforce_final_limits($existing, $preparedfiles, $options);
                    self::enforce_total_pixels(
                        self::get_existing_pixels($existing),
                        $preparedfiles
                    );

                    $filestorage = get_file_storage();
                    $created = [];

                    try {
                        foreach ($preparedfiles as $preparedfile) {
                            $filename = self::get_unique_filename(
                                $context,
                                $preparedfile->filename
                            );
                            $createdfile = $filestorage->create_file_from_pathname(
                                [
                                    'contextid' => $context->id,
                                    'component' => 'mod_photogallery',
                                    'filearea' => 'images',
                                    'itemid' => 0,
                                    'filepath' => '/',
                                    'filename' => $filename,
                                    'userid' => $USER->id,
                                ],
                                $preparedfile->localpath
                            );
                            image_validator::mark_sanitised($createdfile);
                            $created[] = $createdfile;
                        }
                    } catch (\Throwable $exception) {
                        foreach ($created as $createdfile) {
                            $createdfile->delete();
                        }
                        throw $exception;
                    }

                    return count($created);
                } finally {
                    $lock->release();
                }
            }
        );
    }

    /**
     * Copies, extracts and validates an archive within a guaranteed cleanup scope.
     *
     * @param int $draftitemid ZIP draft item ID.
     * @param array $options Gallery file limits.
     * @param int $currentcount Existing image count.
     * @param int $currentbytes Existing image bytes.
     * @param int $currentpixels Existing decoded pixels.
     * @param callable $callback Receives prepared temporary files.
     * @return mixed Callback result.
     */
    private static function with_prepared_archive(
        int $draftitemid,
        array $options,
        int $currentcount,
        int $currentbytes,
        int $currentpixels,
        callable $callback
    ): mixed {
        $zipfile = self::get_zip_file($draftitemid);
        if ($zipfile === null) {
            return $callback([]);
        }

        $zippath = null;
        $temporarydirectory = null;

        try {
            $zippath = $zipfile->copy_content_to_temp(
                'mod_photogallery',
                'galleryzip_'
            );
            if (!$zippath) {
                throw new moodle_exception('invalidzip', 'mod_photogallery');
            }

            $entries = self::inspect_archive(
                $zippath,
                $options,
                $currentcount,
                $currentbytes
            );
            if (empty($entries)) {
                throw new moodle_exception('zipnoimages', 'mod_photogallery');
            }

            $temporarydirectory = make_temp_directory(
                'mod_photogallery/' . random_string(20)
            );
            $entries = self::extract_entries_streaming(
                $zippath,
                $temporarydirectory,
                $entries,
                $options,
                $currentbytes
            );

            $preparedfiles = [];
            $totalpixels = $currentpixels;

            foreach ($entries as $entry) {
                $realpath = realpath($entry->localpath);
                $realbase = realpath($temporarydirectory);

                $pathcomparison = $realpath;
                $basecomparison = $realbase === false
                    ? false
                    : $realbase . DIRECTORY_SEPARATOR;
                if (DIRECTORY_SEPARATOR === '\\') {
                    $pathcomparison = $realpath === false ? false : strtolower($realpath);
                    $basecomparison = $basecomparison === false ? false : strtolower($basecomparison);
                }

                if (
                    $realpath === false
                    || $realbase === false
                    || $pathcomparison === false
                    || $basecomparison === false
                    || !str_starts_with(
                        $pathcomparison,
                        $basecomparison
                    )
                    || !is_file($realpath)
                ) {
                    throw new moodle_exception('invalidzip', 'mod_photogallery');
                }

                $imageinfo = image_validator::validate_pathname(
                    $realpath,
                    $entry->filename,
                    (int) ($options['maxbytes'] ?? 0),
                    true
                );
                $totalpixels += $imageinfo['width'] * $imageinfo['height'];
                if ($totalpixels > image_validator::MAX_TOTAL_PIXELS) {
                    throw new moodle_exception(
                        'imagetotalpixelstoolarge',
                        'mod_photogallery',
                        '',
                        (int) (image_validator::MAX_TOTAL_PIXELS / 1000000)
                    );
                }

                $contenthash = sha1_file($realpath);
                if ($contenthash === false) {
                    throw new moodle_exception('invalidzip', 'mod_photogallery');
                }

                $preparedfiles[] = (object) [
                    'entry' => $entry->pathname,
                    'localpath' => $realpath,
                    'filename' => $entry->filename,
                    'filesize' => $entry->actualsize,
                    'contenthash' => $contenthash,
                    'width' => $imageinfo['width'],
                    'height' => $imageinfo['height'],
                ];
            }

            return $callback($preparedfiles);
        } finally {
            if ($zippath !== null) {
                @unlink($zippath);
            }
            if ($temporarydirectory !== null) {
                fulldelete($temporarydirectory);
            }
        }
    }

    /**
     * Streams selected archive entries to flat temporary files.
     *
     * Limits are checked against decompressed bytes before every write. The
     * central-directory size and CRC are then compared with the streamed data.
     * Flat generated pathnames ensure archive-controlled directories are never
     * created on disk.
     *
     * @param string $zippath Local ZIP pathname.
     * @param string $temporarydirectory Destination temporary directory.
     * @param stdClass[] $entries Selected central-directory descriptors.
     * @param array $options Gallery file limits.
     * @param int $currentbytes Existing image bytes.
     * @return stdClass[] Entry descriptors with localpath and actualsize set.
     */
    private static function extract_entries_streaming(
        string $zippath,
        string $temporarydirectory,
        array $entries,
        array $options,
        int $currentbytes
    ): array {
        $archive = new \ZipArchive();
        if ($archive->open($zippath, \ZipArchive::CHECKCONS) !== true) {
            throw new moodle_exception('invalidzip', 'mod_photogallery');
        }

        $actualbytes = $currentbytes;
        $archivebytes = 0;
        $archivecompressedbytes = 0;

        try {
            foreach ($entries as $entry) {
                self::verify_entry_descriptor($archive, $entry);

                $archivecompressedbytes = self::checked_add(
                    $archivecompressedbytes,
                    $entry->compressedsize
                );
                $localpath = $temporarydirectory
                    . DIRECTORY_SEPARATOR
                    . 'entry-' . $entry->index . '.tmp';
                $input = @$archive->getStreamIndex($entry->index);
                $output = @fopen($localpath, 'xb');
                if (!is_resource($input) || !is_resource($output)) {
                    if (is_resource($input)) {
                        fclose($input);
                    }
                    if (is_resource($output)) {
                        fclose($output);
                    }
                    throw new moodle_exception('invalidzip', 'mod_photogallery');
                }

                $entrybytes = 0;
                $crccontext = hash_init('crc32b');

                try {
                    while (!feof($input)) {
                        $chunk = @fread($input, self::STREAM_CHUNK_SIZE);
                        if ($chunk === false || ($chunk === '' && !feof($input))) {
                            throw new moodle_exception('invalidzip', 'mod_photogallery');
                        }
                        if ($chunk === '') {
                            break;
                        }

                        $chunkbytes = strlen($chunk);
                        $prospectiveentrybytes = self::checked_add(
                            $entrybytes,
                            $chunkbytes
                        );
                        $prospectiveactualbytes = self::checked_add(
                            $actualbytes,
                            $chunkbytes
                        );
                        $prospectivearchivebytes = self::checked_add(
                            $archivebytes,
                            $chunkbytes
                        );

                        self::enforce_stream_limits(
                            $entry,
                            $prospectiveentrybytes,
                            $prospectiveactualbytes,
                            $prospectivearchivebytes,
                            $archivecompressedbytes,
                            $options
                        );
                        self::write_all($output, $chunk);
                        hash_update($crccontext, $chunk);

                        $entrybytes = $prospectiveentrybytes;
                        $actualbytes = $prospectiveactualbytes;
                        $archivebytes = $prospectivearchivebytes;
                    }

                    if (!@fflush($output)) {
                        throw new moodle_exception('invalidzip', 'mod_photogallery');
                    }
                } finally {
                    @fclose($output);
                    @fclose($input);
                }

                $writtenbytes = @filesize($localpath);
                if (
                    $entrybytes !== $entry->size
                    || $writtenbytes === false
                    || $writtenbytes !== $entrybytes
                    || hash_final($crccontext) !== self::crc_to_hex($entry->crc)
                ) {
                    throw new moodle_exception('invalidzip', 'mod_photogallery');
                }

                self::verify_entry_descriptor($archive, $entry);
                $entry->localpath = $localpath;
                $entry->actualsize = $entrybytes;
            }
        } finally {
            @$archive->close();
        }

        return $entries;
    }

    /**
     * Checks the saved descriptor still identifies the same archive entry.
     *
     * @param \ZipArchive $archive Open archive.
     * @param stdClass $entry Saved entry descriptor.
     * @return void
     */
    private static function verify_entry_descriptor(
        \ZipArchive $archive,
        stdClass $entry
    ): void {
        $stat = $archive->statIndex($entry->index);
        if (
            $stat === false
            || !isset($stat['name'], $stat['size'], $stat['comp_size'], $stat['crc'])
            || (string) $stat['name'] !== $entry->archivename
            || (int) $stat['size'] !== $entry->size
            || (int) $stat['comp_size'] !== $entry->compressedsize
            || (int) $stat['crc'] !== $entry->crc
        ) {
            throw new moodle_exception('invalidzip', 'mod_photogallery');
        }
    }

    /**
     * Applies real-byte limits before an extracted chunk is written.
     *
     * @param stdClass $entry Entry being streamed.
     * @param int $entrybytes Prospective entry byte count.
     * @param int $actualbytes Prospective gallery byte count.
     * @param int $archivebytes Prospective selected archive byte count.
     * @param int $archivecompressedbytes Compressed bytes for entries opened so far.
     * @param array $options Gallery file limits.
     * @return void
     */
    private static function enforce_stream_limits(
        stdClass $entry,
        int $entrybytes,
        int $actualbytes,
        int $archivebytes,
        int $archivecompressedbytes,
        array $options
    ): void {
        if ($entrybytes > $entry->size) {
            throw new moodle_exception('invalidzip', 'mod_photogallery');
        }

        $maxbytes = (int) ($options['maxbytes'] ?? 0);
        if ($maxbytes > 0 && $entrybytes > $maxbytes) {
            throw new moodle_exception(
                'zipimagetoolarge',
                'mod_photogallery',
                '',
                $entry->filename
            );
        }

        self::enforce_area_bytes($actualbytes, $options);
        if (
            self::exceeds_compression_ratio(
                $entrybytes,
                $entry->compressedsize
            )
            || self::exceeds_compression_ratio(
                $archivebytes,
                $archivecompressedbytes
            )
        ) {
            throw new moodle_exception(
                'zipcompressionratio',
                'mod_photogallery',
                '',
                $entry->filename
            );
        }
    }

    /**
     * Writes a complete chunk, handling short writes.
     *
     * @param resource $stream Destination stream.
     * @param string $chunk Bytes to write.
     * @return void
     */
    private static function write_all($stream, string $chunk): void {
        $offset = 0;
        $length = strlen($chunk);

        while ($offset < $length) {
            $written = @fwrite($stream, substr($chunk, $offset));
            if ($written === false || $written === 0) {
                throw new moodle_exception('invalidzip', 'mod_photogallery');
            }
            $offset += $written;
        }
    }

    /**
     * Adds non-negative byte counts without allowing integer overflow.
     *
     * @param int $left First value.
     * @param int $right Second value.
     * @return int
     */
    private static function checked_add(int $left, int $right): int {
        if ($left < 0 || $right < 0 || $left > PHP_INT_MAX - $right) {
            throw new moodle_exception('invalidzip', 'mod_photogallery');
        }
        return $left + $right;
    }

    /**
     * Returns whether a non-negative byte pair exceeds the configured ratio.
     *
     * @param int $uncompressedbytes Uncompressed bytes.
     * @param int $compressedbytes Compressed bytes.
     * @return bool
     */
    private static function exceeds_compression_ratio(
        int $uncompressedbytes,
        int $compressedbytes
    ): bool {
        if ($uncompressedbytes <= 0) {
            return false;
        }
        if ($compressedbytes <= 0) {
            return true;
        }
        if (
            $compressedbytes
                > intdiv(PHP_INT_MAX, self::MAX_COMPRESSION_RATIO)
        ) {
            return false;
        }
        return $uncompressedbytes
            > $compressedbytes * self::MAX_COMPRESSION_RATIO;
    }

    /**
     * Formats a ZIP CRC32 value exactly like hash('crc32b').
     *
     * @param int $crc CRC from ZipArchive::statIndex().
     * @return string
     */
    private static function crc_to_hex(int $crc): string {
        return bin2hex(pack('N', $crc));
    }

    /**
     * Performs a bounded, streaming central-directory inspection.
     *
     * @param string $zippath Local ZIP pathname.
     * @param array $options Optional gallery file limits.
     * @param int $currentcount Existing image count.
     * @param int $currentbytes Existing image bytes.
     * @return stdClass[] Selected image entry descriptors.
     */
    private static function inspect_archive(
        string $zippath,
        array $options,
        int $currentcount = 0,
        int $currentbytes = 0
    ): array {
        if (!class_exists(\ZipArchive::class)) {
            throw new moodle_exception('invalidzip', 'mod_photogallery');
        }

        $archive = new \ZipArchive();
        if ($archive->open($zippath, \ZipArchive::CHECKCONS) !== true) {
            throw new moodle_exception('invalidzip', 'mod_photogallery');
        }

        try {
            $entrycount = $archive->numFiles;
            if ($entrycount > self::MAX_ARCHIVE_ENTRIES) {
                throw new moodle_exception(
                    'ziptoomanyentries',
                    'mod_photogallery',
                    '',
                    self::MAX_ARCHIVE_ENTRIES
                );
            }

            $selected = [];
            $normalisedpaths = [];
            $selectedbytes = $currentbytes;
            $selectedcompressedbytes = 0;

            for ($index = 0; $index < $entrycount; $index++) {
                $stat = $archive->statIndex($index);
                if (
                    $stat === false
                    || !isset(
                        $stat['name'],
                        $stat['size'],
                        $stat['comp_size'],
                        $stat['crc']
                    )
                ) {
                    throw new moodle_exception('invalidzip', 'mod_photogallery');
                }

                $pathname = self::normalise_and_validate_path((string) $stat['name']);
                $collisionkey = \core_text::strtolower(rtrim($pathname, '/'));
                if ($collisionkey === '' || isset($normalisedpaths[$collisionkey])) {
                    throw new moodle_exception(
                        'zipinvalidpath',
                        'mod_photogallery',
                        '',
                        clean_param((string) $stat['name'], PARAM_TEXT)
                    );
                }
                $normalisedpaths[$collisionkey] = true;

                if (str_ends_with($pathname, '/')) {
                    continue;
                }

                $extension = strtolower(pathinfo($pathname, PATHINFO_EXTENSION));
                if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                    continue;
                }

                $uncompressedsize = (int) $stat['size'];
                $compressedsize = (int) $stat['comp_size'];
                if ($uncompressedsize < 0 || $compressedsize < 0) {
                    throw new moodle_exception('invalidzip', 'mod_photogallery');
                }
                $maxbytes = (int) ($options['maxbytes'] ?? 0);
                if ($maxbytes > 0 && $uncompressedsize > $maxbytes) {
                    throw new moodle_exception(
                        'zipimagetoolarge',
                        'mod_photogallery',
                        '',
                        basename($pathname)
                    );
                }

                if (self::exceeds_compression_ratio($uncompressedsize, $compressedsize)) {
                    throw new moodle_exception(
                        'zipcompressionratio',
                        'mod_photogallery',
                        '',
                        basename($pathname)
                    );
                }

                $selectedbytes = self::checked_add(
                    $selectedbytes,
                    $uncompressedsize
                );
                $selectedcompressedbytes = self::checked_add(
                    $selectedcompressedbytes,
                    $compressedsize
                );
                self::enforce_area_bytes($selectedbytes, $options);

                $selected[] = (object) [
                    'index' => $index,
                    'archivename' => (string) $stat['name'],
                    'pathname' => $pathname,
                    'filename' => basename($pathname),
                    'size' => $uncompressedsize,
                    'compressedsize' => $compressedsize,
                    'crc' => (int) $stat['crc'],
                ];
            }

            $maxfiles = (int) ($options['maxfiles'] ?? 0);
            if ($maxfiles > 0 && $currentcount + count($selected) > $maxfiles) {
                throw new moodle_exception(
                    'toomanyimages',
                    'mod_photogallery',
                    '',
                    $maxfiles
                );
            }

            $selecteduncompressedbytes = $selectedbytes - $currentbytes;
            if (
                self::exceeds_compression_ratio(
                    $selecteduncompressedbytes,
                    $selectedcompressedbytes
                )
            ) {
                throw new moodle_exception(
                    'zipcompressionratio',
                    'mod_photogallery',
                    '',
                    get_string('importzip', 'mod_photogallery')
                );
            }

            return $selected;
        } finally {
            $archive->close();
        }
    }

    /**
     * Rejects unsafe paths and returns their canonical extraction spelling.
     *
     * @param string $pathname Raw pathname from ZipArchive.
     * @return string
     */
    private static function normalise_and_validate_path(string $pathname): string {
        $displaypath = clean_param($pathname, PARAM_TEXT);
        $pathname = str_replace('\\', '/', $pathname);

        if (
            $pathname === ''
            || strlen($pathname) > self::MAX_PATH_LENGTH
            || str_contains($pathname, "\0")
            || str_starts_with($pathname, '/')
            || preg_match('/^[a-zA-Z]:/', $pathname)
            || preg_match('~(^|/)\.{1,2}(/|$)~', $pathname)
            || str_contains($pathname, '//')
        ) {
            throw new moodle_exception(
                'zipinvalidpath',
                'mod_photogallery',
                '',
                $displaypath
            );
        }

        $isdirectory = str_ends_with($pathname, '/');
        $trimmedpath = rtrim($pathname, '/');
        $segments = explode('/', $trimmedpath);
        if (count($segments) > self::MAX_PATH_DEPTH) {
            throw new moodle_exception(
                'zipinvalidpath',
                'mod_photogallery',
                '',
                $displaypath
            );
        }

        foreach ($segments as $segment) {
            $basename = pathinfo($segment, PATHINFO_FILENAME);
            if (
                $segment === ''
                || strlen($segment) > self::MAX_PATH_SEGMENT_LENGTH
                || preg_match('/[<>:"|?*\x00-\x1F]/', $segment)
                || preg_match('/[ .]$/', $segment)
                || preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])$/i', $basename)
            ) {
                throw new moodle_exception(
                    'zipinvalidpath',
                    'mod_photogallery',
                    '',
                    $displaypath
                );
            }
        }

        $normalised = ltrim(clean_param($trimmedpath, PARAM_PATH), '/');
        if (function_exists('normalizer_normalize')) {
            $normalised = normalizer_normalize($normalised, \Normalizer::FORM_C);
        }

        if (!is_string($normalised) || $normalised === '' || $normalised !== $trimmedpath) {
            throw new moodle_exception(
                'zipinvalidpath',
                'mod_photogallery',
                '',
                $displaypath
            );
        }

        return $normalised . ($isdirectory ? '/' : '');
    }

    /**
     * Re-encodes temporary images before they reach a Moodle file area.
     *
     * @param stdClass[] $preparedfiles Validated extracted files.
     * @return stdClass[] Sanitised files with refreshed hashes and sizes.
     */
    private static function sanitise_prepared_files(array $preparedfiles): array {
        foreach ($preparedfiles as $index => $preparedfile) {
            $sanitisedpath = dirname($preparedfile->localpath)
                . DIRECTORY_SEPARATOR
                . '.sanitised-' . $index;
            image_validator::sanitize_pathname(
                $preparedfile->localpath,
                $preparedfile->filename,
                $sanitisedpath
            );
            $imageinfo = image_validator::validate_pathname(
                $sanitisedpath,
                $preparedfile->filename
            );
            $contenthash = sha1_file($sanitisedpath);
            if ($contenthash === false) {
                throw new moodle_exception('invalidzip', 'mod_photogallery');
            }
            $preparedfile->localpath = $sanitisedpath;
            $preparedfile->filesize = (int) filesize($sanitisedpath);
            $preparedfile->contenthash = $contenthash;
            $preparedfile->width = $imageinfo['width'];
            $preparedfile->height = $imageinfo['height'];
        }

        return $preparedfiles;
    }

    /**
     * Removes content already present in the destination and duplicates in one ZIP.
     *
     * @param stdClass[] $preparedfiles Sanitised extracted files.
     * @param stored_file[] $existingfiles Existing destination files.
     * @return stdClass[] New unique files.
     */
    private static function remove_duplicate_content(
        array $preparedfiles,
        array $existingfiles
    ): array {
        $knownhashes = [];
        foreach ($existingfiles as $existingfile) {
            if ($existingfile instanceof stored_file && !$existingfile->is_directory()) {
                $knownhashes[$existingfile->get_contenthash()] = true;
            }
        }

        return array_values(array_filter(
            $preparedfiles,
            static function (stdClass $preparedfile) use (&$knownhashes): bool {
                if (isset($knownhashes[$preparedfile->contenthash])) {
                    return false;
                }
                $knownhashes[$preparedfile->contenthash] = true;
                return true;
            }
        ));
    }

    /**
     * Enforces count and byte limits against the final post-deduplication set.
     *
     * @param stored_file[] $existingfiles Existing destination files.
     * @param stdClass[] $preparedfiles New sanitised files.
     * @param array $options Gallery file limits.
     * @return void
     */
    private static function enforce_final_limits(
        array $existingfiles,
        array $preparedfiles,
        array $options
    ): void {
        $existingcount = 0;
        $totalbytes = 0;

        foreach ($existingfiles as $existingfile) {
            if ($existingfile instanceof stored_file && !$existingfile->is_directory()) {
                $existingcount++;
                $totalbytes += $existingfile->get_filesize();
            }
        }

        $maxfiles = (int) ($options['maxfiles'] ?? 0);
        if ($maxfiles > 0 && $existingcount + count($preparedfiles) > $maxfiles) {
            throw new moodle_exception(
                'toomanyimages',
                'mod_photogallery',
                '',
                $maxfiles
            );
        }

        foreach ($preparedfiles as $preparedfile) {
            $maxbytes = (int) ($options['maxbytes'] ?? 0);
            if ($maxbytes > 0 && $preparedfile->filesize > $maxbytes) {
                throw new moodle_exception(
                    'zipimagetoolarge',
                    'mod_photogallery',
                    '',
                    $preparedfile->filename
                );
            }
            $totalbytes += $preparedfile->filesize;
        }

        self::enforce_area_bytes($totalbytes, $options);
    }

    /**
     * Enforces the synchronous decoded-pixel budget across old and new files.
     *
     * @param int $existingpixels Existing decoded pixels.
     * @param stdClass[] $preparedfiles New sanitised images.
     * @return void
     */
    private static function enforce_total_pixels(
        int $existingpixels,
        array $preparedfiles
    ): void {
        $totalpixels = $existingpixels;
        foreach ($preparedfiles as $preparedfile) {
            $totalpixels += $preparedfile->width * $preparedfile->height;
        }

        if ($totalpixels > image_validator::MAX_TOTAL_PIXELS) {
            throw new moodle_exception(
                'imagetotalpixelstoolarge',
                'mod_photogallery',
                '',
                (int) (image_validator::MAX_TOTAL_PIXELS / 1000000)
            );
        }
    }

    /**
     * Counts decoded pixels for existing legacy files without modifying them.
     *
     * @param stored_file[] $files Existing images and cover.
     * @return int
     */
    private static function get_existing_pixels(array $files): int {
        $pixels = 0;
        foreach ($files as $file) {
            if (!$file instanceof stored_file || $file->is_directory()) {
                continue;
            }

            $info = $file->get_imageinfo();
            if (is_array($info)) {
                $pixels += (int) ($info['width'] ?? 0) * (int) ($info['height'] ?? 0);
            }
        }
        return $pixels;
    }

    /**
     * Enforces the gallery byte limit.
     *
     * @param int $totalbytes Prospective total bytes.
     * @param array $options Gallery file limits.
     * @return void
     */
    private static function enforce_area_bytes(int $totalbytes, array $options): void {
        $areamaxbytes = (int) ($options['areamaxbytes'] ?? 0);
        if ($areamaxbytes > 0 && $totalbytes > $areamaxbytes) {
            throw new moodle_exception(
                'zipareatoolarge',
                'mod_photogallery',
                '',
                display_size($areamaxbytes)
            );
        }
    }

    /**
     * Returns the only ZIP file in a draft area.
     *
     * @param int $draftitemid Draft item ID.
     * @return stored_file|null
     */
    private static function get_zip_file(int $draftitemid): ?stored_file {
        $draftfiles = self::get_draft_files($draftitemid);
        if (empty($draftfiles)) {
            return null;
        }
        if (count($draftfiles) !== 1) {
            throw new moodle_exception('invalidzip', 'mod_photogallery');
        }

        $zipfile = reset($draftfiles);
        return $zipfile instanceof stored_file ? $zipfile : null;
    }

    /**
     * Finds a safe unused filename using the supplied existence callback.
     *
     * @param string $filename Requested filename.
     * @param callable $exists Receives a candidate and returns whether it exists.
     * @return string
     */
    private static function find_unique_filename(
        string $filename,
        callable $exists
    ): string {
        $filename = clean_param($filename, PARAM_FILE);
        if ($filename === '') {
            $filename = 'photograph.jpg';
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $candidate = $filename;
        $counter = 2;

        while ($exists($candidate)) {
            $suffix = $extension !== '' ? '.' . $extension : '';
            $numbersuffix = '-' . $counter;
            $availablebytes = self::MAX_PATH_SEGMENT_LENGTH
                - strlen($numbersuffix)
                - strlen($suffix);
            $truncatedbasename = $basename;
            while (
                strlen($truncatedbasename) > $availablebytes
                && \core_text::strlen($truncatedbasename) > 0
            ) {
                $truncatedbasename = \core_text::substr(
                    $truncatedbasename,
                    0,
                    \core_text::strlen($truncatedbasename) - 1
                );
            }
            if ($truncatedbasename === '') {
                $truncatedbasename = 'photograph';
            }
            $candidate = $truncatedbasename . $numbersuffix . $suffix;
            $counter++;
        }

        return $candidate;
    }
}
