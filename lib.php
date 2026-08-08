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
 * Library functions for the Photo gallery activity.
 *
 * @package   mod_photogallery
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Declares the Moodle features supported by this plugin.
 *
 * @param string $feature Feature being checked.
 * @return bool|int|string|null
 */
function photogallery_supports(string $feature): bool|int|string|null {
    return match ($feature) {
        FEATURE_MOD_ARCHETYPE => MOD_ARCHETYPE_RESOURCE,
        FEATURE_GROUPS => false,
        FEATURE_GROUPINGS => false,
        FEATURE_MOD_INTRO => true,
        FEATURE_SHOW_DESCRIPTION => true,
        FEATURE_COMPLETION_TRACKS_VIEWS => true,
        FEATURE_GRADE_HAS_GRADE => false,
        FEATURE_GRADE_OUTCOMES => false,
        FEATURE_BACKUP_MOODLE2 => true,
        FEATURE_MOD_PURPOSE => MOD_PURPOSE_CONTENT,
        default => null,
    };
}

/**
 * Returns the options used by the gallery file manager.
 *
 * @param stdClass $course Course record.
 * @return array
 */
function photogallery_get_filemanager_options(stdClass $course): array {
    global $CFG;

    // Limite máximo de 10 MB por imagem, respeitando os limites do
    // servidor, do site e do curso.
    $maxbytes = get_max_upload_file_size(
        $CFG->maxbytes,
        $course->maxbytes,
        10 * 1024 * 1024
    );

    return [
        // Não permite criar subpastas dentro da galeria.
        'subdirs' => 0,

        // Limite por arquivo.
        'maxbytes' => $maxbytes,

        // Limite total inicial da galeria: 200 MB.
        'areamaxbytes' => 200 * 1024 * 1024,

        // Máximo de 100 fotografias por galeria.
        'maxfiles' => 100,

        // Aceita apenas imagens compatíveis com a web.
        'accepted_types' => ['.jpg', '.jpeg', '.png', '.webp'],

        // Armazena uma cópia interna no Moodle.
        'return_types' => FILE_INTERNAL,
    ];
}

/**
 * Returns the file picker options for ZIP imports.
 *
 * @param stdClass $course Course record.
 * @return array
 */
function photogallery_get_zip_filepicker_options(stdClass $course): array {
    global $CFG;

    $maxbytes = get_max_upload_file_size(
        $CFG->maxbytes,
        $course->maxbytes,
        200 * 1024 * 1024
    );

    return [
        'maxbytes' => $maxbytes,
        'accepted_types' => ['.zip'],
        'return_types' => FILE_INTERNAL,
    ];
}

/**
 * Copies a user draft area to an immutable server-side staging area.
 *
 * The returned ID is always positive, including for an empty source area. This
 * lets an empty, validated submission intentionally remove all permanent files
 * without reading a user-editable draft again after validation.
 *
 * @param int $sourceitemid Source draft item ID, or zero for an empty area.
 * @return int Snapshot draft item ID.
 */
function photogallery_create_draft_snapshot(int $sourceitemid): int {
    global $USER;

    $snapshotitemid = file_get_unused_draft_itemid();
    if ($sourceitemid <= 0) {
        return $snapshotitemid;
    }

    $usercontext = \core\context\user::instance(
        $USER->id,
        MUST_EXIST
    );
    $filestorage = get_file_storage();

    try {
        $sourcefiles = $filestorage->get_area_files(
            $usercontext->id,
            'user',
            'draft',
            $sourceitemid,
            'id ASC',
            false
        );

        foreach ($sourcefiles as $sourcefile) {
            $filestorage->create_file_from_storedfile(
                [
                    'contextid' => $usercontext->id,
                    'component' => 'user',
                    'filearea' => 'draft',
                    'itemid' => $snapshotitemid,
                ],
                $sourcefile
            );
        }
    } catch (\Throwable $exception) {
        $filestorage->delete_area_files(
            $usercontext->id,
            'user',
            'draft',
            $snapshotitemid
        );
        throw $exception;
    }

    return $snapshotitemid;
}

/**
 * Deletes private draft snapshots created by this plugin.
 *
 * @param int[] $snapshotitemids Snapshot draft item IDs.
 * @return void
 */
function photogallery_cleanup_draft_snapshots(
    array $snapshotitemids
): void {
    global $USER;

    $snapshotitemids = array_unique(array_filter(
        array_map('intval', $snapshotitemids),
        static fn(int $itemid): bool => $itemid > 0
    ));
    if (empty($snapshotitemids)) {
        return;
    }

    $usercontext = \core\context\user::instance(
        $USER->id,
        MUST_EXIST
    );
    $filestorage = get_file_storage();
    foreach ($snapshotitemids as $snapshotitemid) {
        $filestorage->delete_area_files(
            $usercontext->id,
            'user',
            'draft',
            $snapshotitemid
        );
    }
}

