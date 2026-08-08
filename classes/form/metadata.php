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
 * Photo metadata form.
 *
 * @package   mod_photogallery
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_photogallery\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->libdir . '/formslib.php');

/**
 * Form used to edit photo captions and alternative text.
 */
class metadata extends \moodleform {
    /**
     * Defines the form.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;

        $images = $this->_customdata['images'] ?? [];
        $records = $this->_customdata['records'] ?? [];
        $revision = $this->_customdata['revision'] ?? '';

        $imagecount = count($images);

        $hascover = (
            $imagecount > 0
            && $images[0]->get_filearea() === 'cover'
        );

        $previews = \mod_photogallery\local\thumbnail_manager::queue_missing_for_mode(
            $images,
            $this->_customdata['context'],
            'grid'
        );

        $mform->addElement(
            'static',
            'metadatainfo',
            '',
            get_string('metadataintro', 'mod_photogallery')
        );

        foreach ($images as $index => $file) {
            $number = $index + 1;
            $pathnamehash = $file->get_pathnamehash();
            $record = $records[$pathnamehash] ?? null;

            $previewfile = $previews[$file->get_contenthash()] ?? null;

            $thumbnailfile = $previewfile ?? $file;

            $imageurl =
                \moodle_url::make_pluginfile_url(
                    $thumbnailfile->get_contextid(),
                    $thumbnailfile->get_component(),
                    $thumbnailfile->get_filearea(),
                    $thumbnailfile->get_itemid(),
                    $thumbnailfile->get_filepath(),
                    $thumbnailfile->get_filename(),
                    false
                );

            $preview = \html_writer::div(
                \html_writer::img(
                    $imageurl,
                    '',
                    [
                        'class' =>
                            'mod-photogallery-metadata-thumbnail',
                        'loading' => 'lazy',
                    ]
                ) .
                \html_writer::div(
                    s($file->get_filename()),
                    'small text-muted mt-2'
                ),
                'mod-photogallery-metadata-preview'
            );

            $mform->addElement(
                'header',
                'photoheader_' . $index,
                get_string(
                    'photoitem',
                    'mod_photogallery',
                    $number
                )
            );

            $mform->addElement(
                'static',
                'preview_' . $index,
                '',
                $preview
            );

            $iscover = $file->get_filearea() === 'cover';

            $currentposition = $index + 1;

            if ($iscover) {
                $mform->addElement(
                    'static',
                    'coverorder_' . $index,
                    get_string(
                        'photoorder',
                        'mod_photogallery'
                    ),
                    get_string(
                        'featuredimageposition',
                        'mod_photogallery'
                    )
                );
            } else {
                /*
                * Quando existe uma imagem em destaque, a primeira
                * posição disponível para fotos comuns é a posição 2.
                */
                $minimumposition = $hascover ? 2 : 1;
                $maximumposition = $imagecount;

                $positionelements = [];

                $positionelements[] = $mform->createElement(
                    'text',
                    'targetposition_' . $index,
                    '',
                    [
                        'size' => 4,
                        'type' => 'number',
                        'min' => $minimumposition,
                        'max' => $maximumposition,
                        'step' => 1,
                        'inputmode' => 'numeric',
                        'aria-label' => get_string(
                            'targetposition',
                            'mod_photogallery'
                        ),
                    ]
                );

                $positionelements[] = $mform->createElement(
                    'submit',
                    'moveto_' . $index,
                    get_string(
                        'movetoposition',
                        'mod_photogallery'
                    ),
                    [
                        'class' => 'btn btn-secondary',
                    ]
                );

                $mform->addGroup(
                    $positionelements,
                    'positiongroup_' . $index,
                    get_string(
                        'photoorder',
                        'mod_photogallery'
                    ),
                    ' ',
                    false
                );

                $mform->setType(
                    'targetposition_' . $index,
                    PARAM_INT
                );

                $mform->setDefault(
                    'targetposition_' . $index,
                    $currentposition
                );

