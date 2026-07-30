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
 * Image metadata management page.
 *
 * @package   mod_photogallery
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

global $DB, $OUTPUT, $PAGE;

$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id(
    'photogallery',
    $id,
    0,
    false,
    MUST_EXIST
);

$course = $DB->get_record(
    'course',
    ['id' => $cm->course],
    '*',
    MUST_EXIST
);

$photogallery = $DB->get_record(
    'photogallery',
    ['id' => $cm->instance],
    '*',
    MUST_EXIST
);

require_course_login(
    $course,
    true,
    $cm
);

/** @var \core\context\module $modulecontext */
$modulecontext = \core\context\module::instance(
    $cm->id,
    MUST_EXIST
);

if ($modulecontext === false) {
    throw new \core\exception\coding_exception(
        'Could not obtain the photo gallery context.'
    );
}

/** @var \context $capabilitycontext */
$capabilitycontext = $modulecontext;

require_capability(
    'mod/photogallery:manage',
    $capabilitycontext
);

$editurl = new moodle_url(
    '/mod/photogallery/editmetadata.php',
    ['id' => $cm->id]
);

$viewurl = new moodle_url(
    '/mod/photogallery/view.php',
    ['id' => $cm->id]
);

$PAGE->set_url($editurl);
$PAGE->set_cm(
    $cm,
    $course,
    $photogallery
);

$PAGE->set_context($modulecontext);
$PAGE->set_pagelayout('incourse');

$galleryname = format_string(
    $photogallery->name,
    true,
    ['context' => $modulecontext]
);

$PAGE->set_title(
    get_string(
        'editmetadatatitle',
        'mod_photogallery',
        $galleryname
    )
);

$PAGE->set_heading($course->fullname);

$images = array_values(
    photogallery_get_display_images(
        $modulecontext,
        (int) $photogallery->id
    )
);

$metadatarecords = $DB->get_records(
    'photogallery_image',
    [
        'photogalleryid' => $photogallery->id,
    ]
);

$recordsbyhash = [];

foreach ($metadatarecords as $record) {
    $recordsbyhash[$record->pathnamehash] = $record;
}

$mform = new \mod_photogallery\form\metadata(
    null,
    [
        'images' => $images,
        'records' => $recordsbyhash,
        'context' => $modulecontext,
    ]
);

if ($mform->is_cancelled()) {
    redirect($viewurl);
}

if ($data = $mform->get_data()) {
    $moveindex = null;
    $targetposition = null;

    /*
    * Identifica qual botão "Mover" foi pressionado.
    */
    foreach ($images as $index => $file) {
        if (!empty($data->{'moveto_' . $index})) {
            $moveindex = $index;

            /*
            * A posição informada pelo usuário começa em 1.
            */
            $targetposition = (int) (
                $data->{'targetposition_' . $index} ?? 0
            );

            break;
        }
    }

    $imagecount = count($images);

    $hascover = (
        $imagecount > 0
        && $images[0]->get_filearea() === 'cover'
    );

    $firstmovableindex = $hascover ? 1 : 0;

    $transaction = $DB->start_delegated_transaction();

    $savedrecords = [];
    $now = time();

    foreach ($images as $index => $file) {
        $pathnamehash = (string) (
            $data->{'pathnamehash_' . $index} ?? ''
        );

        /*
         * Prevents a submitted identifier from being used
         * for another photograph.
         */
        if (
            $pathnamehash
            !== $file->get_pathnamehash()
        ) {
            throw new
                \core\exception\invalid_parameter_exception(
                    'Invalid photograph identifier.'
                );
        }


        $caption = trim(
            (string) (
                $data->{'caption_' . $index} ?? ''
            )
        );

        $alttext = trim(
            (string) (
                $data->{'alttext_' . $index} ?? ''
            )
        );

        $existing =
            $recordsbyhash[$pathnamehash] ?? null;

        if ($existing) {
            $existing->caption = $caption;
            $existing->alttext = $alttext;
            $existing->sortorder = $index;
            $existing->timemodified = $now;

            $DB->update_record(
                'photogallery_image',
                $existing
            );

            $savedrecords[$pathnamehash] =
                $existing;
        } else {
            $record = (object) [
                'photogalleryid' =>
                    $photogallery->id,
                'pathnamehash' =>
                    $pathnamehash,
                'caption' => $caption,
                'alttext' => $alttext,
                'sortorder' => $index,
                'timecreated' => $now,
                'timemodified' => $now,
            ];

            $record->id = $DB->insert_record(
                'photogallery_image',
                $record,
                true
            );

            $savedrecords[$pathnamehash] =
                $record;
        }
    }

    /*
     * Changes the order after all current form values
     * have been saved.
     */
    $orderchanged = false;

    if (
        $moveindex !== null
        && $targetposition !== null
    ) {
        /*
        * Converte a posição exibida ao usuário para índice PHP.
        *
        * Posição 1 = índice 0
        * Posição 2 = índice 1
        */
        $targetindex = $targetposition - 1;

        $validmove = (
            $moveindex >= $firstmovableindex
            && $moveindex < $imagecount
            && $targetindex >= $firstmovableindex
            && $targetindex < $imagecount
        );

        if (
            $validmove
            && $targetindex !== $moveindex
        ) {
            /*
            * Monta a ordem atual por pathnamehash.
            */
            $orderedhashes = [];

            foreach ($images as $image) {
                $orderedhashes[] =
                    $image->get_pathnamehash();
            }

            /*
            * Retira a fotografia da posição atual.
            */
            $removeditems = array_splice(
                $orderedhashes,
                $moveindex,
                1
            );

            $movedhash = reset($removeditems);

            if (is_string($movedhash)) {
                /*
                * Insere diretamente na posição solicitada.
                */
                array_splice(
                    $orderedhashes,
                    $targetindex,
                    0,
                    [$movedhash]
                );

                /*
                * Normaliza todas as posições para impedir
                * números repetidos ou espaços na sequência.
                */
                foreach ($orderedhashes as $newindex => $pathnamehash) {
                    $record =
                        $savedrecords[$pathnamehash]
                        ?? null;

                    if (!$record) {
                        continue;
                    }

                    $DB->set_field(
                        'photogallery_image',
                        'sortorder',
                        $newindex,
                        ['id' => $record->id]
                    );
                }

                $orderchanged = true;
            }
        }
    }

    $transaction->allow_commit();

    if ($orderchanged) {
        rebuild_course_cache(
            $course->id,
            true
        );

        redirect(
            $editurl,
            get_string(
                'photoorderupdated',
                'mod_photogallery'
            ),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    redirect(
        $viewurl,
        get_string(
            'metadataupdated',
            'mod_photogallery'
        ),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$mform->set_data(
    (object) [
        'id' => $cm->id,
    ]
);

echo $OUTPUT->header();

echo $OUTPUT->heading(
    get_string(
        'editmetadata',
        'mod_photogallery'
    )
);

if (empty($images)) {
    echo $OUTPUT->notification(
        get_string(
            'nophotosmetadata',
            'mod_photogallery'
        ),
        \core\output\notification::NOTIFY_INFO
    );
} else {
    $mform->display();
}

echo $OUTPUT->footer();
