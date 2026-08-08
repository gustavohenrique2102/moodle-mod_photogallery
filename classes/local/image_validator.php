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
 * Image validation and sanitisation for the Photo gallery activity.
 *
 * @package   mod_photogallery
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_photogallery\local;

use core\context\user as context_user;
use core\lock\lock_config;
use moodle_exception;
use stored_file;

/**
 * Validates raster images and removes embedded metadata before storage.
 */
final class image_validator {
    /** Prefix for the site-bound marker stored after privacy sanitisation. */
    public const SANITISED_SOURCE = 'mod_photogallery:sanitized:v1';

    /** Maximum dimension accepted on either axis. */
    public const MAX_DIMENSION = 10000;

    /** Maximum number of decoded pixels accepted in one image. */
    public const MAX_PIXELS = 25000000;

    /** Maximum decoded pixels processed synchronously in one submission. */
    public const MAX_TOTAL_PIXELS = 100000000;

    /** Supported extensions and their required MIME type. */
    private const FORMATS = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    /**
     * Validates all regular files in a user draft area without changing them.
     *
     * The recognised options are the standard filemanager maxfiles, maxbytes,
     * and areamaxbytes values. Missing or non-positive byte limits mean no
     * limit at that level.
     *
     * @param int $draftitemid Draft item ID, or zero when no draft exists.
     * @param array $options Filemanager limits.
     * @return void
     */
    public static function validate_draft_area(int $draftitemid, array $options): void {
        self::get_draft_stats($draftitemid, $options);
    }

    /**
     * Validates a draft area and returns aggregate resource usage.
     *
     * @param int $draftitemid Draft item ID, or zero when no draft exists.
     * @param array $options Filemanager limits.
     * @return array{count: int, bytes: int, pixels: int}
     */
    public static function get_draft_stats(int $draftitemid, array $options): array {
        return self::get_stored_files_stats(
            self::get_draft_files($draftitemid),
            $options
        );
    }

    /**
     * Validates a collection of stored images without changing them.
     *
     * @param stored_file[] $files Files to validate.
     * @param array $options Filemanager limits.
     * @return void
     */
    public static function validate_stored_files(array $files, array $options): void {
        self::get_stored_files_stats($files, $options);
    }

    /**
     * Validates stored images and returns aggregate resource usage.
     *
     * @param stored_file[] $files Files to validate.
     * @param array $options Filemanager limits.
     * @return array{count: int, bytes: int, pixels: int}
     */
    public static function get_stored_files_stats(array $files, array $options): array {
        $files = array_values(array_filter(
            $files,
            static fn($file): bool => $file instanceof stored_file && !$file->is_directory()
        ));

        $maxfiles = (int) ($options['maxfiles'] ?? 0);
        if ($maxfiles > 0 && count($files) > $maxfiles) {
            throw new moodle_exception(
                'toomanyimages',
                'mod_photogallery',
                '',
                $maxfiles
            );
        }

        $totalbytes = 0;
        $totalpixels = 0;
        $areamaxbytes = (int) ($options['areamaxbytes'] ?? 0);

        foreach ($files as $file) {
            $totalbytes += $file->get_filesize();
            if ($areamaxbytes > 0 && $totalbytes > $areamaxbytes) {
                throw new moodle_exception(
                    'galleryareatoolarge',
                    'mod_photogallery',
                    '',
                    display_size($areamaxbytes)
                );
            }

            $info = self::validate_stored_file($file, $options);
            $totalpixels += $info['width'] * $info['height'];
            if ($totalpixels > self::MAX_TOTAL_PIXELS) {
                throw new moodle_exception(
                    'imagetotalpixelstoolarge',
                    'mod_photogallery',
                    '',
                    (int) (self::MAX_TOTAL_PIXELS / 1000000)
                );
            }
        }

        return [
            'count' => count($files),
            'bytes' => $totalbytes,
            'pixels' => $totalpixels,
        ];
    }

    /**
     * Validates one stored image without changing it.
     *
     * @param stored_file $file Image file.
     * @param array $options Filemanager limits.
     * @param bool $zipcontext Whether to use the legacy ZIP-specific errors.
     * @return array Validated image information.
     */
    public static function validate_stored_file(
        stored_file $file,
        array $options = [],
        bool $zipcontext = false
    ): array {
        $temporarypath = $file->copy_content_to_temp(
            'mod_photogallery',
            'imagecheck_'
        );

        if (!$temporarypath) {
            self::throw_invalid_image($file->get_filename(), $zipcontext);
        }

        try {
            return self::validate_pathname(
                $temporarypath,
                $file->get_filename(),
                (int) ($options['maxbytes'] ?? 0),
                $zipcontext
            );
        } finally {
            @unlink($temporarypath);
        }
    }

