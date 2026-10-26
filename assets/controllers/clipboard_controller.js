import { Controller } from '@hotwired/stimulus';

/* Copia negli appunti il valore del campo «source» e mostra un breve messaggio. */
export default class extends Controller {
    static targets = ['source', 'feedback'];

    async copy() {
        const value = this.sourceTarget.value;
        try {
            await navigator.clipboard.writeText(value);
            this.feedbackTarget.textContent = 'Link copiato';
        } catch {
            this.sourceTarget.select();
            this.feedbackTarget.textContent = 'Link selezionato: copialo manualmente';
        }
    }
}
