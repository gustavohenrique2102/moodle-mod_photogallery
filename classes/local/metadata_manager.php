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
use core\lock\lock_config;
use mod_photogallery\event\image_metadata_updated;
use stored_file;

/**
 * Reads, reconciles and safely updates gallery image metadata.
 */
final class metadata_manager {
    /** Maximum caption length stored by the plugin. */
    public const CAPTION_MAX_LENGTH = 500;

    /** Maximum alternative text length stored by the plugin. */
    public const ALTTEXT_MAX_LENGTH = 1000;

    /** Lock wait time for concurrent metadata operations. */
    private const LOCK_TIMEOUT = 10;

    /**
     * Returns gallery metadata indexed by pathname hash.
     *
     * @param int $photogalleryid Gallery instance ID.
     * @return \stdClass[]
     */
    public static function get_by_pathnamehash(int $photogalleryid): array {
        global $DB;

        $records = $DB->get_records(
            'photogallery_image',
            ['photogalleryid' => $photogalleryid],
            '',
            'id, photogalleryid, pathnamehash, contenthash, caption, alttext, sortorder, timecreated, timemodified'
        );

        $recordsbyhash = [];
        foreach ($records as $record) {
            $recordsbyhash[$record->pathnamehash] = $record;
        }

        return $recordsbyhash;
    }

    /**
     * Returns a revision token for optimistic locking.
     *
     * The token includes both the source files and all metadata values. This
     * makes same-second writes detectable and also rejects a stale form if a
     * photograph was renamed, replaced, added or removed while it was open.
     *
     * @param int $photogalleryid Gallery instance ID.
     * @param context_module $context Activity context.
     * @return string SHA-256 revision token.
     */
    public static function get_revision(int $photogalleryid, context_module $context): string {
        global $DB;

        $gallery = $DB->get_record(
            'photogallery',
            ['id' => $photogalleryid],
            'id, timemodified',
            MUST_EXIST
        );

        $records = array_values($DB->get_records(
            'photogallery_image',
            ['photogalleryid' => $photogalleryid],
            'id ASC',
            'id, pathnamehash, contenthash, caption, alttext, sortorder, timecreated, timemodified'
        ));

        $files = [];
        foreach (self::get_source_files($context) as $file) {
            $files[] = [
                'id' => $file->get_id(),
                'filearea' => $file->get_filearea(),
                'pathnamehash' => $file->get_pathnamehash(),
                'contenthash' => $file->get_contenthash(),
                'timemodified' => $file->get_timemodified(),
            ];
        }

        $state = [
            'gallerytimemodified' => (int) $gallery->timemodified,
            'records' => $records,
            'files' => $files,
        ];

        return hash('sha256', serialize($state));
    }

