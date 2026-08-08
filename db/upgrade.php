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
 * Photo gallery database upgrades.
 *
 * @package   mod_photogallery
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrades the photo gallery database structure.
 *
 * @param int $oldversion Previously installed plugin version.
 * @return bool
 */
function xmldb_photogallery_upgrade(
    int $oldversion
): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026072700) {
        $table = new xmldb_table(
            'photogallery_image'
        );

        if (!$dbman->table_exists($table)) {
            $table->add_field(
                'id',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL,
                XMLDB_SEQUENCE
            );

            $table->add_field(
                'photogalleryid',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL
            );

            $table->add_field(
                'pathnamehash',
                XMLDB_TYPE_CHAR,
                '40',
                null,
                XMLDB_NOTNULL
            );

            $table->add_field(
                'caption',
                XMLDB_TYPE_TEXT,
                null,
                null,
                null
            );

            $table->add_field(
                'alttext',
                XMLDB_TYPE_TEXT,
                null,
                null,
                null
            );

            $table->add_field(
                'sortorder',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL,
                null,
                '0'
            );

            $table->add_field(
                'timecreated',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL,
                null,
                '0'
            );

            $table->add_field(
                'timemodified',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL,
                null,
                '0'
            );

            $table->add_key(
                'primary',
                XMLDB_KEY_PRIMARY,
                ['id']
            );

            $table->add_key(
                'photogalleryid',
                XMLDB_KEY_FOREIGN,
                ['photogalleryid'],
                'photogallery',
                ['id']
            );

            $table->add_index(
                'gallerypath_uix',
                XMLDB_INDEX_UNIQUE,
                [
                    'photogalleryid',
                    'pathnamehash',
                ]
            );

            $table->add_index(
                'gallerysort_ix',
                XMLDB_INDEX_NOTUNIQUE,
                [
                    'photogalleryid',
                    'sortorder',
                ]
            );

            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(
            true,
            2026072700,
            'photogallery'
        );
    }

    if ($oldversion < 2026080700) {
        $table = new xmldb_table('photogallery_image');
        $field = new xmldb_field(
            'contenthash',
            XMLDB_TYPE_CHAR,
            '40',
            null,
            null,
            null,
            null,
            'pathnamehash'
        );

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        /*
         * Populate the content identity for existing metadata. The joins
         * verify that the referenced file belongs to the same gallery
         * context and to one of its permanent source areas.
         */
        $moduleid = $DB->get_field(
            'modules',
            'id',
            ['name' => 'photogallery'],
            MUST_EXIST
        );

        $sql = "SELECT pgi.id, f.contenthash
                  FROM {photogallery_image} pgi
                  JOIN {photogallery} pg
                    ON pg.id = pgi.photogalleryid
                  JOIN {course_modules} cm
                    ON cm.instance = pg.id
                   AND cm.module = :moduleid
                  JOIN {context} ctx
                    ON ctx.contextlevel = :contextlevel
                   AND ctx.instanceid = cm.id
                  JOIN {files} f
                    ON f.contextid = ctx.id
                   AND f.component = :component
                   AND (f.filearea = :images OR f.filearea = :cover)
                   AND f.itemid = 0
                   AND f.pathnamehash = pgi.pathnamehash
                 WHERE pgi.contenthash IS NULL
                    OR pgi.contenthash = :emptyhash";

        $records = $DB->get_recordset_sql($sql, [
            'moduleid' => $moduleid,
            'contextlevel' => CONTEXT_MODULE,
            'component' => 'mod_photogallery',
            'images' => 'images',
            'cover' => 'cover',
            'emptyhash' => '',
        ]);

        foreach ($records as $record) {
            $DB->set_field(
                'photogallery_image',
                'contenthash',
                $record->contenthash,
                ['id' => $record->id]
            );
        }

        $records->close();

        $DB->set_field_select(
            'photogallery_image',
            'contenthash',
            '',
            'contenthash IS NULL'
        );

        $field = new xmldb_field(
            'contenthash',
            XMLDB_TYPE_CHAR,
            '40',
            null,
            XMLDB_NOTNULL,
            null,
            null,
            'pathnamehash'
        );
        $dbman->change_field_notnull($table, $field);
        $dbman->change_field_default($table, $field);

        /*
         * Version 0.2 declared an empty-string default for name. Remove it
         * so upgraded sites match clean installations.
         */
        $table = new xmldb_table('photogallery');
        $field = new xmldb_field(
            'name',
            XMLDB_TYPE_CHAR,
            '255',
            null,
            XMLDB_NOTNULL,
            null,
            null,
            'course'
        );

        $dbman->change_field_default($table, $field);

        set_config(
            'mediasanitizedversion',
            0,
            'mod_photogallery'
        );
        \mod_photogallery\task\sanitize_existing_media::queue();

        upgrade_mod_savepoint(
            true,
            2026080700,
            'photogallery'
        );
    }

    return true;
}
