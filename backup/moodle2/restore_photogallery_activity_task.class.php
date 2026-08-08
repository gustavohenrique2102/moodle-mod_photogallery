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
 * Photo gallery restore task.
 *
 * @package   mod_photogallery
 * @category  backup
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(
    $CFG->dirroot .
    '/mod/photogallery/backup/moodle2/restore_photogallery_stepslib.php'
);

/**
 * Provides the restore task for one photo gallery activity.
 */
class restore_photogallery_activity_task extends restore_activity_task {
    /**
     * Defines activity-specific restore settings.
     */
    protected function define_my_settings() {
        // No custom restore settings are required.
    }

    /**
     * Defines the restore steps.
     */
    protected function define_my_steps() {
        $this->add_step(
            new restore_photogallery_activity_structure_step(
                'photogallery_structure',
                'photogallery.xml'
            )
        );
    }

    /**
     * Defines content fields processed by the link decoder.
     *
     * @return restore_decode_content[]
     */
    public static function define_decode_contents() {
        return [
            new restore_decode_content(
                'photogallery',
                ['intro'],
                'photogallery'
            ),
        ];
    }

    /**
     * Defines the activity link decoding rules.
     *
     * @return restore_decode_rule[]
     */
    public static function define_decode_rules() {
        return [
            new restore_decode_rule(
                'PHOTOGALLERYVIEWBYID',
                '/mod/photogallery/view.php?id=$1',
                'course_module'
            ),

            new restore_decode_rule(
                'PHOTOGALLERYEDITBYID',
                '/mod/photogallery/editmetadata.php?id=$1',
                'course_module'
            ),

            new restore_decode_rule(
                'PHOTOGALLERYINDEX',
                '/mod/photogallery/index.php?id=$1',
                'course'
            ),
        ];
    }

    /**
     * Defines legacy activity log rules used during restore.
     *
     * @return restore_log_rule[]
     */
    public static function define_restore_log_rules() {
        return [
            new restore_log_rule(
                'photogallery',
                'add',
                'view.php?id={course_module}',
                '{photogallery}'
            ),
            new restore_log_rule(
                'photogallery',
                'edit',
                'edit.php?id={course_module}',
                '{photogallery}'
            ),
            new restore_log_rule(
                'photogallery',
                'view',
                'view.php?id={course_module}',
                '{photogallery}'
            ),
        ];
    }

    /**
     * Defines legacy course-level log rules used during restore.
     *
     * @return restore_log_rule[]
     */
    public static function define_restore_log_rules_for_course() {
        return [
            new restore_log_rule(
                'photogallery',
                'view all',
                'index.php?id={course}',
                null
            ),
        ];
    }
}
