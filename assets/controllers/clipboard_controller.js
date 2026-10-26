import { Controller } from '@hotwired/stimulus';

/* Copia negli appunti il valore del campo «source» e mostra un breve messaggio. */
export default class extends Controller {
    static targets = ['source', 'feedback'];
    static values = { success: { type: String, default: 'Link copiato' } };

    async copy() {
        const value = this.sourceTarget.value;
        try {
            await navigator.clipboard.writeText(value);
            this.feedbackTarget.textContent = this.successValue;
        } catch {
            this.sourceTarget.select();
            this.feedbackTarget.textContent = 'Testo selezionato: copialo manualmente';
        }
    }
}