    /**
     * Validates an image stored on the local filesystem.
     *
     * @param string $pathname Absolute pathname.
     * @param string $filename User-facing filename used for extension checks and errors.
     * @param int $maxbytes Maximum file size, or zero for unlimited.
     * @param bool $zipcontext Whether to use the legacy ZIP-specific errors.
     * @return array Image information containing width, height, MIME type and extension.
     */
    public static function validate_pathname(
        string $pathname,
        string $filename,
        int $maxbytes = 0,
        bool $zipcontext = false
    ): array {
        $filesize = @filesize($pathname);
        if ($filesize === false || $filesize <= 0) {
            self::throw_invalid_image($filename, $zipcontext);
        }

        if ($maxbytes > 0 && $filesize > $maxbytes) {
            throw new moodle_exception(
                $zipcontext ? 'zipimagetoolarge' : 'imagetoolarge',
                'mod_photogallery',
                '',
                $filename
            );
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $expectedmime = self::FORMATS[$extension] ?? null;
        if ($expectedmime === null || !self::has_expected_signature($pathname, $expectedmime)) {
            self::throw_invalid_image($filename, $zipcontext);
        }
        self::require_runtime_support($expectedmime, $filename);

        $imageinfo = @getimagesize($pathname);
        if ($imageinfo === false || ($imageinfo['mime'] ?? '') !== $expectedmime) {
            self::throw_invalid_image($filename, $zipcontext);
        }

        $width = (int) ($imageinfo[0] ?? 0);
        $height = (int) ($imageinfo[1] ?? 0);
        if (
            $width <= 0
            || $height <= 0
            || $width > self::MAX_DIMENSION
            || $height > self::MAX_DIMENSION
            || $width > intdiv(self::MAX_PIXELS, $height)
        ) {
            throw new moodle_exception(
                'imagedimensionstoolarge',
                'mod_photogallery',
                '',
                (object) [
                    'filename' => $filename,
                    'maxdimension' => self::MAX_DIMENSION,
                    'maxmegapixels' => (int) (self::MAX_PIXELS / 1000000),
                ]
            );
        }

        if (
            ($expectedmime === 'image/png' && !self::is_static_png($pathname))
            || ($expectedmime === 'image/webp' && !self::is_static_webp($pathname))
        ) {
            throw new moodle_exception(
                'animatedimagenotsupported',
                'mod_photogallery',
                '',
                $filename
            );
        }

        return [
            'width' => $width,
            'height' => $height,
            'mimetype' => $expectedmime,
            'extension' => $extension,
            'filesize' => $filesize,
        ];
    }

    /**
     * Re-encodes every image in a draft area, applying EXIF orientation and
     * discarding EXIF, GPS, IPTC, XMP, comments, and animation metadata.
     *
     * @param int $draftitemid Draft item ID, or zero when no draft exists.
     * @param array $options Filemanager limits.
     * @return array<int, array{filepath: string, filename: string,
     *     oldcontenthash: string, newcontenthash: string}> Content-hash mappings.
     */
    public static function sanitize_draft_area(
        int $draftitemid,
        array $options
    ): array {
        global $USER;

        if ($draftitemid <= 0) {
            return [];
        }

        $lockfactory = lock_config::get_lock_factory('mod_photogallery_image_sanitise');
        $lock = $lockfactory->get_lock($USER->id . ':' . $draftitemid, 10);
        if (!$lock) {
            throw new moodle_exception('invalidzip', 'mod_photogallery');
        }

        $temporarydirectory = null;
        $temporarydraftid = 0;

        try {
            $files = self::get_draft_files($draftitemid);
            self::validate_stored_files($files, $options);

            if (empty($files)) {
                return [];
            }

            $temporarydirectory = make_temp_directory(
                'mod_photogallery/' . random_string(20)
            );
            $prepared = [];
            $totalbytes = 0;
            foreach ($files as $file) {
                if (self::is_sanitised($file)) {
                    $totalbytes += $file->get_filesize();
                    continue;
                }

                $sourcepath = $file->copy_content_to_temp(
                    'mod_photogallery',
                    'imagesource_'
                );
                if (!$sourcepath) {
                    self::throw_invalid_image($file->get_filename());
                }

                $destination = $temporarydirectory . DIRECTORY_SEPARATOR . $file->get_id();
                try {
                    self::sanitize_pathname(
                        $sourcepath,
                        $file->get_filename(),
                        $destination
                    );
                } finally {
                    @unlink($sourcepath);
                }

                $sanitisedsize = (int) filesize($destination);
                $maxbytes = (int) ($options['maxbytes'] ?? 0);
                if ($maxbytes > 0 && $sanitisedsize > $maxbytes) {
                    throw new moodle_exception(
                        'imagetoolarge',
                        'mod_photogallery',
                        '',
                        $file->get_filename()
                    );
                }

                $totalbytes += $sanitisedsize;
                $areamaxbytes = (int) ($options['areamaxbytes'] ?? 0);
                if ($areamaxbytes > 0 && $totalbytes > $areamaxbytes) {
                    throw new moodle_exception(
                        'galleryareatoolarge',
                        'mod_photogallery',
                        '',
                        display_size($areamaxbytes)
                    );
                }

                $prepared[] = [$file, $destination];
            }

            if (empty($prepared)) {
                return [];
            }

            $temporarydraftid = file_get_unused_draft_itemid();
            $usercontext = context_user::instance($USER->id, MUST_EXIST);
            $filestorage = get_file_storage();

            $mappings = [];
            foreach ($prepared as [$file, $pathname]) {
                $oldcontenthash = $file->get_contenthash();
                $replacement = $filestorage->create_file_from_pathname(
                    [
                        'contextid' => $usercontext->id,
                        'component' => 'user',
                        'filearea' => 'draft',
                        'itemid' => $temporarydraftid,
                        'filepath' => '/',
                        'filename' => $file->get_id() . '.tmp',
                        'userid' => $USER->id,
                    ],
                    $pathname
                );

                $file->replace_file_with($replacement);
                $file->set_timemodified(time());
                self::mark_sanitised($file);
                $mappings[] = [
                    'filepath' => $file->get_filepath(),
                    'filename' => $file->get_filename(),
                    'oldcontenthash' => $oldcontenthash,
                    'newcontenthash' => $file->get_contenthash(),
                ];
            }

            return $mappings;
        } finally {
            if ($temporarydraftid > 0) {
                get_file_storage()->delete_area_files(
                    context_user::instance($USER->id)->id,
                    'user',
                    'draft',
                    $temporarydraftid
                );
            }
            if ($temporarydirectory !== null) {
                fulldelete($temporarydirectory);
            }
            $lock->release();
        }
    }

    /**
     * Writes a sanitised copy of an image to a local pathname.
     *
     * @param string $sourcepath Source image pathname.
     * @param string $filename User-facing filename.
     * @param string $destinationpath Destination pathname.
     * @return void
     */
    public static function sanitize_pathname(
        string $sourcepath,
        string $filename,
        string $destinationpath
    ): void {
        $info = self::validate_pathname($sourcepath, $filename);
        $content = @file_get_contents($sourcepath);
        $image = $content === false ? false : @imagecreatefromstring($content);
        unset($content);

        if ($image === false) {
            self::throw_invalid_image($filename);
        }

        try {
            if ($info['mimetype'] === 'image/jpeg') {
                $orientation = self::read_jpeg_orientation($sourcepath);
                $image = self::apply_orientation($image, $orientation);
            }

            $written = match ($info['mimetype']) {
                'image/jpeg' => function_exists('imagejpeg')
                    && @imagejpeg($image, $destinationpath, 90),
                'image/png' => function_exists('imagepng')
                    && @imagepng($image, $destinationpath, 6, PNG_ALL_FILTERS),
                'image/webp' => function_exists('imagewebp')
                    && @imagewebp($image, $destinationpath, 85),
                default => false,
            };

            if (!$written) {
                self::throw_invalid_image($filename);
            }
        } finally {
            imagedestroy($image);
        }

        self::validate_pathname($destinationpath, $filename);
    }

    /**
     * Sanitises one permanent stored image without changing its pathname.
     *
     * The original is replaced only after a complete re-encoded file has been
     * created in the Moodle file pool. Unsupported legacy formats are rejected
     * without deleting or modifying their source file.
     *
     * @param stored_file $file Stored source image.
     * @return array{filepath: string, filename: string, oldcontenthash: string,
     *     newcontenthash: string}|null Hash mapping, or null when already clean.
     */
    public static function sanitize_stored_file(stored_file $file): ?array {
        if (self::is_sanitised($file)) {
            return null;
        }

        $lockfactory = lock_config::get_lock_factory('mod_photogallery_file_sanitise');
        $lock = $lockfactory->get_lock((string) $file->get_id(), 10);
        if (!$lock) {
            throw new moodle_exception('invalidimage', 'mod_photogallery', '', $file->get_filename());
        }

        $sourcepath = null;
        $temporarydirectory = null;
        $replacement = null;

        try {
            if (self::is_sanitised($file)) {
                return null;
            }

            $originalcontenthash = $file->get_contenthash();

            $sourcepath = $file->copy_content_to_temp(
                'mod_photogallery',
                'imagesource_'
            );
            if (!$sourcepath) {
                self::throw_invalid_image($file->get_filename());
            }

            $temporarydirectory = make_temp_directory(
                'mod_photogallery/' . random_string(20)
            );
            $destinationpath = $temporarydirectory . DIRECTORY_SEPARATOR . 'sanitised';
            self::sanitize_pathname(
                $sourcepath,
                $file->get_filename(),
                $destinationpath
            );

            $replacement = get_file_storage()->create_file_from_pathname(
                [
                    'contextid' => $file->get_contextid(),
                    'component' => 'mod_photogallery',
                    'filearea' => 'sanitise_staging',
                    'itemid' => $file->get_id(),
                    'filepath' => '/',
                    'filename' => $file->get_id() . '-' . random_string(12),
                    'userid' => $file->get_userid(),
                ],
                $destinationpath
            );

            // The source may have been replaced while it was being decoded.
            // Never write the prepared bytes over a different photograph.
            $currentfile = get_file_storage()->get_file_by_id($file->get_id());
            if (
                !$currentfile instanceof stored_file
                || !hash_equals(
                    $originalcontenthash,
                    $currentfile->get_contenthash()
                )
            ) {
                throw new moodle_exception(
                    'mediaconflict',
                    'mod_photogallery'
                );
            }

            $currentfile->replace_file_with($replacement);
            $currentfile->set_timemodified(time());
            self::mark_sanitised($currentfile);

            return [
                'filepath' => $currentfile->get_filepath(),
                'filename' => $currentfile->get_filename(),
                'oldcontenthash' => $originalcontenthash,
                'newcontenthash' => $currentfile->get_contenthash(),
            ];
        } finally {
            if ($replacement instanceof stored_file) {
                $replacement->delete();
            }
            get_file_storage()->delete_area_files(
                $file->get_contextid(),
                'mod_photogallery',
                'sanitise_staging',
                $file->get_id()
            );
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
     * Returns whether a stored file carries the current sanitisation marker.
     *
     * @param stored_file $file Stored file.
     * @return bool
     */
    public static function is_sanitised(stored_file $file): bool {
        $source = $file->get_source();
        $expectedmarker = self::get_sanitised_marker($file);

        if (!is_string($source)) {
            return false;
        }

        if (hash_equals($expectedmarker, $source)) {
            return true;
        }

        if (!str_starts_with($source, 'O:8:"stdClass":')) {
            return false;
        }

        $draftsource = unserialize_object($source);
        return is_string($draftsource->source ?? null)
            && hash_equals($expectedmarker, $draftsource->source);
    }

    /**
     * Marks a draft or permanent file as sanitised without losing draft origin metadata.
     *
     * @param stored_file $file Stored file.
     * @return void
     */
    public static function mark_sanitised(stored_file $file): void {
        $source = $file->get_source();
        $marker = self::get_sanitised_marker($file);

        if (
            $file->get_component() === 'user'
            && $file->get_filearea() === 'draft'
        ) {
            $draftsource = is_string($source)
                && str_starts_with($source, 'O:8:"stdClass":')
                ? unserialize_object($source)
                : new \stdClass();
            $draftsource->source = $marker;
            $file->set_source(serialize($draftsource));
            return;
        }

        $file->set_source($marker);
    }

    /**
     * Builds a marker bound to both this Moodle site and the exact file bytes.
     *
     * This prevents repository metadata or a crafted backup from asserting
     * that untrusted content was already re-encoded by this site.
     *
     * @param stored_file $file Stored file.
     * @return string Authenticated sanitisation marker.
     */
    private static function get_sanitised_marker(stored_file $file): string {
        return self::SANITISED_SOURCE . ':' . hash_hmac(
            'sha256',
            $file->get_contenthash(),
            get_site_identifier()
        );
    }

    /**
     * Returns all regular files from a user draft area.
     *
     * @param int $draftitemid Draft item ID.
     * @return stored_file[]
     */
    private static function get_draft_files(int $draftitemid): array {
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
     * Verifies the format's identifying bytes.
     *
     * @param string $pathname Image pathname.
     * @param string $mimetype Expected MIME type.
     * @return bool
     */
    private static function has_expected_signature(string $pathname, string $mimetype): bool {
        $handle = @fopen($pathname, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            $header = fread($handle, 12);
        } finally {
            fclose($handle);
        }

        return match ($mimetype) {
            'image/jpeg' => str_starts_with($header, "\xFF\xD8\xFF"),
            'image/png' => str_starts_with($header, "\x89PNG\r\n\x1A\n"),
            'image/webp' => substr($header, 0, 4) === 'RIFF'
                && substr($header, 8, 4) === 'WEBP',
            default => false,
        };
    }

    /**
     * Ensures the server can decode and re-encode an accepted image format.
     *
     * @param string $mimetype Validated MIME type.
     * @param string $filename Filename shown to the user.
     * @return void
     */
    private static function require_runtime_support(string $mimetype, string $filename): void {
        $supportedtypes = function_exists('imagetypes') ? imagetypes() : 0;
        $supported = match ($mimetype) {
            'image/jpeg' => function_exists('imagecreatefromstring')
                && function_exists('imagejpeg')
                && ($supportedtypes & IMG_JPG),
            'image/png' => function_exists('imagecreatefromstring')
                && function_exists('imagepng')
                && ($supportedtypes & IMG_PNG),
            'image/webp' => function_exists('imagecreatefromstring')
                && function_exists('imagewebp')
                && defined('IMG_WEBP')
                && ($supportedtypes & IMG_WEBP),
            default => false,
        };

        if (!$supported) {
            throw new moodle_exception(
                'imageprocessingunavailable',
                'mod_photogallery',
                '',
                $filename
            );
        }
    }

    /**
     * Reads JPEG orientation using ext-exif or a bounded APP1/TIFF parser.
     *
     * @param string $pathname JPEG pathname.
     * @return int EXIF orientation from 1 to 8.
     */
    private static function read_jpeg_orientation(string $pathname): int {
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($pathname, 'IFD0', true, false);
            if (is_array($exif)) {
                $orientation = (int) ($exif['IFD0']['Orientation'] ?? $exif['Orientation'] ?? 1);
                if ($orientation >= 1 && $orientation <= 8) {
                    return $orientation;
                }
            }
        }

        $handle = @fopen($pathname, 'rb');
        if ($handle === false || fread($handle, 2) !== "\xFF\xD8") {
            if (is_resource($handle)) {
                fclose($handle);
            }
            return 1;
        }

        try {
            while (!feof($handle)) {
                $prefix = fread($handle, 1);
                if ($prefix !== "\xFF") {
                    continue;
                }

                do {
                    $marker = fread($handle, 1);
                } while ($marker === "\xFF");

                if ($marker === '' || $marker === "\xDA" || $marker === "\xD9") {
                    break;
                }
                if (ord($marker) >= 0xD0 && ord($marker) <= 0xD7) {
                    continue;
                }

                $lengthbytes = fread($handle, 2);
                if (strlen($lengthbytes) !== 2) {
                    break;
                }
                $segmentlength = unpack('nlength', $lengthbytes)['length'];
                if ($segmentlength < 2 || $segmentlength > 65535) {
                    break;
                }

                $segment = fread($handle, $segmentlength - 2);
                if ($marker === "\xE1" && str_starts_with($segment, "Exif\0\0")) {
                    $orientation = self::parse_tiff_orientation(substr($segment, 6));
                    if ($orientation !== null) {
                        return $orientation;
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        return 1;
    }

    /**
     * Extracts orientation tag 0x0112 from a TIFF IFD0 payload.
     *
     * @param string $tiff TIFF payload.
     * @return int|null
     */
    private static function parse_tiff_orientation(string $tiff): ?int {
        if (strlen($tiff) < 8) {
            return null;
        }

        $byteorder = substr($tiff, 0, 2);
        $littleendian = $byteorder === 'II';
        if (!$littleendian && $byteorder !== 'MM') {
            return null;
        }

        $readshort = static fn(string $data): int => unpack(
            $littleendian ? 'vvalue' : 'nvalue',
            $data
        )['value'];
        $readlong = static fn(string $data): int => unpack(
            $littleendian ? 'Vvalue' : 'Nvalue',
            $data
        )['value'];

        if ($readshort(substr($tiff, 2, 2)) !== 42) {
            return null;
        }
        $ifdoffset = $readlong(substr($tiff, 4, 4));
        if ($ifdoffset < 8 || $ifdoffset + 2 > strlen($tiff)) {
            return null;
        }

        $entrycount = $readshort(substr($tiff, $ifdoffset, 2));
        $entryoffset = $ifdoffset + 2;
        for ($index = 0; $index < $entrycount; $index++) {
            $offset = $entryoffset + ($index * 12);
            if ($offset + 12 > strlen($tiff)) {
                return null;
            }

            $tag = $readshort(substr($tiff, $offset, 2));
            if ($tag !== 0x0112) {
                continue;
            }

            $type = $readshort(substr($tiff, $offset + 2, 2));
            $count = $readlong(substr($tiff, $offset + 4, 4));
            if ($type !== 3 || $count !== 1) {
                return null;
            }

            $orientation = $readshort(substr($tiff, $offset + 8, 2));
            return $orientation >= 1 && $orientation <= 8 ? $orientation : null;
        }

        return null;
    }

    /**
     * Ensures a PNG is structurally complete and not animated.
     *
     * @param string $pathname PNG pathname.
     * @return bool
     */
    private static function is_static_png(string $pathname): bool {
        $data = @file_get_contents($pathname);
        if ($data === false || !str_starts_with($data, "\x89PNG\r\n\x1A\n")) {
            return false;
        }

        $offset = 8;
        $length = strlen($data);
        $foundend = false;

        while ($offset + 12 <= $length) {
            $chunklength = unpack('Nlength', substr($data, $offset, 4))['length'];
            $type = substr($data, $offset + 4, 4);
            $end = $offset + 12 + $chunklength;
            if ($end > $length) {
                return false;
            }

            $chunkdata = substr($data, $offset + 8, $chunklength);
            $expectedcrc = substr($data, $offset + 8 + $chunklength, 4);
            $actualcrc = pack('N', crc32($type . $chunkdata));
            if (!hash_equals($expectedcrc, $actualcrc) || $type === 'acTL') {
                return false;
            }

            $offset = $end;
            if ($type === 'IEND') {
                $foundend = true;
                break;
            }
        }

        return $foundend;
    }

    /**
     * Ensures a WebP RIFF container is complete and not animated.
     *
     * @param string $pathname WebP pathname.
     * @return bool
     */
    private static function is_static_webp(string $pathname): bool {
        $data = @file_get_contents($pathname);
        if (
            $data === false
            || strlen($data) < 20
            || substr($data, 0, 4) !== 'RIFF'
            || substr($data, 8, 4) !== 'WEBP'
        ) {
            return false;
        }

        $declaredlength = unpack('Vlength', substr($data, 4, 4))['length'] + 8;
        $length = strlen($data);
        if ($declaredlength !== $length) {
            return false;
        }

        $offset = 12;
        while ($offset + 8 <= $length) {
            $type = substr($data, $offset, 4);
            $chunksize = unpack('Vlength', substr($data, $offset + 4, 4))['length'];
            $offset += 8;
            if ($offset + $chunksize > $length || $type === 'ANIM' || $type === 'ANMF') {
                return false;
            }
            $offset += $chunksize + ($chunksize % 2);
        }

        return $offset === $length;
    }

    /**
     * Applies all eight EXIF orientation values to a GD image.
     *
     * @param \GdImage $image Image resource.
     * @param int $orientation EXIF orientation.
     * @return \GdImage Oriented image.
     */
    private static function apply_orientation(\GdImage $image, int $orientation): \GdImage {
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            $flipmode = in_array($orientation, [2, 5], true)
                ? IMG_FLIP_HORIZONTAL
                : IMG_FLIP_VERTICAL;
            imageflip($image, $flipmode);
        }

        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) {
            return $image;
        }

        imagedestroy($image);
        return $rotated;
    }

    /**
     * Throws the plugin's existing invalid-image exception.
     *
     * @param string $filename Filename shown to the user.
     * @param bool $zipcontext Whether the call is made from ZIP validation.
     * @return never
     */
    private static function throw_invalid_image(
        string $filename,
        bool $zipcontext = false
    ): never {
        throw new moodle_exception(
            $zipcontext ? 'invalidzipimage' : 'invalidimage',
            'mod_photogallery',
            '',
            $filename
        );
    }
}
