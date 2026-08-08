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
 * English language strings for the Photo gallery activity.
 *
 * @package   mod_photogallery
 * @copyright 2026 Gustavo Soares
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['animatedimagenotsupported'] =
    'The photograph "{$a}" is animated. Only static JPEG, PNG, and WebP images are supported.';
$string['backtogallery'] =
    'Back to gallery';
$string['batchuploadinfo'] =
    'To upload several photographs at once, select the files on your computer and drag them all into the area above.';
$string['coverheading'] =
    'Featured image';
$string['coverimage'] =
    'Featured image';
$string['coverimage_help'] =
    'This image will be displayed first and will occupy the main position in the mosaic. If no image is added, the first photograph in the gallery will be used automatically.';
$string['editmetadata'] =
    'Manage gallery';
$string['editmetadatatitle'] =
    'Manage gallery: {$a}';
$string['eventimagemetadataupdated'] =
    'Image metadata updated';
$string['featuredimagefixed'] =
    'The featured image remains in the first position. To replace it, edit the gallery settings.';
$string['featuredimageposition'] =
    'Position 1 — fixed featured image';
$string['galleryareatoolarge'] =
    'The gallery photographs exceed the combined storage limit of {$a}.';
$string['gallerysettings'] = 'Gallery settings';
$string['imagealt'] =
    'Photograph {$a->number} from the {$a->gallery} gallery';
$string['imagedimensionstoolarge'] =
    'The photograph "{$a->filename}" exceeds the limit of {$a->maxdimension} pixels per side or {$a->maxmegapixels} megapixels.';
$string['imageposition'] =
    'Photograph {$a->current} of {$a->total}';
$string['imageprocessingunavailable'] =
    'The server cannot safely process the image format used by "{$a}".';
$string['images'] = 'Photographs';
$string['images_help'] =
    'Upload static JPEG, PNG, or WebP photographs. A gallery can contain up to 100 photographs including the featured image, with a limit of 10 MB per file and 200 MB in total. Manage the display order in “Manage gallery”.';
$string['imagetoolarge'] =
    'The photograph "{$a}" exceeds the allowed file size.';
$string['imagetotalpixelstoolarge'] =
    'The selected photographs exceed the combined decoded-image limit of {$a} megapixels.';
$string['importseparator'] = 'OR';
$string['importzip'] = 'Import compressed folder';
$string['importzip_help'] =
    'Compress static JPEG, PNG, or WebP photographs as a ZIP file and upload it. Compatible images will be validated and added automatically; the ZIP file will not be stored.';
$string['invalidimage'] =
    'The file "{$a}" is not a valid static JPEG, PNG, or WebP photograph.';
$string['invalidtargetposition'] =
    'Enter a position between {$a->minimum} and {$a->maximum}.';
$string['invalidzip'] =
    'The uploaded file is not a valid ZIP archive.';
$string['invalidzipimage'] =
    'The file "{$a}" has an image extension, but its content is not a valid photograph.';
$string['lightboxtitle'] = 'Photograph viewer';
$string['managephotos'] = 'Manage photos';
$string['managephotosintro'] =
    'Add new photographs, remove existing images, or import a compressed folder. To upload multiple images at once, select the files on your computer and drag them all into the photographs field.';
$string['managephotosnotice'] =
    'Photographs removed from this file manager will be deleted from the gallery after you save the changes.';
$string['managephotostitle'] =
    'Manage photos: {$a}';
$string['mediaconflict'] =
    'The photograph was replaced while it was being processed. No changes were made.';
$string['medialockfailed'] =
    'The gallery is being updated by another process. Please wait and try again.';
$string['metadataconflict'] =
    'The gallery changed while this page was open. Your changes were not saved. Review the current values and try again.';
$string['metadataintro'] =
    'Add a caption, alternative text for people who use screen readers, and set the display order. The featured image always remains first.';
$string['metadatalockfailed'] =
    'The gallery is currently being changed by another request. Wait a moment and try again.';
$string['metadataupdated'] =
    'The captions and alternative text have been updated.';
$string['metadatavaluetoolong'] =
    'This metadata value cannot contain more than {$a} characters.';
