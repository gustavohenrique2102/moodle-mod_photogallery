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
 * Activity configuration form for the Photo gallery plugin.
 *
 * @package   mod_photogallery
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once(__DIR__ . '/lib.php');

/**
 * Activity creation and editing form.
 */
class mod_photogallery_mod_form extends moodleform_mod {
    /**
     * Defines the activity form.
     */
    public function definition() {
        $mform = $this->_form;

        // Configurações gerais.
        $mform->addElement(
            'header',
            'general',
            get_string('general', 'form')
        );

        $mform->addElement(
            'text',
            'name',
            get_string('photogalleryname', 'mod_photogallery'),
            ['size' => 64]
        );

        $mform->setType('name', PARAM_TEXT);

        $mform->addRule(
            'name',
            get_string('required'),
            'required',
            null,
            'client'
        );

        $mform->addRule(
            'name',
            get_string('maximumchars', '', 255),
            'maxlength',
            255,
            'client'
        );

        $mform->addHelpButton(
            'name',
            'photogalleryname',
            'mod_photogallery'
        );

        // Adiciona descrição e opção para mostrar a descrição no curso.
        $this->standard_intro_elements();

        // Configurações da galeria.
        $mform->addElement(
            'header',
            'coverheader',
            get_string(
                'coverheading',
                'mod_photogallery'
            )
        );

        $mform->addElement(
            'filemanager',
            'coverimage',
            get_string(
                'coverimage',
                'mod_photogallery'
            ),
            null,
            photogallery_get_cover_filemanager_options(
                $this->_course
            )
        );

        $mform->addHelpButton(
            'coverimage',
            'coverimage',
            'mod_photogallery'
        );

        $mform->addElement(
            'header',
            'gallerysettings',
            get_string('gallerysettings', 'mod_photogallery')
        );

        $previewoptions = [
            3 => get_string('previewphotos', 'mod_photogallery', 3),
            4 => get_string('previewphotos', 'mod_photogallery', 4),
            5 => get_string('previewphotos', 'mod_photogallery', 5),
            6 => get_string('previewphotos', 'mod_photogallery', 6),
            8 => get_string('previewphotos', 'mod_photogallery', 8),
            9 => get_string('previewphotos', 'mod_photogallery', 9),
            12 => get_string('previewphotos', 'mod_photogallery', 12),
        ];

        $mform->addElement(
            'select',
            'previewcount',
            get_string('previewcount', 'mod_photogallery'),
            $previewoptions
        );

        $mform->setDefault('previewcount', 6);

        $mform->addHelpButton(
            'previewcount',
            'previewcount',
            'mod_photogallery'
        );

        // Gerenciador de imagens.
        $mform->addElement(
            'filemanager',
            'images',
            get_string('images', 'mod_photogallery'),
            null,
            photogallery_get_filemanager_options($this->_course)
        );

        $mform->addHelpButton(
            'images',
            'images',
            'mod_photogallery'
        );

        $mform->addElement(
            'static',
            'batchuploadinfo',
            '',
            get_string('batchuploadinfo', 'mod_photogallery')
        );

        $mform->addElement(
            'static',
            'importseparator',
            '',
            get_string('importseparator', 'mod_photogallery')
        );

        $mform->addElement(
            'filepicker',
            'importzip',
            get_string('importzip', 'mod_photogallery'),
            null,
            photogallery_get_zip_filepicker_options($this->_course)
        );

        $mform->addHelpButton(
            'importzip',
            'importzip',
            'mod_photogallery'
        );

        $mform->setExpanded('gallerysettings');

        // Configurações padrão de uma atividade Moodle.
        $this->standard_coursemodule_elements();

        // Botões Salvar e Cancelar.
        $this->add_action_buttons();
    }

