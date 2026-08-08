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
 * Ad-hoc gallery preview generation.
 *
 * @package   mod_photogallery
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_photogallery\task;

use core\context\module as context_module;
use core\task\adhoc_task;
use core\task\manager;
use mod_photogallery\local\thumbnail_manager;
use stored_file;

/**
 * Generates bounded image previews outside the web request.
 */
final class generate_previews extends adhoc_task {
    /** Maximum requests accepted in a single worker task. */
    private const MAX_REQUESTS = 200;

    /**
     * Executes queued preview requests.
     *
     * @return void
     */
    #[\Override]
    public function execute(): void {
        $data = $this->get_custom_data();
        $context = \context::instance_by_id(
            (int) ($data->contextid ?? 0),
            IGNORE_MISSING
        );
        if (!$context instanceof context_module) {
            return;
        }

        $requests = is_array($data->requests ?? null)
            ? array_slice($data->requests, 0, self::MAX_REQUESTS)
            : [];
        $filestorage = get_file_storage();

        foreach ($requests as $request) {
            $file = $filestorage->get_file_by_id((int) ($request->fileid ?? 0));
            if (!$file instanceof stored_file) {
                continue;
            }

            thumbnail_manager::generate_preview_now(
                $file,
                $context,
                (string) ($request->mode ?? '')
            );
        }

        thumbnail_manager::cleanup_generated_previews($context);
    }

    /**
     * Queues a canonical, deduplicated batch of preview requests.
     *
     * @param context_module $context Activity context.
     * @param array $requests Arrays containing fileid and mode.
     * @return void
     */
    public static function queue(
        context_module $context,
        array $requests
    ): void {
        $normalised = [];

        foreach ($requests as $request) {
            $fileid = (int) ($request['fileid'] ?? 0);
            $mode = (string) ($request['mode'] ?? '');
            if ($fileid <= 0 || !in_array($mode, ['grid', 'mosaic'], true)) {
                continue;
            }
            $normalised[$fileid . ':' . $mode] = [
                'fileid' => $fileid,
                'mode' => $mode,
            ];
        }

        if (empty($normalised)) {
            return;
        }

        ksort($normalised, SORT_NATURAL);
        foreach (array_chunk(array_values($normalised), self::MAX_REQUESTS) as $batch) {
            $task = new self();
            $task->set_component('mod_photogallery');
            $task->set_custom_data((object) [
                'contextid' => $context->id,
                'requests' => $batch,
            ]);
            manager::queue_adhoc_task($task, true);
        }
    }
}