$string['modulename'] = 'Photo gallery';
$string['modulename_help'] =
    '<h4>Key features</h4>
    <p>Allows several photographs to be uploaded, one image to be featured, and the collection to be displayed as a mosaic, grid, and enlarged viewer.</p>

    <h4>Ways to use it</h4>
    <p>The gallery can be used for event records, institutional presentations, highlights, visual announcements and educational content.</p>';
$string['modulename_summary'] =
    'Displays photographs in a course-page mosaic and a complete gallery with an enlarged viewer.';
$string['modulename_tip'] =
    'Add objective alternative text and useful captions to make the gallery more accessible.';
$string['modulenameplural'] = 'Photo galleries';
$string['movephotodown'] =
    '↓ Move down';
$string['movephotoup'] =
    '↑ Move up';
$string['movetoposition'] =
    'Move';
$string['nextimage'] = 'Next photograph';
$string['noautocompletioninline'] =
    'Completion based on viewing cannot be used because this activity displays its photographs directly on the course page.';
$string['noimages'] = 'No photographs have been added to this gallery.';
$string['nophotosmetadata'] =
    'The gallery does not yet contain photographs to edit.';
$string['photoalttext'] =
    'Alternative text';
$string['photoalttext_help'] =
    'Objectively describe the visual content of the photograph for people who use screen readers.';
$string['photocaption'] =
    'Caption';
$string['photocaption_help'] =
    'Visible text that identifies or provides context for the photograph. The caption may be displayed below the image or in the enlarged viewer.';
$string['photogallery:addinstance'] = 'Add a new photo gallery';
$string['photogallery:manage'] = 'Manage the gallery photos';
$string['photogallery:view'] = 'View the photo gallery';
$string['photogalleryname'] = 'Gallery name';
$string['photogalleryname_help'] =
    'Enter a name identifying the event, course, or set of photographs.';
$string['photoitem'] =
    'Photograph {$a}';
$string['photoorder'] =
    'Photograph order';
$string['photoorderupdated'] =
    'The photograph order has been updated.';
$string['photosimported'] =
    '{$a} photographs were imported from the ZIP archive.';
$string['photosupdated'] =
    'The gallery photographs have been updated.';
$string['pluginadministration'] = 'Photo gallery administration';
$string['pluginname'] = 'Photo gallery';
$string['previewcount'] = 'Photos displayed in the mosaic';
$string['previewcount_help'] =
    'Defines how many photographs are displayed directly on the course page or site home page. All other photographs remain available on the full gallery page.';
$string['previewphotos'] = '{$a} photos';
$string['previousimage'] = 'Previous photograph';
$string['privacy:metadata'] =
    'The Photo gallery activity stores only the gallery settings, photographs, and image metadata, without associating them with a specific user.';
$string['remainingphotos'] = '+{$a}';
$string['remainingphotosaccessible'] =
    '{$a} more photographs are available in this viewer.';
$string['savemetadata'] =
    'Save captions and accessibility';
$string['savephotos'] =
    'Save photo changes';
$string['targetposition'] =
    'New position';
$string['targetposition_help'] =
    'Enter the position where the photograph should appear and click “Move”. The other photographs will be reorganised automatically.';
$string['taskgeneratepreviews'] = 'Generate Photo gallery previews';
$string['toomanyimages'] =
    'The gallery can contain no more than {$a} photographs.';
$string['totalphotos'] = '{$a} photographs';
$string['viewmorephotos'] = 'View more photos';
$string['zipareatoolarge'] =
    'The imported photographs would exceed the gallery storage limit of {$a}.';
$string['zipcompressionratio'] =
    'The compressed entry "{$a}" has an unsafe compression ratio.';
$string['zipimagetoolarge'] =
    'The photograph "{$a}" exceeds the allowed file size.';
$string['zipinvalidpath'] =
    'The ZIP entry "{$a}" has an invalid or unsafe path.';
$string['zipnoimages'] =
    'The ZIP archive does not contain compatible photographs.';
$string['ziptoomanyentries'] =
    'The ZIP archive contains more than {$a} entries.';