/**
 * Validates, imports and sanitises submitted image drafts before persistence.
 *
 * ZIP photographs are first staged in the regular images draft. Nothing in
 * the activity context is changed until all submitted content is valid and
 * safe to store.
 *
 * @param int $imagesdraftitemid Images draft item ID.
 * @param int $zipdraftitemid ZIP draft item ID.
 * @param int $coverdraftitemid Cover draft item ID.
 * @param stdClass $course Course record.
 * @return array{imagesdraftitemid: int, coverdraftitemid: int,
 *     imagesanitized: array, coversanitized: array}
 */
function photogallery_prepare_image_drafts(
    int $imagesdraftitemid,
    int $zipdraftitemid,
    int $coverdraftitemid,
    stdClass $course
): array {
    $imageoptions = photogallery_get_filemanager_options($course);
    $coveroptions = photogallery_get_cover_filemanager_options($course);

    $initialimagestats =
        \mod_photogallery\local\image_validator::get_draft_stats(
            $imagesdraftitemid,
            $imageoptions
        );
    $initialcoverstats =
        \mod_photogallery\local\image_validator::get_draft_stats(
            $coverdraftitemid,
            $coveroptions
        );

    if ($zipdraftitemid > 0) {
        \mod_photogallery\local\zip_importer::validate(
            $zipdraftitemid,
            $course,
            $initialimagestats['count'] + $initialcoverstats['count'],
            $initialimagestats['bytes'] + $initialcoverstats['bytes'],
            $initialimagestats['pixels'] + $initialcoverstats['pixels']
        );

        if ($imagesdraftitemid <= 0) {
            $imagesdraftitemid = file_get_unused_draft_itemid();
        }

        \mod_photogallery\local\zip_importer::import_to_draft(
            $zipdraftitemid,
            $imagesdraftitemid,
            $course
        );
    }

    $imagessnapshotid = 0;
    $coversnapshotid = 0;

    try {
        // From this point onward only private snapshots are validated and saved.
        $imagessnapshotid = photogallery_create_draft_snapshot(
            $imagesdraftitemid
        );
        $coversnapshotid = photogallery_create_draft_snapshot(
            $coverdraftitemid
        );

        $imagesanitized =
            \mod_photogallery\local\image_validator::sanitize_draft_area(
                $imagessnapshotid,
                $imageoptions
            );
        $coversanitized =
            \mod_photogallery\local\image_validator::sanitize_draft_area(
                $coversnapshotid,
                $coveroptions
            );

        // Recheck limits because re-encoding can change file sizes.
        $imagestats = \mod_photogallery\local\image_validator::get_draft_stats(
            $imagessnapshotid,
            $imageoptions
        );
        $coverstats = \mod_photogallery\local\image_validator::get_draft_stats(
            $coversnapshotid,
            $coveroptions
        );

        if ($imagestats['count'] + $coverstats['count'] > 100) {
            throw new moodle_exception(
                'toomanyimages',
                'mod_photogallery',
                '',
                100
            );
        }

        $maxgallerybytes = 200 * 1024 * 1024;
        if ($imagestats['bytes'] + $coverstats['bytes'] > $maxgallerybytes) {
            throw new moodle_exception(
                'galleryareatoolarge',
                'mod_photogallery',
                '',
                display_size($maxgallerybytes)
            );
        }

        if (
            $imagestats['pixels'] + $coverstats['pixels']
            > \mod_photogallery\local\image_validator::MAX_TOTAL_PIXELS
        ) {
            throw new moodle_exception(
                'imagetotalpixelstoolarge',
                'mod_photogallery',
                '',
                (int) (
                    \mod_photogallery\local\image_validator::MAX_TOTAL_PIXELS
                    / 1000000
                )
            );
        }

        return [
            'imagesdraftitemid' => $imagessnapshotid,
            'coverdraftitemid' => $coversnapshotid,
            'imagesanitized' => $imagesanitized,
            'coversanitized' => $coversanitized,
        ];
    } catch (\Throwable $exception) {
        photogallery_cleanup_draft_snapshots([
            $imagessnapshotid,
            $coversnapshotid,
        ]);
        throw $exception;
    }
}

/**
 * Creates a new Photo gallery activity instance.
 *
 * @param stdClass $photogallery Submitted activity data.
 * @param mod_photogallery_mod_form|null $mform Activity form.
 * @return int New activity instance ID.
 */
