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
$cm = get_coursemodule_from_id('photogallery', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$photogallery = $DB->get_record('photogallery', ['id' => $cm->instance], '*', MUST_EXIST);

require_course_login($course, true, $cm);

$modulecontext = \core\context\module::instance($cm->id, MUST_EXIST);
require_capability('mod/photogallery:manage', $modulecontext);

$editurl = new moodle_url('/mod/photogallery/editmetadata.php', ['id' => $cm->id]);
$viewurl = new moodle_url('/mod/photogallery/view.php', ['id' => $cm->id]);

$PAGE->set_url($editurl);
$PAGE->set_cm($cm, $course, $photogallery);
$PAGE->set_context($modulecontext);
$PAGE->set_pagelayout('incourse');

$galleryname = format_string($photogallery->name, true, ['context' => $modulecontext]);
$PAGE->set_title(get_string('editmetadatatitle', 'mod_photogallery', $galleryname));
$PAGE->set_heading($course->fullname);

// Reconcile renames and replacements before rendering editable values.
\mod_photogallery\local\metadata_manager::reconcile_files(
    (int) $photogallery->id,
    $modulecontext,
    true
);

$recordsbyhash = \mod_photogallery\local\metadata_manager::get_by_pathnamehash((int) $photogallery->id);
$images = array_values(photogallery_get_display_images(
    $modulecontext,
    (int) $photogallery->id,
    $recordsbyhash
));
$revision = \mod_photogallery\local\metadata_manager::get_revision(
    (int) $photogallery->id,
    $modulecontext
);

$mform = new \mod_photogallery\form\metadata(null, [
    'images' => $images,
    'records' => $recordsbyhash,
    'context' => $modulecontext,
    'revision' => $revision,
]);

if ($mform->is_cancelled()) {
    redirect($viewurl);
}

if ($data = $mform->get_data()) {
    try {
        $orderchanged = \mod_photogallery\local\metadata_manager::save(
            (int) $photogallery->id,
            $modulecontext,
            $images,
            $data,
            (string) $data->revision
        );
    } catch (\moodle_exception $exception) {
        if (in_array($exception->errorcode, ['metadataconflict', 'metadatalockfailed'], true)) {
            redirect(
                $editurl,
                $exception->getMessage(),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        throw $exception;
    }

    if ($orderchanged) {
        rebuild_course_cache($course->id, true);
        redirect(
            $editurl,
            get_string('photoorderupdated', 'mod_photogallery'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    redirect(
        $viewurl,
        get_string('metadataupdated', 'mod_photogallery'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if (!$mform->is_submitted()) {
    $mform->set_data((object) [
        'id' => $cm->id,
        'revision' => $revision,
    ]);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('editmetadata', 'mod_photogallery'));

if (empty($images)) {
    echo $OUTPUT->notification(
        get_string('nophotosmetadata', 'mod_photogallery'),
        \core\output\notification::NOTIFY_INFO
    );
} else {
    $mform->display();
}

echo $OUTPUT->footer();
