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
 * @return bool|string|null
 */
function photogallery_supports(string $feature): bool|string|null {
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
        'accepted_types' => ['web_image'],

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

    unset(
        $photogallery->images,
        $photogallery->importzip,
        $photogallery->coverimage
    );

    $time = time();

    $photogallery->timecreated = $time;
    $photogallery->timemodified = $time;

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
    $course = get_course($photogallery->course);

    // Move as imagens da área temporária para a área definitiva.
    if ($imagesdraftitemid) {
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
    }

    if ($zipdraftitemid) {
        photogallery_import_zip(
            $zipdraftitemid,
            $context,
            $course
        );
    }

    if ($coverdraftitemid > 0) {
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
    }

    photogallery_generate_missing_previews(
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

    unset(
        $photogallery->images,
        $photogallery->importzip,
        $photogallery->coverimage
    );

    $photogallery->id = $photogallery->instance;
    $photogallery->timemodified = time();

    $DB->update_record(
        'photogallery',
        $photogallery
    );

    $context = context_module::instance($cmid);
    $course = get_course($photogallery->course);

    /*
     * Sincroniza o gerenciador de arquivos com a área permanente.
     *
     * Novos arquivos são adicionados.
     * Arquivos removidos no formulário também são excluídos.
     */
    if ($imagesdraftitemid) {
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
    }

    if ($coverdraftitemid > 0) {
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
    }

    if ($zipdraftitemid) {
        photogallery_import_zip(
            $zipdraftitemid,
            $context,
            $course
        );
    }

    photogallery_cleanup_image_metadata(
        (int) $photogallery->id,
        $context
    );

    photogallery_cleanup_generated_previews(
        $context
    );

    photogallery_generate_missing_previews(
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

    $context =
        \core\context\module::instance(
            $cm->id,
            MUST_EXIST
        );

    if ($context === false) {
        return false;
    }

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

    // Restricts the capabilities of files displayed in the browser.
    if (!$forcedownload) {
        header(
            "Content-Security-Policy: default-src 'none'; "
            . "img-src 'self'; media-src 'self'"
        );
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

    $allimages = photogallery_get_display_images(
        $modulecontext,
        (int) $photogallery->id
    );

    $allimages = array_values($allimages);

    $metadata = photogallery_get_image_metadata(
        (int) $photogallery->id
    );

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
        $intro
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
        'accepted_types' => ['web_image'],
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

    return $cover instanceof stored_file
        ? $cover
        : null;
}

/**
 * Returns gallery images in their display order.
 *
 * The featured image is displayed first. If the same file was also
 * uploaded to the regular image area, it is not displayed twice.
 *
 * @param \core\context\module $context Module context.
 * @return stored_file[]
 */
/**
 * Returns gallery images in their display order.
 *
 * The featured image remains fixed in the first position.
 * Regular photographs are ordered using their metadata sortorder.
 *
 * @param \core\context\module $context Module context.
 * @param int $photogalleryid Gallery instance ID.
 * @return stored_file[]
 */
function photogallery_get_display_images(
    \core\context\module $context,
    int $photogalleryid
): array {
    $images = array_values(
        photogallery_get_images($context)
    );

    $metadata = photogallery_get_image_metadata(
        $photogalleryid
    );

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

    if (!$cover instanceof stored_file) {
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
