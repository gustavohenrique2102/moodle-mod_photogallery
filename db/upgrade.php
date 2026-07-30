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

    return true;
}
