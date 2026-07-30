// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// any later version.

/**
 * Photo gallery lightbox.
 *
 * @module     mod_photogallery/lightbox
 * @copyright  2026 Gustavo Soares
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Modal from 'core/modal';
import * as Notification from 'core/notification';
import {getStrings} from 'core/str';

const Selectors = {
    gallery: '[data-region="photogallery"]',
    imageTrigger: '[data-action="open-image"]',
    previousButton: '[data-action="previous-image"]',
    nextButton: '[data-action="next-image"]',
};

let initialised = false;

/**
 * Loads the translated navigation labels.
 *
 * @returns {Promise<string[]>}
 */
const getLabels = () => getStrings([
    {
        key: 'previousimage',
        component: 'mod_photogallery',
    },
    {
        key: 'nextimage',
        component: 'mod_photogallery',
    },
    {
        key: 'lightboxtitle',
        component: 'mod_photogallery',
    },
]);

/**
 * Converts the gallery links into simple image data.
 *
 * @param {HTMLElement} gallery Gallery container.
 * @returns {Array}
 */
const getGalleryImages = gallery => {
    return Array.from(
        gallery.querySelectorAll(Selectors.imageTrigger)
    ).map(element => ({
        url: element.dataset.imageUrl,
        alt: element.dataset.imageAlt || '',
        title: element.dataset.imageTitle || '',
        caption: element.dataset.imageCaption || '',
        trigger: element,
    }));
};

/**
 * Builds the complete lightbox body.
 *
 * @param {Object} image Current image.
 * @param {number} currentIndex Current image index.
 * @param {number} total Total number of images.
 * @param {Object} labels Translated labels.
 * @returns {string}
 */
const buildLightboxBody = (
    image,
    currentIndex,
    total,
    labels
) => {
    const container = document.createElement('div');

    container.className = 'mod-photogallery-lightbox';
    container.dataset.region = 'photogallery-lightbox';

    const figure = document.createElement('figure');

    figure.className =
        'mod-photogallery-lightbox-figure';

    const stage = document.createElement('div');

    stage.className =
        'mod-photogallery-lightbox-stage';

    const photo = document.createElement('img');

    photo.className =
        'mod-photogallery-lightbox-image';

    photo.src = image.url;
    photo.alt = image.alt;

    if (total > 1) {
        const previousButton =
            document.createElement('button');

        previousButton.type = 'button';

        previousButton.className =
            'mod-photogallery-lightbox-control ' +
            'mod-photogallery-lightbox-previous';

        previousButton.dataset.action =
            'previous-image';

        previousButton.setAttribute(
            'aria-label',
            labels.previous
        );

        previousButton.title = labels.previous;
        previousButton.textContent = '‹';

        stage.append(previousButton);
    }

    stage.append(photo);

    if (total > 1) {
        const nextButton =
            document.createElement('button');

        nextButton.type = 'button';

        nextButton.className =
            'mod-photogallery-lightbox-control ' +
            'mod-photogallery-lightbox-next';

        nextButton.dataset.action =
            'next-image';

        nextButton.setAttribute(
            'aria-label',
            labels.next
        );

        nextButton.title = labels.next;
        nextButton.textContent = '›';

        stage.append(nextButton);
    }

    figure.append(stage);

    /*
     * The caption is inserted only here.
     */
    if (image.caption) {
        const caption =
            document.createElement('figcaption');

        caption.className =
            'mod-photogallery-lightbox-caption';

        caption.textContent = image.caption;

        figure.append(caption);
    }

    container.append(figure);

    const counter = document.createElement('div');

    counter.className =
        'mod-photogallery-lightbox-counter';

    counter.setAttribute(
        'aria-live',
        'polite'
    );

    counter.textContent =
        `${currentIndex + 1} / ${total}`;

    container.append(counter);

    return container.outerHTML;
};

/**
 * Opens a gallery image inside a Moodle modal.
 *
 * @param {HTMLElement} trigger Link that opened the modal.
 * @param {Array} images Gallery images.
 * @param {number} initialIndex Initially selected image.
 * @returns {Promise<void>}
 */
const openLightbox = async(
    trigger,
    images,
    initialIndex
) => {
    const [
        previousLabel,
        nextLabel,
        lightboxTitle,
    ] = await getLabels();

    const labels = {
        previous: previousLabel,
        next: nextLabel,
        title: lightboxTitle,
    };

    let currentIndex = initialIndex;
    let currentImage = images[currentIndex];

    const modal = await Modal.create({
        title: labels.title,
        body: buildLightboxBody(
            currentImage,
            currentIndex,
            images.length,
            labels
        ),
        large: true,
        isVerticallyCentered: true,
        scrollable: false,
        removeOnClose: true,
        returnElement: trigger,
    });

    /**
     * Displays the selected image.
     *
     * @param {number} index Requested image index.
     */
    const displayImage = index => {
        /*
         * The calculation makes the navigation circular:
         *
         * - Previous on the first image opens the last image.
         * - Next on the last image opens the first image.
         */
        currentIndex = (
            index + images.length
        ) % images.length;

        currentImage = images[currentIndex];

        modal.setBody(
            buildLightboxBody(
                currentImage,
                currentIndex,
                images.length,
                labels
            )
        );
    };

    const modalRoot = modal.getRoot()[0];

    modalRoot.addEventListener('click', event => {
        if (!(event.target instanceof Element)) {
            return;
        }

        if (event.target.closest(Selectors.previousButton)) {
            displayImage(currentIndex - 1);
            return;
        }

        if (event.target.closest(Selectors.nextButton)) {
            displayImage(currentIndex + 1);
        }
    });

    modalRoot.addEventListener('keydown', event => {
        if (images.length <= 1) {
            return;
        }

        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            displayImage(currentIndex - 1);
        }

        if (event.key === 'ArrowRight') {
            event.preventDefault();
            displayImage(currentIndex + 1);
        }
    });

    modal.show();
};

/**
 * Initialises the lightbox click listener.
 */
export const init = () => {

    if (initialised) {
        return;
    }

    initialised = true;


    document.addEventListener('click', event => {
        if (!(event.target instanceof Element)) {
            return;
        }

        const trigger = event.target.closest(
            Selectors.imageTrigger
        );

        if (!trigger) {
            return;
        }

        const gallery = trigger.closest(Selectors.gallery);

        if (!gallery) {
            return;
        }

        const images = getGalleryImages(gallery);
        const initialIndex = images.findIndex(
            image => image.trigger === trigger
        );

        if (initialIndex === -1) {
            return;
        }

        event.preventDefault();

        openLightbox(
            trigger,
            images,
            initialIndex
        ).catch(Notification.exception);
    });
};