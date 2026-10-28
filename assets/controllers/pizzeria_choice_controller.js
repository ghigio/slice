import { Controller } from '@hotwired/stimulus';

/*
 * Creazione della serata: il nome libero della pizzeria serve solo con «Altra pizzeria».
 * Senza JavaScript il campo resta visibile (e viene ignorato se si sceglie una pizzeria dell'elenco).
 */
export default class extends Controller {
    static targets = ['select', 'other'];

    connect() {
        this.toggle();
    }

    toggle() {
        if (this.hasSelectTarget && this.hasOtherTarget) {
            this.otherTarget.hidden = this.selectTarget.value !== '';
        }
    }
}
