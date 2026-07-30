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
 * Photo gallery restore structure.
 *
 * @package   mod_photogallery
 * @category  backup
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restores one photo gallery activity.
 */
class restore_photogallery_activity_structure_step extends restore_activity_structure_step {
    /**
     * Metadata waiting for the related files to be restored.
     *
     * @var stdClass[]
     */
    private $pendingmetadata = [];

    /**
     * ID of the restored gallery instance.
     *
     * @var int
     */
    private $newphotogalleryid = 0;

    /**
     * Defines the XML paths processed during restoration.
     *
     * @return restore_path_element[]
     */
    protected function define_structure() {
        return $this->prepare_activity_structure([
            new restore_path_element(
                'photogallery',
                '/activity/photogallery'
            ),

            new restore_path_element(
                'photogallery_image',
                '/activity/photogallery/imagesmetadata/image'
            ),
        ]);
    }

    /**
     * Restores the main gallery record.
     *
     * @param array $data Backup data.
     */
    protected function process_photogallery($data) {
        global $DB;

        $data = (object) $data;

        $data->course = $this->get_courseid();

        /*
         * Backwards compatibility for backups created before
         * previewcount existed.
         */
        if (!isset($data->previewcount)) {
            $data->previewcount = 6;
        }

        $newitemid = $DB->insert_record(
            'photogallery',
            $data
        );

        $this->newphotogalleryid = (int) $newitemid;

        /*
         * Must be called immediately after the activity record
         * has been created.
         */
        $this->apply_activity_instance(
            $newitemid
        );
    }

    /**
     * Temporarily stores image metadata.
     *
     * The files do not yet exist in the restored context when
     * this processing method runs.
     *
     * @param array $data Image metadata.
     */
    protected function process_photogallery_image(
        $data
    ) {
        $data = (object) $data;

        unset($data->id);

        $this->pendingmetadata[] = $data;
    }

    /**
     * Restores files and reconnects metadata to their new hashes.
     */
    protected function after_execute() {
        global $CFG, $DB;

        /*
         * Restore the permanent file areas.
         */
        $this->add_related_files(
            'mod_photogallery',
            'intro',
            null
        );

        $this->add_related_files(
            'mod_photogallery',
            'images',
            null
        );

        $this->add_related_files(
            'mod_photogallery',
            'cover',
            null
        );

        if ($this->newphotogalleryid <= 0) {
            return;
        }

        $modulecontext =
            \core\context\module::instance(
                $this->task->get_moduleid()
            );

        $filestorage = get_file_storage();

        $allowedfileareas = [
            'images',
            'cover',
        ];

        $now = time();

        foreach ($this->pendingmetadata as $metadata) {
            $filearea = (string) (
                $metadata->filearea ?? ''
            );

            if (
                !in_array(
                    $filearea,
                    $allowedfileareas,
                    true
                )
            ) {
                continue;
            }

            $filepath = (string) (
                $metadata->filepath ?? '/'
            );

            if ($filepath === '') {
                $filepath = '/';
            }

            if (!str_starts_with($filepath, '/')) {
                $filepath = '/' . $filepath;
            }

            if (!str_ends_with($filepath, '/')) {
                $filepath .= '/';
            }

            $filename = (string) (
                $metadata->filename ?? ''
            );

            if ($filename === '') {
                continue;
            }

            /*
             * Locate the file in the new activity context.
             */
            $file = $filestorage->get_file(
                $modulecontext->id,
                'mod_photogallery',
                $filearea,
                0,
                $filepath,
                $filename
            );

            if (
                !$file instanceof stored_file
                || $file->is_directory()
            ) {
                continue;
            }

            $pathnamehash =
                $file->get_pathnamehash();

            /*
             * Defensive check against malformed or duplicated
             * entries in an imported backup.
             */
            if (
                $DB->record_exists(
                    'photogallery_image',
                    [
                    'photogalleryid' =>
                        $this->newphotogalleryid,

                    'pathnamehash' =>
                        $pathnamehash,
                    ]
                )
            ) {
                continue;
            }

            $record = (object) [
                'photogalleryid' =>
                    $this->newphotogalleryid,

                'pathnamehash' =>
                    $pathnamehash,

                'caption' => trim(
                    (string) (
                        $metadata->caption ?? ''
                    )
                ),

                'alttext' => trim(
                    (string) (
                        $metadata->alttext ?? ''
                    )
                ),

                'sortorder' => (int) (
                    $metadata->sortorder ?? 0
                ),

                'timecreated' => (int) (
                    $metadata->timecreated ?? $now
                ),

                'timemodified' => (int) (
                    $metadata->timemodified ?? $now
                ),
            ];

            $DB->insert_record(
                'photogallery_image',
                $record
            );
        }

        /*
         * Generated thumbnails are not included in the backup.
         * Recreate the missing grid and mosaic previews now.
         */
        require_once(
            $CFG->dirroot .
            '/mod/photogallery/lib.php'
        );

        $previewcount = (int) $DB->get_field(
            'photogallery',
            'previewcount',
            [
                'id' =>
                    $this->newphotogalleryid,
            ],
            MUST_EXIST
        );

        photogallery_generate_missing_previews(
            $this->newphotogalleryid,
            $modulecontext,
            $previewcount
        );
    }
}
