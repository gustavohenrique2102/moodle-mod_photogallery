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
 * Image metadata updated event.
 *
 * @package   mod_photogallery
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_photogallery\event;

/**
 * Records caption, alternative text and order updates.
 */
final class image_metadata_updated extends \core\event\base {
    /**
     * Initialises the event properties.
     */
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'photogallery';
    }

    /**
     * Returns the localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventimagemetadataupdated', 'mod_photogallery');
    }

    /**
     * Returns a human-readable event description.
     *
     * @return string
     */
    public function get_description(): string {
        return "The user with id '{$this->userid}' updated metadata for the photo gallery "
            . "with id '{$this->objectid}'.";
    }

    /**
     * Returns the metadata editor URL.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/mod/photogallery/editmetadata.php', [
            'id' => $this->contextinstanceid,
        ]);
    }

    /**
     * Defines the object ID mapping used by backup and restore.
     *
     * @return array
     */
    public static function get_objectid_mapping(): array {
        return [
            'db' => 'photogallery',
            'restore' => 'photogallery',
        ];
    }

    /**
     * Indicates that the event's other data contains no record IDs.
     *
     * @return false
     */
    public static function get_other_mapping() {
        return false;
    }

    /**
     * Validates plugin-specific event data.
     */
    protected function validate_data(): void {
        parent::validate_data();

        if (!isset($this->other['imagecount']) || !is_int($this->other['imagecount'])) {
            throw new \core\exception\coding_exception('The image count must be provided.');
        }
        if (!isset($this->other['orderchanged']) || !is_bool($this->other['orderchanged'])) {
            throw new \core\exception\coding_exception('The order change flag must be provided.');
        }
    }
}