function photogallery_add_instance(
    stdClass $photogallery,
    $mform = null
): int {
    $lock = \mod_photogallery\local\media_lock::acquire(
        (int) $photogallery->coursemodule
    );

    try {
        return photogallery_add_instance_locked(
            $photogallery,
            $mform
        );
    } finally {
        $lock->release();
    }
}

/**
 * Creates an activity while its course-module media lock is held.
 *
 * @param stdClass $photogallery Submitted activity data.
 * @param mod_photogallery_mod_form|null $mform Activity form.
 * @return int New activity instance ID.
 */
function photogallery_add_instance_locked(
    stdClass $photogallery,
    $mform = null
): int {
    global $DB;

    $cmid = (int) $photogallery->coursemodule;

    $imagesdraftitemid = (int) (
        $photogallery->images ?? 0
    );

    $zipdraftitemid = (int) (
        $photogallery->importzip ?? 0
    );

    $coverdraftitemid = (int) (
        $photogallery->coverimage ?? 0
    );

    $course = get_course($photogallery->course);
    $prepareddrafts = photogallery_prepare_image_drafts(
        $imagesdraftitemid,
        $zipdraftitemid,
        $coverdraftitemid,
        $course
    );
    $imagesdraftitemid = $prepareddrafts['imagesdraftitemid'];
    $coverdraftitemid = $prepareddrafts['coverdraftitemid'];

    unset(
        $photogallery->images,
        $photogallery->importzip,
        $photogallery->coverimage
    );

    $time = time();

    $photogallery->timecreated = $time;
    $photogallery->timemodified = $time;

    $transaction = $DB->start_delegated_transaction();

    try {
        // Cria o registro da galeria.
        $photogallery->id = $DB->insert_record(
            'photogallery',
            $photogallery
        );

        /*
        * Relaciona o módulo do curso com a nova instância.
        *
        * Precisamos fazer isso antes de criar o contexto do módulo,
        * pois os arquivos serão vinculados a esse contexto.
        */
        $DB->set_field(
            'course_modules',
            'instance',
            $photogallery->id,
            ['id' => $cmid]
        );

        $context = context_module::instance($cmid);

        // Move as imagens da área temporária para a área definitiva.
        try {
            file_save_draft_area_files(
                $imagesdraftitemid,
                $context->id,
                'mod_photogallery',
                'images',
                0,
                photogallery_get_filemanager_options(
                    $course
                )
            );

            file_save_draft_area_files(
                $coverdraftitemid,
                $context->id,
                'mod_photogallery',
                'cover',
                0,
                photogallery_get_cover_filemanager_options(
                    $course
                )
            );
        } finally {
            photogallery_cleanup_draft_snapshots([
                $imagesdraftitemid,
                $coverdraftitemid,
            ]);
        }

        \mod_photogallery\local\metadata_manager::adopt_sanitized_content(
            (int) $photogallery->id,
            $context,
            'images',
            $prepareddrafts['imagesanitized']
        );
        \mod_photogallery\local\metadata_manager::adopt_sanitized_content(
            (int) $photogallery->id,
            $context,
            'cover',
            $prepareddrafts['coversanitized']
        );

        \mod_photogallery\local\metadata_manager::sync_sortorder_from_files(
            (int) $photogallery->id,
            $context
        );

        photogallery_cleanup_generated_previews($context);

        $completiontimeexpected = !empty($photogallery->completionexpected)
        ? (int) $photogallery->completionexpected
        : null;

        \core_completion\api::update_completion_date_event(
            $cmid,
            'photogallery',
            $photogallery->id,
            $completiontimeexpected
        );

        $transaction->allow_commit();
    } catch (\Throwable $exception) {
        photogallery_cleanup_draft_snapshots([
            $imagesdraftitemid,
            $coverdraftitemid,
        ]);
        $transaction->rollback($exception);
    }

    photogallery_queue_missing_previews(
        (int) $photogallery->id,
        $context,
        (int) ($photogallery->previewcount ?? 6)
    );

    return (int) $photogallery->id;
}

/**
 * Updates an existing photo gallery instance.
 *
 * @param stdClass $photogallery Form data.
 * @param mixed $mform Activity form.
 * @return bool
 */
function photogallery_update_instance(
    stdClass $photogallery,
    $mform = null
): bool {
    $lock = \mod_photogallery\local\media_lock::acquire(
        (int) $photogallery->coursemodule
    );

    try {
        return photogallery_update_instance_locked(
            $photogallery,
            $mform
        );
    } finally {
        $lock->release();
    }
}

/**
 * Updates an activity while its course-module media lock is held.
 *
 * @param stdClass $photogallery Form data.
 * @param mixed $mform Activity form.
 * @return bool
 */
