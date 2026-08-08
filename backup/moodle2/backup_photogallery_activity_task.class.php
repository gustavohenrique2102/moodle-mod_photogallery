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
 * Photo gallery backup task.
 *
 * @package   mod_photogallery
 * @category  backup
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(
    $CFG->dirroot .
    '/mod/photogallery/backup/moodle2/backup_photogallery_stepslib.php'
);

/**
 * Provides the backup task for one photo gallery activity.
 */
class backup_photogallery_activity_task extends backup_activity_task {
    /**
     * Defines activity-specific backup settings.
     */
    protected function define_my_settings() {
        // No custom backup settings are required.
    }

    /**
     * Defines the backup steps.
     */
    protected function define_my_steps() {
        $this->add_step(
            new backup_photogallery_activity_structure_step(
                'photogallery_structure',
                'photogallery.xml'
            )
        );
    }

    /**
     * Encodes links pointing to this activity.
     *
     * @param string $content Content which may contain activity URLs.
     * @return string
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        $search =
            "/({$base}\/mod\/photogallery\/view\.php\?id=)([0-9]+)/";

        $content = preg_replace(
            $search,
            '$@PHOTOGALLERYVIEWBYID*$2@$',
            $content
        );

        $search =
            "/({$base}\/mod\/photogallery\/editmetadata\.php\?id=)([0-9]+)/";

        $content = preg_replace(
            $search,
            '$@PHOTOGALLERYEDITBYID*$2@$',
            $content
        );

        $search =
            "/({$base}\/mod\/photogallery\/index\.php\?id=)([0-9]+)/";

        $content = preg_replace(
            $search,
            '$@PHOTOGALLERYINDEX*$2@$',
            $content
        );

        return $content;
    }
}
