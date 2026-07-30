# Photo gallery (`mod_photogallery`)

Activity module for Moodle 5.2 that displays photographs as a course-page
mosaic and as a complete gallery with an accessible enlarged viewer.

## Main features

- Multiple image upload and ZIP folder import.
- Optional featured image.
- Configurable course-page mosaic.
- Captions, alternative text, and manual ordering.
- Responsive grid and keyboard-accessible lightbox.
- Generated and cached thumbnails.
- Backup, restore, duplication, and Privacy API support.

## Requirements

- Moodle 5.2 or later (`2026042000`).
- PHP image processing supported by the Moodle installation.

## Installation

Copy the `photogallery` directory to:

```text
public/mod/photogallery
```

Then visit **Site administration > Notifications** or run the Moodle CLI
upgrade script.

## Limits

- Up to 100 photographs per gallery.
- Up to 10 MB per image, subject to the site and course upload limits.
- Up to 200 MB in the gallery image area.
- ZIP imports support JPEG, PNG, GIF, and WebP files.

## Privacy

The plugin does not maintain its own user-linked records. Photographs and their
captions may nevertheless contain personal information, so site administrators
must apply their institutional publication and retention policies.

## License

GNU GPL v3 or later.