function photogallery_update_instance_locked(
    stdClass $photogallery,
    $mform = null
): bool {
    global $DB;

    $cmid = (int) $photogallery->coursemodule;
    $imagesdraftitemid = (int) (
        $photogallery->images ?? 0
    );

    $zipdraftitemid = (int) (
        $photogallery->importzip ?? 0
    );

    $coverdraftitemid = (int) (
        $photogallery->coverimage ?? 0
    );

    $course = get_course($photogallery->course);
    $prepareddrafts = photogallery_prepare_image_drafts(
        $imagesdraftitemid,
        $zipdraftitemid,
        $coverdraftitemid,
        $course
    );
    $imagesdraftitemid = $prepareddrafts['imagesdraftitemid'];
    $coverdraftitemid = $prepareddrafts['coverdraftitemid'];

    unset(
        $photogallery->images,
        $photogallery->importzip,
        $photogallery->coverimage
    );

    $photogallery->id = $photogallery->instance;
    $photogallery->timemodified = time();

    $transaction = $DB->start_delegated_transaction();

    try {
        $DB->update_record(
            'photogallery',
            $photogallery
        );

        $context = context_module::instance($cmid);

        /*
        * Sincroniza o gerenciador de arquivos com a área permanente.
        *
        * Novos arquivos são adicionados.
        * Arquivos removidos no formulário também são excluídos.
        */
        try {
            file_save_draft_area_files(
                $imagesdraftitemid,
                $context->id,
                'mod_photogallery',
                'images',
                0,
                photogallery_get_filemanager_options(
                    $course
                )
            );

            file_save_draft_area_files(
                $coverdraftitemid,
                $context->id,
                'mod_photogallery',
                'cover',
                0,
                photogallery_get_cover_filemanager_options(
                    $course
                )
            );
        } finally {
            photogallery_cleanup_draft_snapshots([
                $imagesdraftitemid,
                $coverdraftitemid,
            ]);
        }

        \mod_photogallery\local\metadata_manager::adopt_sanitized_content(
            (int) $photogallery->id,
            $context,
            'images',
            $prepareddrafts['imagesanitized']
        );
        \mod_photogallery\local\metadata_manager::adopt_sanitized_content(
            (int) $photogallery->id,
            $context,
            'cover',
            $prepareddrafts['coversanitized']
        );

        \mod_photogallery\local\metadata_manager::sync_sortorder_from_files(
            (int) $photogallery->id,
            $context
        );

        // Remove previews whose source was removed or replaced before queuing.
        photogallery_cleanup_generated_previews($context);

        $completiontimeexpected = !empty($photogallery->completionexpected)
        ? (int) $photogallery->completionexpected
        : null;

        \core_completion\api::update_completion_date_event(
            $cmid,
            'photogallery',
            $photogallery->id,
            $completiontimeexpected
        );

        $transaction->allow_commit();
    } catch (\Throwable $exception) {
        photogallery_cleanup_draft_snapshots([
            $imagesdraftitemid,
            $coverdraftitemid,
        ]);
        $transaction->rollback($exception);
    }

    photogallery_queue_missing_previews(
        (int) $photogallery->id,
        $context,
        (int) ($photogallery->previewcount ?? 6)
    );

    return true;
}

/**
 * Deletes a photo gallery instance.
 *
 * @param int $id Gallery instance ID.
 * @return bool
 */
function photogallery_delete_instance(
    int $id
): bool {
    global $DB;

    $photogallery = $DB->get_record(
        'photogallery',
        ['id' => $id]
    );

    if (!$photogallery) {
        return false;
    }

    $cm = get_coursemodule_from_instance(
        'photogallery',
        $id,
        $photogallery->course,
        false,
        MUST_EXIST
    );

    $lock = \mod_photogallery\local\media_lock::acquire(
        (int) $cm->id
    );

    try {
        return photogallery_delete_instance_locked($id, $cm);
    } finally {
        $lock->release();
    }
}

/**
 * Deletes an activity while its course-module media lock is held.
 *
 * @param int $id Gallery instance ID.
 * @param stdClass $cm Course module record.
 * @return bool
 */
function photogallery_delete_instance_locked(
    int $id,
    stdClass $cm
): bool {
    global $DB;

    if (!$DB->record_exists('photogallery', ['id' => $id])) {
        return false;
    }

    $context =
        \core\context\module::instance(
            $cm->id,
            MUST_EXIST
        );

    if ($context === false) {
        return false;
    }

    \core_completion\api::update_completion_date_event(
        $cm->id,
        'photogallery',
        $id,
        null
    );

    $filestorage = get_file_storage();

    $filestorage->delete_area_files(
        $context->id,
        'mod_photogallery'
    );

    /*
     * Delete child records before the gallery because
     * photogallery_image has a foreign key.
     */
    $DB->delete_records(
        'photogallery_image',
        [
            'photogalleryid' => $id,
        ]
    );

    $DB->delete_records(
        'photogallery',
        [
            'id' => $id,
        ]
    );

    return true;
}

