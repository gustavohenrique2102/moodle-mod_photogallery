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
    newWindowNotice: '[data-region="new-window-notice"]',
    remainingPhotosNotice: '[data-region="remaining-photos-notice"]',
    lightboxImage: '[data-region="lightbox-image"]',
    lightboxCaption: '[data-region="lightbox-caption"]',
    lightboxCounter: '[data-region="lightbox-counter"]',
    previousButton: '[data-action="previous-image"]',
    nextButton: '[data-action="next-image"]',
};

let initialised = false;

/**
 * Loads translated labels, including a localised position for each image.
 *
 * @param {number} total Total number of images.
 * @returns {Promise<Object>}
 */
const getLabels = async total => {
    const requests = [
        {key: 'previousimage', component: 'mod_photogallery'},
        {key: 'nextimage', component: 'mod_photogallery'},
        {key: 'lightboxtitle', component: 'mod_photogallery'},
    ];

    for (let index = 0; index < total; index++) {
        requests.push({
            key: 'imageposition',
            component: 'mod_photogallery',
            param: {
                current: index + 1,
                total,
            },
        });
    }

    const strings = await getStrings(requests);
    return {
        previous: strings[0],
        next: strings[1],
        title: strings[2],
        positions: strings.slice(3),
    };
};

/**
 * Converts the gallery links into simple image data.
 *
 * @param {HTMLElement} gallery Gallery container.
 * @returns {Array}
 */
const getGalleryImages = gallery => Array.from(
    gallery.querySelectorAll(Selectors.imageTrigger)
).map(element => ({
    url: element.dataset.imageUrl || element.href,
    alt: element.dataset.imageAlt || '',
    caption: element.dataset.imageCaption || '',
    trigger: element,
})).filter(image => image.url);

/**
 * Marks a link as enhanced while preserving its normal href fallback.
 *
 * @param {HTMLElement} trigger Image link.
 */
const enhanceTrigger = trigger => {
    trigger.removeAttribute('target');
    trigger.setAttribute('aria-haspopup', 'dialog');
    trigger.querySelector(Selectors.newWindowNotice)?.remove();
    trigger.querySelector(Selectors.remainingPhotosNotice)?.removeAttribute('hidden');
};

/**
 * Builds the persistent lightbox DOM.
 *
 * @param {Object} image Current image.
 * @param {number} currentIndex Current image index.
 * @param {number} total Total number of images.
 * @param {Object} labels Translated labels.
 * @returns {string}
 */
const buildLightboxBody = (image, currentIndex, total, labels) => {
    const container = document.createElement('div');
    container.className = 'mod-photogallery-lightbox';
    container.dataset.region = 'photogallery-lightbox';

    const figure = document.createElement('figure');
    figure.className = 'mod-photogallery-lightbox-figure';

    const stage = document.createElement('div');
    stage.className = 'mod-photogallery-lightbox-stage';

    if (total > 1) {
        const previousButton = document.createElement('button');
        previousButton.type = 'button';
        previousButton.className = 'mod-photogallery-lightbox-control mod-photogallery-lightbox-previous';
        previousButton.dataset.action = 'previous-image';
        previousButton.setAttribute('aria-label', labels.previous);
        previousButton.title = labels.previous;
        previousButton.textContent = '\u2039';
        stage.append(previousButton);
    }

    const photo = document.createElement('img');
    photo.className = 'mod-photogallery-lightbox-image';
    photo.dataset.region = 'lightbox-image';
    photo.src = image.url;
    photo.alt = image.alt;
    photo.decoding = 'async';
    stage.append(photo);

    if (total > 1) {
        const nextButton = document.createElement('button');
        nextButton.type = 'button';
        nextButton.className = 'mod-photogallery-lightbox-control mod-photogallery-lightbox-next';
        nextButton.dataset.action = 'next-image';
        nextButton.setAttribute('aria-label', labels.next);
        nextButton.title = labels.next;
        nextButton.textContent = '\u203a';
        stage.append(nextButton);
    }

    figure.append(stage);

    const caption = document.createElement('figcaption');
    caption.className = 'mod-photogallery-lightbox-caption';
    caption.dataset.region = 'lightbox-caption';
    caption.textContent = image.caption;
    caption.hidden = !image.caption;
    figure.append(caption);
    container.append(figure);

    const counter = document.createElement('div');
    counter.className = 'mod-photogallery-lightbox-counter';
    counter.dataset.region = 'lightbox-counter';
    counter.setAttribute('role', 'status');
    counter.setAttribute('aria-live', 'polite');
    counter.setAttribute('aria-atomic', 'true');
    counter.textContent = labels.positions[currentIndex];
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
const openLightbox = async(trigger, images, initialIndex) => {
    const labels = await getLabels(images.length);
    let currentIndex = initialIndex;

    const modal = await Modal.create({
        title: labels.title,
        body: buildLightboxBody(images[currentIndex], currentIndex, images.length, labels),
        large: true,
        isVerticallyCentered: true,
        scrollable: false,
        removeOnClose: true,
        returnElement: trigger,
    });

    const modalRoot = modal.getRoot()[0];
    const photo = modalRoot.querySelector(Selectors.lightboxImage);
    const caption = modalRoot.querySelector(Selectors.lightboxCaption);
    const counter = modalRoot.querySelector(Selectors.lightboxCounter);

    /**
     * Updates stable nodes so keyboard focus and the live region survive navigation.
     *
     * @param {number} index Requested image index.
     */
    const displayImage = index => {
        currentIndex = (index + images.length) % images.length;
        const image = images[currentIndex];

        photo.src = image.url;
        photo.alt = image.alt;
        caption.textContent = image.caption;
        caption.hidden = !image.caption;
        counter.textContent = labels.positions[currentIndex];
    };

    modalRoot.addEventListener('click', event => {
        if (!(event.target instanceof Element)) {
            return;
        }

        if (event.target.closest(Selectors.previousButton)) {
            event.preventDefault();
            displayImage(currentIndex - 1);
        } else if (event.target.closest(Selectors.nextButton)) {
            event.preventDefault();
            displayImage(currentIndex + 1);
        }
    });

    modalRoot.addEventListener('keydown', event => {
        if (images.length <= 1 || event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }

        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            displayImage(currentIndex - 1);
        } else if (event.key === 'ArrowRight') {
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

    document.querySelectorAll(Selectors.gallery).forEach(gallery => {
        gallery.querySelectorAll(Selectors.imageTrigger).forEach(trigger => {
            enhanceTrigger(trigger);
        });
        gallery.classList.add('is-lightbox-enhanced');
    });

    document.addEventListener('click', event => {
        if (
            event.defaultPrevented
            || event.button !== 0
            || event.altKey
            || event.ctrlKey
            || event.metaKey
            || event.shiftKey
            || !(event.target instanceof Element)
        ) {
            return;
        }

        const trigger = event.target.closest(Selectors.imageTrigger);
        if (!trigger) {
            return;
        }

        const gallery = trigger.closest(Selectors.gallery);
        if (!gallery) {
            return;
        }

        enhanceTrigger(trigger);
        gallery.classList.add('is-lightbox-enhanced');
        const images = getGalleryImages(gallery);
        const initialIndex = images.findIndex(image => image.trigger === trigger);
        if (initialIndex === -1) {
            return;
        }

        event.preventDefault();
        openLightbox(trigger, images, initialIndex).catch(async error => {
            try {
                await Notification.exception(error);
            } finally {
                window.location.assign(trigger.href);
            }
        });
    });
};
