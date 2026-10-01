// This file is part of Moodle - http://moodle.org/

/**
 * Module pour afficher et piloter le tiroir de discussion du tuteur IA.
 *
 * @module     aiplacement_tuteur/placement
 */

import Templates from 'core/templates';
import Ajax from 'core/ajax';
import 'core/copy_to_clipboard';
import Notification from 'core/notification';
import Selectors from 'aiplacement_tuteur/selectors';
import Policy from 'core_ai/policy';
import AIHelper from 'core_ai/helper';
import DrawerEvents from 'core/drawer_events';
import {subscribe} from 'core/pubsub';
import * as MessageDrawerHelper from 'core_message/message_drawer_helper';
import * as FocusLock from 'core/local/aria/focuslock';
import {isSmall} from "core/pagehelpers";

const AITutor = class {

    /** @type {Integer} */
    userId;
    /** @type {Integer} */
    contextId;
    /** @type {String} Derniere question envoyee (pour "reposer la question"). */
    lastQuestion = '';
    /** @type {Boolean} Empeche une double soumission concurrente. */
    isAsking = false;
    /** @type {Boolean} */
    isDrawerFocusLocked = false;

    constructor(userId, contextId) {
        this.userId = userId;
        this.contextId = contextId;

        this.drawerElement = document.querySelector(Selectors.ELEMENTS.DRAWER);
        this.drawerBodyElement = document.querySelector(Selectors.ELEMENTS.DRAWER_BODY);
        this.pageElement = document.querySelector(Selectors.ELEMENTS.PAGE);
        this.jumpToElement = document.querySelector(Selectors.ELEMENTS.JUMPTO);
        this.openButtonElement = document.querySelector(Selectors.ELEMENTS.OPEN_BUTTON);
        this.drawerCloseElement = this.drawerElement.querySelector(Selectors.ELEMENTS.DRAWER_CLOSE);

        this.registerEventListeners();
    }

    registerEventListeners() {
        document.addEventListener('click', async(e) => {
            const openAction = e.target.closest(Selectors.ACTIONS.OPEN);
            if (openAction) {
                e.preventDefault();
                this.toggleDrawer();
                if (this.isDrawerOpen()) {
                    const isPolicyAccepted = await this.isPolicyAccepted();
                    if (!isPolicyAccepted) {
                        this.displayPolicy();
                        return;
                    }
                    this.displayAskForm();
                }
            }
        });

        document.addEventListener('keydown', e => {
            if (this.isDrawerOpen() && e.key === 'Escape') {
                this.closeDrawer();
            }
        });

        // Ferme le tiroir du tuteur si le tiroir de messagerie s'ouvre (evite deux tiroirs ouverts).
        subscribe(DrawerEvents.DRAWER_SHOWN, () => {
            if (this.isDrawerOpen()) {
                this.closeDrawer();
            }
        });

        this.jumpToElement.addEventListener('focus', () => {
            this.drawerCloseElement.focus();
        });
    }

    registerPolicyEventListeners() {
        const acceptAction = document.querySelector(Selectors.ACTIONS.ACCEPT);
        const declineAction = document.querySelector(Selectors.ACTIONS.DECLINE);
        if (acceptAction) {
            acceptAction.addEventListener('click', (e) => {
                e.preventDefault();
                this.acceptPolicy().then(() => {
                    return this.displayAskForm();
                }).catch(Notification.exception);
            });
        }
        if (declineAction) {
            declineAction.addEventListener('click', (e) => {
                e.preventDefault();
                this.closeDrawer();
            });
        }
    }

    registerAskEventListeners() {
        const askButton = document.querySelector(Selectors.ACTIONS.ASK);
        const questionInput = document.querySelector(Selectors.ELEMENTS.QUESTION_INPUT);
        if (!askButton || !questionInput) {
            return;
        }
        questionInput.addEventListener('input', () => {
            askButton.disabled = questionInput.value.trim() === '';
        });
        askButton.addEventListener('click', (e) => {
            e.preventDefault();
            this.askQuestion(questionInput.value.trim());
        });
    }

    registerErrorEventListeners() {
        const retryAction = document.querySelector(Selectors.ACTIONS.RETRY);
        if (retryAction) {
            retryAction.addEventListener('click', (e) => {
                e.preventDefault();
                this.askQuestion(this.lastQuestion);
            });
        }
    }

    registerResponseEventListeners() {
        const regenerateAction = document.querySelector(Selectors.ACTIONS.REGENERATE);
        if (regenerateAction) {
            regenerateAction.addEventListener('click', (e) => {
                e.preventDefault();
                this.askQuestion(this.lastQuestion);
            });
        }
        const newQuestionAction = document.querySelector(Selectors.ACTIONS.NEWQUESTION);
        if (newQuestionAction) {
            newQuestionAction.addEventListener('click', (e) => {
                e.preventDefault();
                this.displayAskForm();
            });
        }
    }

    registerLoadingEventListeners() {
        const cancelAction = document.querySelector(Selectors.ACTIONS.CANCEL);
        if (cancelAction) {
            cancelAction.addEventListener('click', (e) => {
                e.preventDefault();
                this.isAsking = false;
                this.displayAskForm();
            });
        }
    }

    isDrawerOpen() {
        return this.drawerElement.classList.contains('show');
    }

    openDrawer() {
        MessageDrawerHelper.hide();
        this.drawerElement.classList.add('show');
        this.drawerElement.setAttribute('tabindex', '0');
        this.drawerBodyElement.setAttribute('aria-live', 'polite');
        this.jumpToElement.setAttribute('tabindex', 0);
        this.jumpToElement.focus();
        if (isSmall()) {
            FocusLock.trapFocus(this.drawerElement);
            this.drawerElement.setAttribute('aria-modal', 'true');
            this.drawerElement.setAttribute('role', 'dialog');
            this.isDrawerFocusLocked = true;
        }
    }

    closeDrawer() {
        if (this.isDrawerFocusLocked) {
            FocusLock.untrapFocus();
            this.drawerElement.removeAttribute('aria-modal');
            this.drawerElement.setAttribute('role', 'region');
            this.isDrawerFocusLocked = false;
        }
        this.drawerElement.classList.remove('show');
        this.drawerElement.setAttribute('tabindex', '-1');
        this.drawerBodyElement.removeAttribute('aria-live');
        this.jumpToElement.setAttribute('tabindex', -1);
        this.openButtonElement.focus();
    }

    toggleDrawer() {
        if (this.isDrawerOpen()) {
            this.closeDrawer();
        } else {
            this.openDrawer();
        }
    }

    async isPolicyAccepted() {
        return await Policy.getPolicyStatus(this.userId);
    }

    acceptPolicy() {
        return Policy.acceptPolicy();
    }

    displayPolicy() {
        Templates.render('core_ai/policyblock', {}).then((html) => {
            this.drawerBodyElement.innerHTML = html;
            this.registerPolicyEventListeners();
            return;
        }).catch(Notification.exception);
    }

    displayAskForm() {
        Templates.render('aiplacement_tuteur/ask', {}).then((html) => {
            this.drawerBodyElement.innerHTML = html;
            this.registerAskEventListeners();
            const questionInput = document.querySelector(Selectors.ELEMENTS.QUESTION_INPUT);
            if (questionInput) {
                questionInput.focus();
            }
            return;
        }).catch(Notification.exception);
    }

    displayLoading() {
        Templates.render('aiplacement_tuteur/loading', {}).then((html) => {
            this.drawerBodyElement.innerHTML = html;
            this.registerLoadingEventListeners();
            return;
        }).catch(Notification.exception);
    }

    /**
     * Envoie une question au tuteur et affiche le resultat.
     *
     * @param {String} question Le texte de la question (deja nettoye des espaces superflus).
     */
    async askQuestion(question) {
        // Garde anti double-soumission (Review Focus 3) : une seule requete en vol a la fois.
        if (this.isAsking || question === '') {
            return;
        }
        this.isAsking = true;
        this.lastQuestion = question;
        this.displayLoading();
        const request = {
            methodname: 'aiplacement_tuteur_generate_text',
            args: {
                contextid: this.contextId,
                prompttext: question,
            }
        };
        try {
            const responseObj = await Ajax.call([request])[0];
            this.isAsking = false;
            if (responseObj.error) {
                this.displayError();
                return;
            }
            const generatedContent = AIHelper.replaceLineBreaks(responseObj.generatedcontent);
            this.displayResponse(question, generatedContent);
        } catch (error) {
            // Echec reseau/exception (Review Focus 4) : jamais de chargement bloque indefiniment.
            this.isAsking = false;
            window.console.log(error);
            this.displayError();
        }
    }

    displayResponse(question, content) {
        Templates.render('aiplacement_tuteur/response', {question: question, content: content}).then((html) => {
            this.drawerBodyElement.innerHTML = html;
            this.registerResponseEventListeners();
            return;
        }).catch(Notification.exception);
    }

    displayError() {
        Templates.render('aiplacement_tuteur/error', {}).then((html) => {
            this.drawerBodyElement.innerHTML = html;
            this.registerErrorEventListeners();
            return;
        }).catch(Notification.exception);
    }
};

export default AITutor;
