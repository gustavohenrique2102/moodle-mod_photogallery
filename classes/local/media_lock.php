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
 * Gallery media mutation lock.
 *
 * @package   mod_photogallery
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_photogallery\local;

use core\lock\lock_config;

/**
 * Serialises activity edits, deletion and background media migration.
 */
final class media_lock {
    /** Maximum time to wait for another media mutation. */
    private const LOCK_TIMEOUT = 30;

    /**
     * Acquires the lock for one course module.
     *
     * @param int $coursemoduleid Course module ID.
     * @return \core\lock\lock
     */
    public static function acquire(int $coursemoduleid): \core\lock\lock {
        if ($coursemoduleid <= 0) {
            throw new \core\exception\invalid_parameter_exception(
                'A valid course module is required for a gallery media mutation.'
            );
        }

        $factory = lock_config::get_lock_factory(
            'mod_photogallery_gallery_media'
        );
        $lock = $factory->get_lock(
            'cm:' . $coursemoduleid,
            self::LOCK_TIMEOUT,
            HOURSECS
        );

        if (!$lock) {
            throw new \moodle_exception(
                'medialockfailed',
                'mod_photogallery'
            );
        }

        return $lock;
    }
}
