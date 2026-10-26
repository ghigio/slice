import { Controller } from '@hotwired/stimulus';

/*
 * Quando il nome digitato corrisponde a una pizza già inserita nella serata,
 * propone l'ultimo prezzo usato (solo se il campo prezzo è vuoto).
 * La normalizzazione replica NameNormalizer::normalize lato server.
 */
export default class extends Controller {
    static targets = ['name', 'price'];
    static values = { prices: Object };

    suggest() {
        const key = this.nameTarget.value.normalize('NFD').replace(/\p{Mn}/gu, '').replace(/\s+/g, ' ').trim().toLowerCase();
        const price = this.pricesValue[key];
        if (price && this.priceTarget.value.trim() === '') {
            this.priceTarget.value = price;
            this.priceTarget.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }
}