/**
 * Returns the images stored in a gallery.
 *
 * @param context_module $context Activity module context.
 * @return stored_file[]
 */
function photogallery_get_images(context_module $context): array {
    $filestorage = get_file_storage();

    return $filestorage->get_area_files(
        $context->id,
        'mod_photogallery',
        'images',
        0,
        'sortorder ASC, id ASC',
        false
    );
}

/**
 * Lists the gallery file areas exposed to Moodle's file browser.
 *
 * Generated thumbnails are deliberately excluded because they can be
 * recreated from the original photographs.
 *
 * @param stdClass $course Course record.
 * @param stdClass $cm Course module record.
 * @param context $context Module context.
 * @return array<string, string>
 */
function photogallery_get_file_areas(
    $course,
    $cm,
    $context
): array {
    return [
        'images' => get_string('images', 'mod_photogallery'),
        'cover' => get_string('coverimage', 'mod_photogallery'),
    ];
}

/**
 * Returns file information for Moodle's file browser and Server files.
 *
 * This integration is intentionally read-only. Gallery writes must pass
 * through the activity form so its type, count and storage limits apply.
 *
 * @param file_browser $browser File browser instance.
 * @param array $areas Available file areas.
 * @param stdClass $course Course record.
 * @param stdClass $cm Course module record.
 * @param context $context Module context.
 * @param string $filearea Requested file area.
 * @param int $itemid File item ID.
 * @param string|null $filepath File path.
 * @param string|null $filename File name.
 * @return file_info|null
 */
function photogallery_get_file_info(
    $browser,
    $areas,
    $course,
    $cm,
    $context,
    $filearea,
    $itemid,
    $filepath,
    $filename
) {
    global $CFG;

    if (
        !isset($areas[$filearea])
        || !in_array($filearea, ['images', 'cover'], true)
        || (int) $itemid !== 0
        || !has_capability('mod/photogallery:view', $context)
    ) {
        return null;
    }

    $filepath = $filepath ?? '/';
    $filename = $filename ?? '.';
    $filestorage = get_file_storage();

    $storedfile = $filestorage->get_file(
        $context->id,
        'mod_photogallery',
        $filearea,
        0,
        $filepath,
        $filename
    );

    if (!$storedfile) {
        if ($filepath !== '/' || $filename !== '.') {
            return null;
        }

        $storedfile = new virtual_root_file(
            $context->id,
            'mod_photogallery',
            $filearea,
            0
        );
    }

    return new file_info_stored(
        $browser,
        $context,
        $storedfile,
        $CFG->wwwroot . '/pluginfile.php',
        $areas[$filearea],
        true,
        false,
        false,
        false
    );
}

/**
 * Serves files stored in the gallery images file area.
 *
 * @param stdClass $course Course record.
 * @param stdClass $cm Course module record.
 * @param context $context File context.
 * @param string $filearea Requested file area.
 * @param array $args Remaining parts of the file path.
 * @param bool $forcedownload Whether the file should be downloaded.
 * @param array $options Additional file-serving options.
 * @return bool False when the file cannot be served.
 */
