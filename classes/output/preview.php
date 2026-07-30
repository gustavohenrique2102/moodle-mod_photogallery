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
 * Course page photo gallery preview.
 *
 * @package   mod_photogallery
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_photogallery\output;

use core\context\module as context_module;
use core\output\renderable;
use core\output\renderer_base;
use core\output\templatable;
use moodle_url;
use stdClass;
use stored_file;

/**
 * Prepares the photo mosaic displayed on the course page.
 */
class preview implements renderable, templatable {
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
     * Full gallery URL.
     *
     * @var moodle_url
     */
    private readonly moodle_url $galleryurl;

    /**
     * Total number of gallery images.
     *
     * @var int
     */
    private readonly int $totalimages;

    /**
     * Image metadata indexed by pathname hash.
     *
     * @var array
     */
    private readonly array $metadata;

    /**
     * Activity introduction.
     *
     * @var string
     */
    private readonly string $intro;

    /**
     * Constructor.
     *
     * @param stored_file[] $files Preview image files.
     * @param context_module $context Activity context.
     * @param string $galleryname Gallery name.
     * @param moodle_url $galleryurl Full gallery URL.
     * @param int $totalimages Total number of images.
     * @param array $metadata Image metadata.
     * @param string $intro Activity introduction.
     */
    public function __construct(
        array $files,
        context_module $context,
        string $galleryname,
        moodle_url $galleryurl,
        int $totalimages,
        array $metadata = [],
        string $intro = ''
    ) {
        $this->files = $files;
        $this->context = $context;
        $this->galleryname = $galleryname;
        $this->galleryurl = $galleryurl;
        $this->totalimages = $totalimages;
        $this->metadata = $metadata;
        $this->intro = $intro;
    }

    /**
     * Exports data for the preview Mustache template.
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

        $visiblecount = count($this->files);
        $remainingcount = max(
            0,
            $this->totalimages - $visiblecount
        );

        $data->hasimages = $visiblecount > 0;
        $data->images = [];
        $data->intro = $this->intro;
        $data->hasintro = trim($this->intro) !== '';

        $data->galleryurl = $this->galleryurl->out(false);
        $data->viewmorelabel = get_string(
            'viewmorephotos',
            'mod_photogallery'
        );

        $data->totalphotoslabel = get_string(
            'totalphotos',
            'mod_photogallery',
            $this->totalimages
        );

        $data->noimages = get_string(
            'noimages',
            'mod_photogallery'
        );

        foreach ($this->files as $position => $file) {
            $number = $position + 1;

            $originalurl = moodle_url::make_pluginfile_url(
                $file->get_contextid(),
                $file->get_component(),
                $file->get_filearea(),
                $file->get_itemid(),
                $file->get_filepath(),
                $file->get_filename(),
                false
            );

            $originalurl->param(
                'oid',
                $file->get_timemodified()
            );

            $previewfile =
                \photogallery_get_resized_preview(
                    $file,
                    $this->context,
                    'mosaic'
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
            $altparameters = (object) [
                'number' => $number,
                'gallery' => $formattedname,
            ];

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
                $altparameters
            );

            $islast = $number === $visiblecount;

            $data->images[] = (object) [
                'url' => $originalurl->out(false),
                'thumbnailurl' => $thumbnailurl->out(false),
                'filename' => $file->get_filename(),
                'alt' => $alttext ?: $fallbackalt,
                'caption' => $caption,
                'hascaption' => $caption !== '',
                'positionclass' => 'position-' . $number,
                'islast' => $islast,
                'showremaining' => $islast && $remainingcount > 0,
                'remaininglabel' => $remainingcount > 0
                    ? get_string(
                        'remainingphotos',
                        'mod_photogallery',
                        $remainingcount
                    )
                    : '',
            ];
        }

        return $data;
    }
}