    /**
     * Validates submitted settings and draft media on the server.
     *
     * @param array $data Submitted values.
     * @param array $files Submitted files.
     * @return array Field errors.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = get_string('required');
        } else if (\core_text::strlen($name) > 255) {
            $errors['name'] = get_string('maximumchars', '', 255);
        }

        $imagesdraftid = (int) ($data['images'] ?? 0);
        $coverdraftid = (int) ($data['coverimage'] ?? 0);
        $zipdraftid = (int) ($data['importzip'] ?? 0);
        $galleryoptions = photogallery_get_filemanager_options($this->_course);

        $imagestats = $this->validate_image_draft(
            'images',
            $imagesdraftid,
            $galleryoptions,
            $errors
        );
        $coverstats = $this->validate_image_draft(
            'coverimage',
            $coverdraftid,
            photogallery_get_cover_filemanager_options($this->_course),
            $errors
        );

        [$imagecount, $imagebytes] = $this->get_draft_totals($imagesdraftid);
        [$covercount, $coverbytes] = $this->get_draft_totals($coverdraftid);
        $totalcount = $imagecount + $covercount;
        $totalbytes = $imagebytes + $coverbytes;
        $totalpixels = $imagestats['pixels'] + $coverstats['pixels'];

        $maxfiles = (int) ($galleryoptions['maxfiles'] ?? 0);
        $areamaxbytes = (int) ($galleryoptions['areamaxbytes'] ?? 0);

        if ($maxfiles > 0 && $totalcount > $maxfiles) {
            $errors['images'] = get_string('toomanyimages', 'mod_photogallery', $maxfiles);
        }
        if ($areamaxbytes > 0 && $totalbytes > $areamaxbytes) {
            $errors['images'] = get_string(
                'galleryareatoolarge',
                'mod_photogallery',
                display_size($areamaxbytes)
            );
        }
        if ($totalpixels > \mod_photogallery\local\image_validator::MAX_TOTAL_PIXELS) {
            $errors['images'] = get_string(
                'imagetotalpixelstoolarge',
                'mod_photogallery',
                (int) (\mod_photogallery\local\image_validator::MAX_TOTAL_PIXELS / 1000000)
            );
        }

        if ($zipdraftid > 0) {
            try {
                \mod_photogallery\local\zip_importer::validate(
                    $zipdraftid,
                    $this->_course,
                    $totalcount,
                    $totalbytes,
                    $totalpixels
                );
            } catch (\moodle_exception $exception) {
                $errors['importzip'] = $exception->getMessage();
            }
        }

        return $errors;
    }

    /**
     * Maps media validation exceptions to the relevant form field.
     *
     * @param string $field Form field name.
     * @param int $draftitemid Draft item ID.
     * @param array $options Filemanager options.
     * @param array $errors Current validation errors.
     * @return array{count: int, bytes: int, pixels: int} Validated draft statistics.
     */
    private function validate_image_draft(
        string $field,
        int $draftitemid,
        array $options,
        array &$errors
    ): array {
        try {
            return \mod_photogallery\local\image_validator::get_draft_stats(
                $draftitemid,
                $options
            );
        } catch (\moodle_exception $exception) {
            $errors[$field] = $exception->getMessage();
            return [
                'count' => 0,
                'bytes' => 0,
                'pixels' => 0,
            ];
        }
    }

    /**
     * Counts files and bytes in a user draft area.
     *
     * @param int $draftitemid Draft item ID.
     * @return int[] File count and total bytes.
     */
    private function get_draft_totals(int $draftitemid): array {
        global $USER;

        if ($draftitemid <= 0) {
            return [0, 0];
        }

        $usercontext = \core\context\user::instance($USER->id, MUST_EXIST);
        $draftfiles = get_file_storage()->get_area_files(
            $usercontext->id,
            'user',
            'draft',
            $draftitemid,
            'id ASC',
            false
        );

        $totalbytes = array_sum(array_map(
            static fn(stored_file $file): int => $file->get_filesize(),
            $draftfiles
        ));

        return [count($draftfiles), $totalbytes];
    }

    /**
     * Prepares existing gallery images for the file manager.
     *
     * @param array $defaultvalues Existing activity values.
     */
    public function data_preprocessing(&$defaultvalues) {
        if (empty($this->current->instance)) {
            return;
        }

        $coverdraftitemid =
            file_get_submitted_draft_itemid(
                'coverimage'
            );

        file_prepare_draft_area(
            $coverdraftitemid,
            $this->context->id,
            'mod_photogallery',
            'cover',
            0,
            photogallery_get_cover_filemanager_options(
                $this->_course
            )
        );

        $defaultvalues['coverimage'] =
            $coverdraftitemid;

        $draftitemid = file_get_submitted_draft_itemid('images');

        file_prepare_draft_area(
            $draftitemid,
            $this->context->id,
            'mod_photogallery',
            'images',
            0,
            photogallery_get_filemanager_options($this->_course)
        );

        $defaultvalues['images'] = $draftitemid;
    }
}
