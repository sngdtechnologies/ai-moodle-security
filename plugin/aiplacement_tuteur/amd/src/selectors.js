// This file is part of Moodle - http://moodle.org/

/**
 * Selecteurs utilises par le module de l'emplacement tuteur.
 *
 * @module     aiplacement_tuteur/selectors
 */
export default {
    ELEMENTS: {
        DRAWER: '#ai-tutor-drawer',
        DRAWER_BODY: '#ai-tutor-drawer .ai-tutor-drawer-body',
        PAGE: '#page',
        JUMPTO: '.ai-tutor-controls [data-region="jumpto"]',
        DRAWER_CLOSE: '#ai-tutor-drawer-close',
        QUESTION_INPUT: '#ai-tutor-question',
        OPEN_BUTTON: '#ai-tutor-open',
    },
    ACTIONS: {
        OPEN: '[data-action="tutor-open"]',
        ASK: '[data-action="tutor-ask"]',
        RETRY: '[data-action="tutor-retry"]',
        REGENERATE: '[data-action="tutor-regenerate"]',
        NEWQUESTION: '[data-action="tutor-newquestion"]',
        CANCEL: '[data-action="tutor-cancel"]',
        DECLINE: '.ai-policy-block [data-action="decline"]',
        ACCEPT: '.ai-policy-block [data-action="accept"]',
    }
};
