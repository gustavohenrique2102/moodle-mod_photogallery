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
 * Displays the complete photo gallery.
 *
 * @package   mod_photogallery
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

// Moodle global objects.
global $DB, $OUTPUT, $PAGE;

// Course module ID received through view.php?id=123.
$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id(
    'photogallery',
    $id,
    0,
    false,
    MUST_EXIST
);

$course = get_course($cm->course);

$photogallery = $DB->get_record(
    'photogallery',
    ['id' => $cm->instance],
    '*',
    MUST_EXIST
);

// Verifies login, course access and activity visibility.
require_course_login($course, true, $cm);

// Obtém o contexto da atividade.
$context = \core\context\module::instance(
    $cm->id,
    MUST_EXIST
);

// Verifica a permissão de visualização.
require_capability(
    'mod/photogallery:view',
    $context,
);

/*
 * Register a valid view and update activity completion.
 *
 * This must run before the page header is printed.
 */
photogallery_view(
    $photogallery,
    $course,
    $cm,
    $context
);

// Page configuration.
$PAGE->set_url(
    new moodle_url(
        '/mod/photogallery/view.php',
        ['id' => $cm->id]
    )
);

$PAGE->set_context($context);

$PAGE->set_title(
    format_string(
        $photogallery->name,
        true,
        ['context' => $context]
    )
);

$PAGE->set_heading(
    format_string(
        $course->fullname,
        true,
        ['context' => \core\context\course::instance($course->id)]
    )
);

$PAGE->requires->js_call_amd(
    'mod_photogallery/lightbox',
    'init',
);

echo $OUTPUT->header();

echo $OUTPUT->heading(
    format_string($photogallery->name, true, ['context' => $context])
);

$capabilitycontext = $context;

if (
    has_capability(
        'mod/photogallery:manage',
        $capabilitycontext
    )
) {
    $editmetadataurl = new moodle_url(
        '/mod/photogallery/editmetadata.php',
        ['id' => $cm->id]
    );

    echo html_writer::div(
        html_writer::link(
            $editmetadataurl,
            get_string(
                'editmetadata',
                'mod_photogallery'
            ),
            [
                'class' =>
                    'btn btn-outline-primary',
            ]
        ),
        'd-flex justify-content-end mb-3'
    );
}



// Displays the activity description, when one was provided.
if (trim((string) $photogallery->intro) !== '') {
    echo $OUTPUT->box(
        format_module_intro(
            'photogallery',
            $photogallery,
            $cm->id
        ),
        'generalbox mod_introbox mb-4',
        'photogalleryintro'
    );
}

$metadata = photogallery_get_image_metadata(
    (int) $photogallery->id
);

// Retrieves every gallery image using the metadata already loaded above.
$images = photogallery_get_display_images(
    $context,
    (int) $photogallery->id,
    $metadata
);

// Prepares and renders the gallery.
$galleryoutput = new \mod_photogallery\output\gallery(
    $images,
    $context,
    $photogallery->name,
    $metadata
);

echo $OUTPUT->render($galleryoutput);

echo $OUTPUT->footer();