function photogallery_pluginfile(
    $course,
    $cm,
    $context,
    string $filearea,
    array $args,
    bool $forcedownload,
    array $options = []
) {
    // The gallery files must belong to an activity module context.
    if ($context->contextlevel !== CONTEXT_MODULE) {
        return false;
    }

    // Only the images file area is handled by this callback.
    $allowedfileareas = [
        'images',
        'cover',
        'thumbs',
    ];

    if (
        !in_array(
            $filearea,
            $allowedfileareas,
            true
        )
    ) {
        return false;
    }

    // Checks course, activity visibility and user access.
    require_course_login($course, true, $cm);

    if (!has_capability('mod/photogallery:view', $context)) {
        return false;
    }

    /*
     * Our file URLs contain itemid 0.
     *
     * Example:
     * /pluginfile.php/58/mod_photogallery/images/0/foto.jpg
     */
    $itemid = (int) array_shift($args);

    if ($itemid !== 0) {
        return false;
    }

    // The last part is the filename.
    $filename = array_pop($args);

    if ($filename === null || $filename === '') {
        return false;
    }

    // Everything before the filename represents the file path.
    if (empty($args)) {
        $filepath = '/';
    } else {
        $filepath = '/' . implode('/', $args) . '/';
    }

    // Failure markers and any unexpected thumbnail paths are private internals.
    if (
        $filearea === 'thumbs'
        && (
            !in_array($filepath, ['/grid/', '/mosaic/'], true)
            || !preg_match('/^[a-f0-9]{40}$/D', $filename)
        )
    ) {
        return false;
    }

    // Custom thumbnails are stored in the thumbs file area.
    // Core preview generation is not used by this plugin.
    unset($options['preview']);

    $filestorage = get_file_storage();

    $file = $filestorage->get_file(
        $context->id,
        'mod_photogallery',
        $filearea,
        0,
        $filepath,
        $filename
    );

    if (!$file || $file->is_directory()) {
        return false;
    }

    // Legacy active or unsupported formats remain stored but are never served.
    if (
        in_array($filearea, ['images', 'cover'], true)
        && !in_array($file->get_mimetype(), ['image/jpeg', 'image/png', 'image/webp'], true)
    ) {
        return false;
    }

    // Restricts the capabilities of files displayed in the browser.
    header('X-Content-Type-Options: nosniff');
    if (!$forcedownload) {
        header(
            "Content-Security-Policy: sandbox; default-src 'none'; "
            . "img-src 'self'; media-src 'self'"
        );
        header('Referrer-Policy: no-referrer');
    }

    // File delivery no longer needs to keep the user session locked.
    \core\session\manager::write_close();

    send_stored_file(
        $file,
        DAYSECS,
        0,
        $forcedownload,
        $options
    );
}

/**
 * Returns files from a user draft area.
 *
 * @param int $draftitemid Draft item ID.
 * @return stored_file[]
 */
function photogallery_get_draft_files(
    int $draftitemid
): array {
    return \mod_photogallery\local\zip_importer
        ::get_draft_files(
            $draftitemid
        );
}

/**
 * Returns supported image entries from an uploaded ZIP.
 *
 * @param int $draftitemid ZIP draft item ID.
 * @return string[]
 */
function photogallery_get_zip_image_entries(
    int $draftitemid
): array {
    return \mod_photogallery\local\zip_importer
        ::get_image_entries(
            $draftitemid
        );
}

/**
 * Generates a unique filename inside the gallery.
 *
 * @param \core\context\module $context Activity context.
 * @param string $filename Original filename.
 * @return string
 */
function photogallery_get_unique_filename(
    \core\context\module $context,
    string $filename
): string {
    return \mod_photogallery\local\zip_importer
        ::get_unique_filename(
            $context,
            $filename
        );
}

/**
 * Imports photographs from an uploaded ZIP.
 *
 * @param int $draftitemid ZIP draft item ID.
 * @param \core\context\module $context Activity context.
 * @param stdClass $course Course record.
 * @return int Number of imported images.
 */
function photogallery_import_zip(
    int $draftitemid,
    \core\context\module $context,
    stdClass $course
): int {
    return \mod_photogallery\local\zip_importer
        ::import(
            $draftitemid,
            $context,
            $course
        );
}

/**
 * Provides cached information about a gallery course module.
 *
 * @param stdClass $cm Course module database record.
 * @return cached_cm_info|null
 */
function photogallery_get_coursemodule_info($cm) {
    global $DB;

    $photogallery = $DB->get_record(
        'photogallery',
        ['id' => $cm->instance],
        'id, course, name, intro, introformat, previewcount'
    );

    if (!$photogallery) {
        return null;
    }

    $cminfo = new cached_cm_info();

    $cminfo->name = $photogallery->name;

    $cminfo->customdata = (object) [
        'id' => (int) $photogallery->id,
        'course' => (int) $photogallery->course,
        'name' => (string) $photogallery->name,
        'intro' => (string) $photogallery->intro,
        'introformat' => (int) $photogallery->introformat,
        'previewcount' => (int) $photogallery->previewcount,
    ];

    return $cminfo;
}

/**
 * Displays the photo mosaic directly on the course page.
 *
 * @param cm_info $cm Course module information.
 * @return void
 */
