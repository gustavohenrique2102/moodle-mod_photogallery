[![Moodle Plugin CI](https://github.com/gustavohenrique2102/moodle-mod_photogallery/actions/workflows/moodle-ci.yml/badge.svg)](https://github.com/gustavohenrique2102/moodle-mod_photogallery/actions/workflows/moodle-ci.yml)

# Photo gallery (`mod_photogallery`)

Activity module for Moodle 5.2 that displays photographs as a course-page
mosaic and as a complete gallery with an accessible enlarged viewer.

## Main features

- Multiple image upload and ZIP folder import.
- Optional featured image.
- Configurable course-page mosaic.
- Captions, alternative text, and manual ordering from the metadata editor.
- Responsive grid and keyboard-accessible lightbox.
- Generated and cached thumbnails.
- Backup, restore, duplication, and Privacy API support.

## Requirements

- Moodle 5.2 or later (`2026042000`).
- PHP GD with JPEG, PNG, and WebP decoding/encoding support.
- PHP `zip`/`ZipArchive` for compressed-folder imports.
- PHP `exif` is recommended for JPEG orientation; a bounded built-in EXIF
  orientation parser is used when the extension is unavailable.

## Installation

Install a clean release ZIP, or copy a clean exported `photogallery` directory
to:

```text
public/mod/photogallery
```

Then visit **Site administration > Notifications** or run the Moodle CLI
upgrade script.

Never publish a working Git checkout below the web document root. In
particular, `.git`, `.svn`, and `.hg` directories must not be present in the
deployed plugin. Build releases with `git archive` (or an equivalent CI
artifact step), and also deny access to version-control and other dotfiles in
the web-server configuration.

## Limits

- Up to 100 photographs per gallery, including the featured image.
- Up to 10 MB per image, subject to the site and course upload limits.
- Up to 200 MB for all gallery photographs combined, including the featured
  image and images selected from a ZIP import.
- Direct uploads and ZIP imports support static JPEG, PNG, and WebP files.
- SVG and animated formats such as GIF, APNG, and animated WebP are rejected.

Image dimensions, decoded pixel count, signature, extension, and MIME type are
validated on the server. Accepted images are re-encoded before permanent
storage. JPEG orientation is applied and embedded EXIF/GPS, IPTC, XMP,
comments, and animation metadata are discarded. Administrators should still
keep the server image library patched and choose upload limits appropriate for
their infrastructure.

The display order is managed in **Manage gallery**, alongside captions and
alternative text. The featured image always remains first.

## Privacy

The plugin does not maintain its own user-linked records. Re-encoding removes
embedded camera and location metadata, but the visible photograph and its
caption may still contain personal information. Site administrators must apply
their institutional publication, consent, access, and retention policies.

## License

GNU GPL v3 or later.
