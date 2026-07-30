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
 * Test data generator for the Photo gallery activity.
 *
 * @package   mod_photogallery
 * @category  test
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Test data generator for mod_photogallery.
 */
class mod_photogallery_generator extends testing_module_generator {
    /**
     * Creates a Photo gallery instance for tests.
     *
     * @param array|stdClass|null $record Instance data.
     * @param array|null $options Course module options.
     * @return stdClass
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (array) $record + [
            'previewcount' => 6,
            'images' => 0,
            'coverimage' => 0,
            'importzip' => 0,
        ];

        return parent::create_instance($record, (array) $options);
    }
}