    /**
     * Reconciles metadata with the current source files.
     *
     * Metadata follows a rename only when the content hash identifies one
     * source and one previous record unambiguously. Replacing the contents at
     * the same path intentionally creates a blank metadata record instead of
     * leaking the previous photograph's caption or alternative text.
     *
     * @param int $photogalleryid Gallery instance ID.
     * @param context_module $context Activity context.
     * @param bool $createmissing Create blank records for photographs without metadata.
     * @return int Number of orphaned or replaced metadata records removed.
     */
    public static function reconcile_files(
        int $photogalleryid,
        context_module $context,
        bool $createmissing = false
    ): int {
        global $DB;

        $lock = self::acquire_lock($photogalleryid);

        try {
            $transaction = $DB->start_delegated_transaction();
            $files = self::get_source_files($context);
            $records = self::get_by_pathnamehash($photogalleryid);

            $matches = [];
            $unmatchedfiles = [];
            $unmatchedrecords = $records;

            // Prefer the exact path when it still represents the same content.
            foreach ($files as $file) {
                $pathnamehash = $file->get_pathnamehash();
                $record = $records[$pathnamehash] ?? null;

                if (
                    $record
                    && (
                        $record->contenthash === ''
                        || hash_equals((string) $record->contenthash, $file->get_contenthash())
                    )
                ) {
                    $matches[$file->get_id()] = $record;
                    unset($unmatchedrecords[$record->pathnamehash]);
                    continue;
                }

                $unmatchedfiles[$file->get_id()] = $file;
            }

            // A unique content match represents a rename or move between file areas.
            $filesbycontent = [];
            foreach ($unmatchedfiles as $file) {
                $filesbycontent[$file->get_contenthash()][] = $file;
            }

            $recordsbycontent = [];
            foreach ($unmatchedrecords as $record) {
                if ($record->contenthash !== '') {
                    $recordsbycontent[$record->contenthash][] = $record;
                }
            }

            foreach ($filesbycontent as $contenthash => $contentfiles) {
                $contentrecords = $recordsbycontent[$contenthash] ?? [];
                if (count($contentfiles) !== 1 || count($contentrecords) !== 1) {
                    continue;
                }

                $file = reset($contentfiles);
                $record = reset($contentrecords);
                $matches[$file->get_id()] = $record;
                unset($unmatchedfiles[$file->get_id()]);
                unset($unmatchedrecords[$record->pathnamehash]);
            }

            $removed = count($unmatchedrecords);
            foreach ($unmatchedrecords as $record) {
                $DB->delete_records('photogallery_image', ['id' => $record->id]);
            }

            // Temporarily free unique pathname hashes before applying renamed paths.
            foreach ($files as $file) {
                $record = $matches[$file->get_id()] ?? null;
                if (!$record || $record->pathnamehash === $file->get_pathnamehash()) {
                    continue;
                }

                $temporaryhash = sha1(
                    'mod_photogallery:' . $photogalleryid . ':' . $record->id . ':' . $file->get_id()
                );
                $DB->set_field('photogallery_image', 'pathnamehash', $temporaryhash, ['id' => $record->id]);
                $record->pathnamehash = $temporaryhash;
            }

            $now = time();
            $nextsortorder = self::get_next_sortorder($matches);

            foreach ($files as $file) {
                $record = $matches[$file->get_id()] ?? null;
                if ($record) {
                    $changed = $record->pathnamehash !== $file->get_pathnamehash()
                        || $record->contenthash !== $file->get_contenthash();

                    if ($changed) {
                        $record->pathnamehash = $file->get_pathnamehash();
                        $record->contenthash = $file->get_contenthash();
                        $record->timemodified = $now;
                        $DB->update_record('photogallery_image', $record);
                    }
                    continue;
                }

                if (!$createmissing) {
                    continue;
                }

                $DB->insert_record('photogallery_image', (object) [
                    'photogalleryid' => $photogalleryid,
                    'pathnamehash' => $file->get_pathnamehash(),
                    'contenthash' => $file->get_contenthash(),
                    'caption' => '',
                    'alttext' => '',
                    'sortorder' => $nextsortorder++,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
            }

            $transaction->allow_commit();
            return $removed;
        } finally {
            $lock->release();
        }
    }

    /**
     * Saves all metadata and an optional order change using optimistic locking.
     *
     * @param int $photogalleryid Gallery instance ID.
     * @param context_module $context Activity context.
     * @param stored_file[] $images Images in their displayed order.
     * @param \stdClass $data Submitted form data.
     * @param string $expectedrevision Revision rendered with the form.
     * @return bool Whether the image order changed.
     */
    public static function save(
        int $photogalleryid,
        context_module $context,
        array $images,
        \stdClass $data,
        string $expectedrevision
    ): bool {
        global $DB;

        self::validate_submitted_metadata($images, $data);
        $lock = self::acquire_lock($photogalleryid);

        try {
            $currentrevision = self::get_revision($photogalleryid, $context);
            if (!hash_equals($currentrevision, $expectedrevision)) {
                throw new \moodle_exception('metadataconflict', 'mod_photogallery');
            }

            $transaction = $DB->start_delegated_transaction();
            $recordsbyhash = self::get_by_pathnamehash($photogalleryid);
            $savedrecords = [];
            $now = time();

            foreach ($images as $index => $file) {
                $pathnamehash = $file->get_pathnamehash();
                $record = $recordsbyhash[$pathnamehash] ?? null;

                if (!$record || !hash_equals((string) $record->contenthash, $file->get_contenthash())) {
                    throw new \moodle_exception('metadataconflict', 'mod_photogallery');
                }

                $record->caption = trim((string) ($data->{'caption_' . $index} ?? ''));
                $record->alttext = trim((string) ($data->{'alttext_' . $index} ?? ''));
                $record->sortorder = $index;
                $record->timemodified = $now;
                $DB->update_record('photogallery_image', $record);
                $savedrecords[$pathnamehash] = $record;
            }

            $orderchanged = self::apply_order_change($images, $data, $savedrecords);

            $gallery = $DB->get_record('photogallery', ['id' => $photogalleryid], '*', MUST_EXIST);
            $gallery->timemodified = $now;
            $DB->update_record('photogallery', $gallery);

            $event = image_metadata_updated::create([
                'objectid' => $photogalleryid,
                'context' => $context,
                'other' => [
                    'imagecount' => count($images),
                    'orderchanged' => $orderchanged,
                ],
            ]);
            $event->trigger();

            $transaction->allow_commit();
            return $orderchanged;
        } finally {
            $lock->release();
        }
    }

    /**
     * Removes metadata records whose source files no longer exist.
     *
     * @param int $photogalleryid Gallery instance ID.
     * @param context_module $context Activity context.
     * @return int Number of metadata records removed.
     */
    public static function cleanup_orphans(int $photogalleryid, context_module $context): int {
        return self::reconcile_files($photogalleryid, $context);
    }

    /**
     * Makes metadata ordering follow the order saved by the Moodle file manager.
     *
     * This is called after the activity form saves its draft area. Changes made
     * in the metadata editor flow in the opposite direction by updating each
     * stored file's sort order in {@see self::apply_order_change()}.
     *
     * @param int $photogalleryid Gallery instance ID.
     * @param context_module $context Activity context.
     */
    public static function sync_sortorder_from_files(
        int $photogalleryid,
        context_module $context
    ): void {
        global $DB;

        self::reconcile_files($photogalleryid, $context, true);
        $lock = self::acquire_lock($photogalleryid);

        try {
            $transaction = $DB->start_delegated_transaction();
            $records = self::get_by_pathnamehash($photogalleryid);

            foreach (self::get_source_files($context) as $index => $file) {
                $record = $records[$file->get_pathnamehash()] ?? null;
                if ($record && (int) $record->sortorder !== $index) {
                    $DB->set_field('photogallery_image', 'sortorder', $index, ['id' => $record->id]);
                }
            }

            $transaction->allow_commit();
        } finally {
            $lock->release();
        }
    }

    /**
     * Adopts content hashes produced by the trusted image sanitisation step.
     *
     * Generic replacements must go through {@see self::reconcile_files()} and
     * intentionally lose the previous metadata. This narrowly scoped method is
     * only for paths explicitly reported as re-encoded by the media pipeline.
     * Every old hash is checked against the current metadata record and every
     * new hash against the corresponding permanent stored file.
     *
     * @param int $photogalleryid Gallery instance ID.
     * @param context_module $context Activity context.
     * @param string $filearea Permanent source file area (`images` or `cover`).
     * @param array $mappings Sanitiser mappings with filepath, filename, oldcontenthash and newcontenthash.
     * @return int Number of existing metadata records adopted.
     */
    public static function adopt_sanitized_content(
        int $photogalleryid,
        context_module $context,
        string $filearea,
        array $mappings
    ): int {
        global $DB;

        if (!in_array($filearea, ['images', 'cover'], true)) {
            throw new \core\exception\invalid_parameter_exception('Invalid photograph file area.');
        }
        if (empty($mappings)) {
            return 0;
        }

        $lock = self::acquire_lock($photogalleryid);

        try {
            $files = [];
            $areafiles = get_file_storage()->get_area_files(
                $context->id,
                'mod_photogallery',
                $filearea,
                0,
                'id ASC',
                false
            );
            foreach ($areafiles as $file) {
                $files[$file->get_filepath() . $file->get_filename()] = $file;
            }
            $records = self::get_by_pathnamehash($photogalleryid);
            $transaction = $DB->start_delegated_transaction();
            $now = time();
            $adopted = 0;
            $processedpaths = [];

            foreach ($mappings as $mapping) {
                $mapping = (array) $mapping;
                $filepath = (string) ($mapping['filepath'] ?? '');
                $filename = (string) ($mapping['filename'] ?? '');
                $oldcontenthash = (string) ($mapping['oldcontenthash'] ?? '');
                $newcontenthash = (string) ($mapping['newcontenthash'] ?? '');
                $path = $filepath . $filename;
                $file = $files[$path] ?? null;
                $record = $file ? ($records[$file->get_pathnamehash()] ?? null) : null;

                if (
                    $filepath === ''
                    || !str_starts_with($filepath, '/')
                    || $filename === ''
                    || isset($processedpaths[$path])
                    || !preg_match('/^[a-f0-9]{40}$/D', $oldcontenthash)
                    || !preg_match('/^[a-f0-9]{40}$/D', $newcontenthash)
                    || !$file
                    || !hash_equals($file->get_contenthash(), $newcontenthash)
                ) {
                    throw new \core\exception\invalid_parameter_exception(
                        'Invalid sanitised photograph mapping.'
                    );
                }
                $processedpaths[$path] = true;

                // Propagate the sanitised marker from the draft area to the
                // permanent file so that thumbnail generation can use the
                // faster resize_image() path instead of re-sanitising.
                image_validator::mark_sanitised($file);

                // New files have no metadata to preserve.
                if (!$record) {
                    continue;
                }

                if (!hash_equals((string) $record->contenthash, $oldcontenthash)) {
                    // The form replaced this path with a different photograph.
                    // Reconciliation will deliberately create blank metadata.
                    continue;
                }

                if (!hash_equals((string) $record->contenthash, $newcontenthash)) {
                    $record->contenthash = $newcontenthash;
                    $record->timemodified = $now;
                    $DB->update_record('photogallery_image', $record);
                    $adopted++;
                }
            }

            $transaction->allow_commit();
            return $adopted;
        } finally {
            $lock->release();
        }
    }

    /**
     * Returns source files from both plugin file areas.
     *
     * @param context_module $context Activity context.
     * @return stored_file[]
     */
    private static function get_source_files(context_module $context): array {
        $filestorage = get_file_storage();
        $files = [];

        foreach (['cover', 'images'] as $filearea) {
            $areafiles = $filestorage->get_area_files(
                $context->id,
                'mod_photogallery',
                $filearea,
                0,
                'sortorder ASC, id ASC',
                false
            );
            foreach ($areafiles as $file) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * Calculates the first unused sort order.
     *
     * @param \stdClass[] $matches Matched metadata records.
     * @return int
     */
    private static function get_next_sortorder(array $matches): int {
        $maximum = -1;
        foreach ($matches as $record) {
            $maximum = max($maximum, (int) $record->sortorder);
        }
        return $maximum + 1;
    }

    /**
     * Validates identifiers and text lengths independently of the browser form.
     *
     * @param stored_file[] $images Images displayed by the form.
     * @param \stdClass $data Submitted form data.
     */
    private static function validate_submitted_metadata(array $images, \stdClass $data): void {
        foreach ($images as $index => $file) {
            $pathnamehash = (string) ($data->{'pathnamehash_' . $index} ?? '');
            if (!hash_equals($file->get_pathnamehash(), $pathnamehash)) {
                throw new \core\exception\invalid_parameter_exception('Invalid photograph identifier.');
            }

            $caption = trim((string) ($data->{'caption_' . $index} ?? ''));
            $alttext = trim((string) ($data->{'alttext_' . $index} ?? ''));

            if (\core_text::strlen($caption) > self::CAPTION_MAX_LENGTH) {
                throw new \moodle_exception(
                    'metadatavaluetoolong',
                    'mod_photogallery',
                    '',
                    self::CAPTION_MAX_LENGTH
                );
            }
            if (\core_text::strlen($alttext) > self::ALTTEXT_MAX_LENGTH) {
                throw new \moodle_exception(
                    'metadatavaluetoolong',
                    'mod_photogallery',
                    '',
                    self::ALTTEXT_MAX_LENGTH
                );
            }
        }
    }

    /**
     * Applies the move button selected in the metadata form.
     *
     * @param stored_file[] $images Images in their current displayed order.
     * @param \stdClass $data Submitted form data.
     * @param \stdClass[] $savedrecords Records indexed by pathname hash.
     * @return bool Whether the order changed.
     */
    private static function apply_order_change(array $images, \stdClass $data, array $savedrecords): bool {
        global $DB;

        $moveindex = null;
        $targetposition = null;
        foreach ($images as $index => $unused) {
            if (!empty($data->{'moveto_' . $index})) {
                $moveindex = $index;
                $targetposition = (int) ($data->{'targetposition_' . $index} ?? 0);
                break;
            }
        }

        if ($moveindex === null || $targetposition === null) {
            return false;
        }

        $imagecount = count($images);
        $hascover = $imagecount > 0 && $images[0]->get_filearea() === 'cover';
        $firstmovableindex = $hascover ? 1 : 0;
        $targetindex = $targetposition - 1;

        $validmove = $moveindex >= $firstmovableindex
            && $moveindex < $imagecount
            && $targetindex >= $firstmovableindex
            && $targetindex < $imagecount;

        if (!$validmove || $targetindex === $moveindex) {
            return false;
        }

        $orderedhashes = array_map(
            static fn(stored_file $image): string => $image->get_pathnamehash(),
            $images
        );
        $moved = array_splice($orderedhashes, $moveindex, 1);
        array_splice($orderedhashes, $targetindex, 0, $moved);

        $filesbyhash = [];
        foreach ($images as $image) {
            $filesbyhash[$image->get_pathnamehash()] = $image;
        }

        foreach ($orderedhashes as $newindex => $pathnamehash) {
            $record = $savedrecords[$pathnamehash] ?? null;
            if ($record) {
                $DB->set_field('photogallery_image', 'sortorder', $newindex, ['id' => $record->id]);
            }
            if (isset($filesbyhash[$pathnamehash])) {
                $filesbyhash[$pathnamehash]->set_sortorder($newindex);
            }
        }

        return true;
    }

    /**
     * Acquires the gallery metadata lock.
     *
     * @param int $photogalleryid Gallery instance ID.
     * @return \core\lock\lock
     */
    private static function acquire_lock(int $photogalleryid): \core\lock\lock {
        $factory = lock_config::get_lock_factory('mod_photogallery_metadata');
        $lock = $factory->get_lock('gallery:' . $photogalleryid, self::LOCK_TIMEOUT, MINSECS);
        if (!$lock) {
            throw new \moodle_exception('metadatalockfailed', 'mod_photogallery');
        }
        return $lock;
    }
}