function photogallery_cm_info_view(\cm_info $cm): void {
    global $OUTPUT, $PAGE;

    if (!$cm->uservisible) {
        return;
    }

    // Module context used by file and output APIs.
    $modulecontext = $cm->context;

    // Context used for capability checks.
    $capabilitycontext = $modulecontext;

    if (
        !has_capability(
            'mod/photogallery:view',
            $capabilitycontext
        )
    ) {
        return;
    }

    $customdata = $cm->get_custom_data();

    if (empty($customdata)) {
        return;
    }

    $photogallery = (object) $customdata;

    $previewcount = (int) (
        $photogallery->previewcount ?? 6
    );

    $allowedcounts = [
        3,
        4,
        5,
        6,
        8,
        9,
        12,
    ];

    if (
        !in_array(
            $previewcount,
            $allowedcounts,
            true
        )
    ) {
        $previewcount = 6;
    }

    $metadata = photogallery_get_image_metadata(
        (int) $photogallery->id
    );

    $allimages = photogallery_get_display_images(
        $modulecontext,
        (int) $photogallery->id,
        $metadata
    );

    $allimages = array_values($allimages);

    $previewimages = array_slice(
        $allimages,
        0,
        $previewcount
    );

    $intro = '';

    if (
        $cm->showdescription
        && trim((string) $photogallery->intro) !== ''
    ) {
        $introrecord = (object) [
            'id' => (int) $photogallery->id,
            'course' => (int) $photogallery->course,
            'name' => (string) $photogallery->name,
            'intro' => (string) $photogallery->intro,
            'introformat' => (int) $photogallery->introformat,
        ];

        $intro = format_module_intro(
            'photogallery',
            $introrecord,
            $cm->id
        );
    }

    $galleryurl = new moodle_url(
        '/mod/photogallery/view.php',
        ['id' => $cm->id]
    );

    $preview = new \mod_photogallery\output\preview(
        $previewimages,
        $modulecontext,
        (string) $photogallery->name,
        $galleryurl,
        count($allimages),
        $metadata,
        $intro,
        $allimages
    );

    $html = $OUTPUT->render($preview);

    $cm->set_content($html, true);

    static $javascriptloaded = false;

    if (!$javascriptloaded) {
        $PAGE->requires->js_call_amd(
            'mod_photogallery/lightbox',
            'init'
        );

        $javascriptloaded = true;
    }
}

/**
 * Returns the file manager options for the featured image.
 *
 * @param stdClass $course Course record.
 * @return array
 */
function photogallery_get_cover_filemanager_options(
    stdClass $course
): array {
    global $CFG;

    $maxbytes = get_max_upload_file_size(
        $CFG->maxbytes,
        $course->maxbytes,
        10 * 1024 * 1024
    );

    return [
        'subdirs' => 0,
        'maxbytes' => $maxbytes,
        'areamaxbytes' => $maxbytes,
        'maxfiles' => 1,
        'accepted_types' => ['.jpg', '.jpeg', '.png', '.webp'],
        'return_types' => FILE_INTERNAL,
    ];
}

/**
 * Returns the featured image.
 *
 * @param \core\context\module $context Module context.
 * @return stored_file|null
 */
function photogallery_get_cover_image(
    \core\context\module $context
): ?stored_file {
    $filestorage = get_file_storage();

    $files = $filestorage->get_area_files(
        $context->id,
        'mod_photogallery',
        'cover',
        0,
        'id ASC',
        false
    );

    if (empty($files)) {
        return null;
    }

    $cover = reset($files);

    return $cover instanceof stored_file ? $cover : null;
}

/**
 * Returns gallery images in their display order.
 *
 * The featured image remains fixed in the first position.
 * Regular photographs are ordered using their metadata sortorder.
 *
 * @param \core\context\module $context Module context.
 * @param int $photogalleryid Gallery instance ID.
 * @param stdClass[]|null $metadata Preloaded metadata indexed by pathname hash.
 * @return stored_file[]
 */
function photogallery_get_display_images(
    \core\context\module $context,
    int $photogalleryid,
    ?array $metadata = null
): array {
    $supportedmimetypes = ['image/jpeg', 'image/png', 'image/webp'];
    $images = array_values(array_filter(
        photogallery_get_images($context),
        static fn(stored_file $file): bool => in_array(
            $file->get_mimetype(),
            $supportedmimetypes,
            true
        )
    ));

    if ($metadata === null) {
        $metadata = photogallery_get_image_metadata(
            $photogalleryid
        );
    }

    $sortableimages = [];

    foreach ($images as $fallbackindex => $image) {
        $pathnamehash = $image->get_pathnamehash();
        $record = $metadata[$pathnamehash] ?? null;

        /*
         * Photographs without a stored order are placed after
         * the already ordered photographs, retaining their
         * original File API order.
         */
        $sortorder = $record
            ? (int) $record->sortorder
            : 100000 + $fallbackindex;

        $sortableimages[] = (object) [
            'file' => $image,
            'sortorder' => $sortorder,
            'fallbackindex' => $fallbackindex,
        ];
    }

    usort(
        $sortableimages,
        static function (
            stdClass $first,
            stdClass $second
        ): int {
            $ordercomparison =
                $first->sortorder
                <=> $second->sortorder;

            if ($ordercomparison !== 0) {
                return $ordercomparison;
            }

            return $first->fallbackindex
                <=> $second->fallbackindex;
        }
    );

    $orderedimages = array_map(
        static fn(stdClass $item): stored_file =>
            $item->file,
        $sortableimages
    );

    $cover = photogallery_get_cover_image(
        $context
    );

    if (
        !$cover instanceof stored_file
        || !in_array($cover->get_mimetype(), $supportedmimetypes, true)
    ) {
        return $orderedimages;
    }

    $displayimages = [
        $cover,
    ];

    foreach ($orderedimages as $image) {
        /*
         * Avoid displaying the same image twice when the
         * featured image has identical content.
         */
        if (
            $image->get_contenthash()
            === $cover->get_contenthash()
        ) {
            continue;
        }

        $displayimages[] = $image;
    }

    return $displayimages;
}