                $mform->addHelpButton(
                    'positiongroup_' . $index,
                    'targetposition',
                    'mod_photogallery'
                );
            }

            $mform->addElement(
                'hidden',
                'pathnamehash_' . $index,
                $pathnamehash
            );

            $mform->setType(
                'pathnamehash_' . $index,
                PARAM_ALPHANUM
            );

            $mform->addElement(
                'text',
                'caption_' . $index,
                get_string(
                    'photocaption',
                    'mod_photogallery'
                ),
                [
                    'size' => 80,
                    'maxlength' =>
                        \mod_photogallery\local\metadata_manager::CAPTION_MAX_LENGTH,
                ]
            );

            $mform->setType(
                'caption_' . $index,
                PARAM_TEXT
            );

            $mform->addHelpButton(
                'caption_' . $index,
                'photocaption',
                'mod_photogallery'
            );

            $mform->setDefault(
                'caption_' . $index,
                $record->caption ?? ''
            );

            $mform->addElement(
                'textarea',
                'alttext_' . $index,
                get_string(
                    'photoalttext',
                    'mod_photogallery'
                ),
                [
                    'rows' => 3,
                    'cols' => 80,
                    'maxlength' =>
                        \mod_photogallery\local\metadata_manager::ALTTEXT_MAX_LENGTH,
                ]
            );

            $mform->setType(
                'alttext_' . $index,
                PARAM_TEXT
            );

            $mform->addHelpButton(
                'alttext_' . $index,
                'photoalttext',
                'mod_photogallery'
            );

            $mform->setDefault(
                'alttext_' . $index,
                $record->alttext ?? ''
            );
        }

        $mform->addElement(
            'hidden',
            'id'
        );

        $mform->setType(
            'id',
            PARAM_INT
        );

        $mform->addElement(
            'hidden',
            'revision',
            $revision
        );

        $mform->setType(
            'revision',
            PARAM_ALPHANUM
        );

        $this->add_action_buttons(
            true,
            get_string(
                'savemetadata',
                'mod_photogallery'
            )
        );
    }

    /**
     * Validates metadata lengths and photograph position changes.
     *
     * @param array $data Submitted form data.
     * @param array $files Submitted files.
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $images = $this->_customdata['images'] ?? [];
        $imagecount = count($images);

        foreach ($images as $index => $file) {
            $caption = trim((string) ($data['caption_' . $index] ?? ''));
            $alttext = trim((string) ($data['alttext_' . $index] ?? ''));

            if (
                \core_text::strlen($caption)
                > \mod_photogallery\local\metadata_manager::CAPTION_MAX_LENGTH
            ) {
                $errors['caption_' . $index] = get_string(
                    'maximumchars',
                    '',
                    \mod_photogallery\local\metadata_manager::CAPTION_MAX_LENGTH
                );
            }

            if (
                \core_text::strlen($alttext)
                > \mod_photogallery\local\metadata_manager::ALTTEXT_MAX_LENGTH
            ) {
                $errors['alttext_' . $index] = get_string(
                    'maximumchars',
                    '',
                    \mod_photogallery\local\metadata_manager::ALTTEXT_MAX_LENGTH
                );
            }
        }

        if ($imagecount === 0) {
            return $errors;
        }

        $hascover = (
            $images[0]->get_filearea() === 'cover'
        );

        $minimumposition = $hascover ? 2 : 1;
        $maximumposition = $imagecount;

        foreach ($images as $index => $file) {
            if ($file->get_filearea() === 'cover') {
                continue;
            }

            if (empty($data['moveto_' . $index])) {
                continue;
            }

            $targetposition = (int) (
                $data['targetposition_' . $index] ?? 0
            );

            if (
                $targetposition < $minimumposition
                || $targetposition > $maximumposition
            ) {
                $errors['positiongroup_' . $index] =
                    get_string(
                        'invalidtargetposition',
                        'mod_photogallery',
                        (object) [
                            'minimum' => $minimumposition,
                            'maximum' => $maximumposition,
                        ]
                    );
            }
        }

        return $errors;
    }
}
