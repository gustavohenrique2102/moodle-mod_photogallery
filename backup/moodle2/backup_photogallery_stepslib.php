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
 * Photo gallery backup structure.
 *
 * @package   mod_photogallery
 * @category  backup
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Defines the complete photo gallery backup structure.
 */
class backup_photogallery_activity_structure_step extends backup_activity_structure_step {
    /**
     * Defines the backup XML structure.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        /*
         * Main activity record.
         */
        $photogallery = new backup_nested_element(
            'photogallery',
            ['id'],
            [
                'name',
                'intro',
                'introformat',
                'previewcount',
                'timecreated',
                'timemodified',
            ]
        );

        /*
         * Container for captions, alternative texts and ordering.
         */
        $imagesmetadata = new backup_nested_element(
            'imagesmetadata'
        );

        /*
         * filearea, filepath and filename are portable identifiers.
         *
         * We cannot restore the original pathnamehash because the
         * restored activity receives a new context ID.
         */
        $image = new backup_nested_element(
            'image',
            ['id'],
            [
                'filearea',
                'filepath',
                'filename',
                'caption',
                'alttext',
                'sortorder',
                'timecreated',
                'timemodified',
            ]
        );

        $photogallery->add_child($imagesmetadata);
        $imagesmetadata->add_child($image);

        /*
         * Main activity source.
         */
        $photogallery->set_source_table(
            'photogallery',
            [
                'id' => backup::VAR_ACTIVITYID,
            ]
        );

        /*
         * Metadata source.
         *
         * The metadata table stores pathnamehash. During backup we join
         * it to the files table to obtain the portable file location.
         */
        $image->set_source_sql(
            'SELECT
                 pgi.id,
                 f.filearea,
                 f.filepath,
                 f.filename,
                 pgi.caption,
                 pgi.alttext,
                 pgi.sortorder,
                 pgi.timecreated,
                 pgi.timemodified
               FROM {photogallery_image} pgi
               JOIN {files} f
                 ON f.pathnamehash = pgi.pathnamehash
              WHERE pgi.photogalleryid = ?
                AND f.contextid = ?
                AND f.component = ?
                AND f.itemid = 0',
            [
                backup::VAR_PARENTID,
                backup::VAR_CONTEXTID,
                backup_helper::is_sqlparam(
                    'mod_photogallery'
                ),
            ]
        );

        /*
         * Permanent files included in the backup.
         *
         * Generated thumbnails are intentionally excluded.
         */
        $photogallery->annotate_files(
            'mod_photogallery',
            'intro',
            null
        );

        $photogallery->annotate_files(
            'mod_photogallery',
            'images',
            null
        );

        $photogallery->annotate_files(
            'mod_photogallery',
            'cover',
            null
        );

        return $this->prepare_activity_structure(
            $photogallery
        );
    }
}