/**
 * Returns gallery image metadata indexed by pathname hash.
 *
 * @param int $photogalleryid Gallery instance ID.
 * @return stdClass[]
 */
function photogallery_get_image_metadata(
    int $photogalleryid
): array {
    return \mod_photogallery\local\metadata_manager
        ::get_by_pathnamehash(
            $photogalleryid
        );
}

/**
 * Removes metadata belonging to files which no longer exist.
 *
 * @param int $photogalleryid Gallery instance ID.
 * @param \core\context\module $context Activity context.
 * @return int Number of metadata records removed.
 */
function photogallery_cleanup_image_metadata(
    int $photogalleryid,
    \core\context\module $context
): int {
    return \mod_photogallery\local\metadata_manager
        ::cleanup_orphans(
            $photogalleryid,
            $context
        );
}

/**
 * Returns an existing preview or creates a new one.
 *
 * @param stored_file $source Original image.
 * @param \core\context\module $context Activity context.
 * @param string $mode Preview mode.
 * @return stored_file|null
 */
function photogallery_get_resized_preview(
    stored_file $source,
    \core\context\module $context,
    string $mode
): ?stored_file {
    return \mod_photogallery\local\thumbnail_manager
        ::get_resized_preview(
            $source,
            $context,
            $mode
        );
}

/**
 * Queues background generation of missing gallery previews.
 *
 * @param int $photogalleryid Gallery instance ID.
 * @param \core\context\module $context Activity context.
 * @param int $previewcount Number displayed in the course mosaic.
 * @return void
 */
function photogallery_queue_missing_previews(
    int $photogalleryid,
    \core\context\module $context,
    int $previewcount
): void {
    photogallery_generate_missing_previews(
        $photogalleryid,
        $context,
        $previewcount
    );
}

/**
 * Generates any missing gallery previews.
 *
 * @param int $photogalleryid Gallery instance ID.
 * @param \core\context\module $context Activity context.
 * @param int $previewcount Number displayed in the course mosaic.
 * @return void
 */
function photogallery_generate_missing_previews(
    int $photogalleryid,
    \core\context\module $context,
    int $previewcount
): void {
    $images = photogallery_get_display_images(
        $context,
        $photogalleryid
    );

    \mod_photogallery\local\thumbnail_manager
        ::generate_missing_previews(
            $images,
            $context,
            $previewcount
        );
}

/**
 * Removes generated previews without a source image.
 *
 * @param \core\context\module $context Activity context.
 * @return int Number of deleted previews.
 */
function photogallery_cleanup_generated_previews(
    \core\context\module $context
): int {
    return \mod_photogallery\local\thumbnail_manager
        ::cleanup_generated_previews(
            $context
        );
}

/**
 * Triggers the view event and marks the gallery as viewed.
 *
 * @param stdClass $photogallery Gallery instance.
 * @param stdClass $course Course record.
 * @param stdClass $cm Course module record.
 * @param \core\context\module $context Module context.
 * @return void
 */
function photogallery_view(
    stdClass $photogallery,
    stdClass $course,
    stdClass $cm,
    \core\context\module $context
): void {
    global $CFG;

    require_once(
        $CFG->libdir . '/completionlib.php'
    );

    /*
     * Register the activity view in Moodle's event system.
     */
    $event =
        \mod_photogallery\event\course_module_viewed::create([
            'context' => $context,
            'objectid' => $photogallery->id,
        ]);

    $event->add_record_snapshot(
        'course_modules',
        $cm
    );

    $event->add_record_snapshot(
        'course',
        $course
    );

    $event->add_record_snapshot(
        'photogallery',
        $photogallery
    );

    $event->trigger();

    /*
     * Mark the activity as viewed when completion
     * by view is enabled for this course module.
     */
    $completion = new completion_info(
        $course
    );

    $completion->set_module_viewed(
        $cm
    );
}
