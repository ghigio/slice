import { Controller } from '@hotwired/stimulus';

/*
 * Chiede conferma prima delle azioni successive sullo stesso elemento,
 * es. data-action="confirm#ask live#action" data-confirm-message-param="…".
 */
export default class extends Controller {
    ask(event) {
        if (!window.confirm(event.params.message)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }
}
