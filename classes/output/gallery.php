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
 * Full gallery renderable.
 *
 * @package   mod_photogallery
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_photogallery\output;

use context_module;
use core\output\renderable;
use core\output\renderer_base;
use core\output\templatable;
use moodle_url;
use stdClass;
use stored_file;

/**
 * Prepares the full photo gallery for a Mustache template.
 */
class gallery implements renderable, templatable {
    /**
     * Gallery image files.
     *
     * @var stored_file[]
     */
    private readonly array $files;

    /**
     * Activity module context.
     *
     * @var context_module
     */
    private readonly context_module $context;

    /**
     * Gallery name.
     *
     * @var string
     */
    private readonly string $galleryname;

    /**
     * Image metadata indexed by pathname hash.
     *
     * @var array
     */
    private readonly array $metadata;

    /**
     * Constructor.
     *
     * @param stored_file[] $files Gallery files.
     * @param context_module $context Activity context.
     * @param string $galleryname Gallery name.
     * @param array $metadata Image metadata.
     */
    public function __construct(
        array $files,
        context_module $context,
        string $galleryname,
        array $metadata = []
    ) {
        $this->files = $files;
        $this->context = $context;
        $this->galleryname = $galleryname;
        $this->metadata = $metadata;
    }

    /**
     * Exports data for the gallery Mustache template.
     *
     * @param renderer_base $output Moodle renderer.
     * @return stdClass
     */
    public function export_for_template(renderer_base $output) {
        $data = new stdClass();

        $formattedname = format_string(
            $this->galleryname,
            true,
            ['context' => $this->context]
        );

        $data->hasimages = !empty($this->files);
        $data->images = [];
        $data->noimages = get_string('noimages', 'mod_photogallery');

        $position = 0;

        foreach ($this->files as $file) {
            $position++;

            $originalurl = moodle_url::make_pluginfile_url(
                $file->get_contextid(),
                $file->get_component(),
                $file->get_filearea(),
                $file->get_itemid(),
                $file->get_filepath(),
                $file->get_filename(),
                false
            );

            /*
            * Evita que o navegador mantenha uma versão antiga
            * quando o conteúdo do arquivo for substituído.
            */
            $originalurl->param(
                'oid',
                $file->get_timemodified()
            );

            $previewfile =
                \photogallery_get_resized_preview(
                    $file,
                    $this->context,
                    'grid'
                );

            $thumbnailfile = $previewfile ?? $file;

            $thumbnailurl =
                moodle_url::make_pluginfile_url(
                    $thumbnailfile->get_contextid(),
                    $thumbnailfile->get_component(),
                    $thumbnailfile->get_filearea(),
                    $thumbnailfile->get_itemid(),
                    $thumbnailfile->get_filepath(),
                    $thumbnailfile->get_filename(),
                    false
                );

            $thumbnailurl->param(
                'rev',
                $thumbnailfile->get_timemodified()
            );

            $record = $this->metadata[$file->get_pathnamehash()] ?? null;

            $caption = trim(
                (string) ($record->caption ?? '')
            );

            $alttext = trim(
                (string) ($record->alttext ?? '')
            );

            $fallbackalt = get_string(
                'imagealt',
                'mod_photogallery',
                (object) [
                    'number' => $position,
                    'gallery' => $formattedname,
                ]
            );

            $data->images[] = (object) [

                'url' => $originalurl->out(false),
                'thumbnailurl' => $thumbnailurl->out(false),
                'filename' => $file->get_filename(),

                'displaylabel' => $caption !== ''
                    ? $caption
                    : $file->get_filename(),

                // Uses the manually entered text or the automatic fallback.
                'alt' => $alttext !== ''
                    ? $alttext
                    : $fallbackalt,

                'caption' => $caption,
                'hascaption' => $caption !== '',
            ];
        }

        return $data;
    }
}
