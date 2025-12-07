// public/assets/js/core.js

// On s'assure de ne pas écraser un éventuel objet déjà défini
window.UserScan = window.UserScan || {};

/**
 * Analyse une valeur de scan et retourne :
 * - raw       : la chaîne brute
 * - reference : la partie référence
 * - size      : la partie taille (ou null si absente)
 *
 * Exemple :
 *   "ZARA_ETE2025_ROBE_BLEUE-38" → { reference: "ZARA_ETE2025_ROBE_BLEUE", size: "38" }
 *   "ZARA_ETE2025_ROBE_BLEUE"    → { reference: "ZARA_ETE2025_ROBE_BLEUE", size: null }
 */
window.UserScan.parseRefAndSize = function (value) {
    const raw = String(value || '').trim();
    if (!raw) {
        return { raw: '', reference: '', size: null };
    }

    // On prend le dernier "-" comme séparateur REF-Taille
    const sepIndex = raw.lastIndexOf('-');
    if (sepIndex > 0 && sepIndex < raw.length - 1) {
        return {
            raw,
            reference: raw.slice(0, sepIndex),
            size: raw.slice(sepIndex + 1),
        };
    }

    // Pas de taille dans le scan → on considère que c'est juste une ref simple
    return { raw, reference: raw, size: null };
};

// ===============================
// Autocomplétion des références
// ===============================
window.ReferenceAutocomplete = window.ReferenceAutocomplete || {};

/**
 * Attache une autocomplétion à un champ texte.
 *
 * - input        : élément <input>
 * - endpointUrl  : URL de l'endpoint JSON (ex: /autocomplete/product-reference)
 * - datalist     : élément <datalist> partagé
 */
window.ReferenceAutocomplete.initInput = function (input, endpointUrl, datalist) {
    let lastController = null;

    input.addEventListener('input', function () {
        const term = input.value.trim();

        // On évite de spammer le serveur pour 1 caractère
        if (term.length < 2) {
            datalist.innerHTML = '';
            return;
        }

        // Annule une requête précédente si elle est encore en cours
        if (lastController) {
            lastController.abort();
        }
        lastController = new AbortController();

        fetch(endpointUrl + '?term=' + encodeURIComponent(term), {
            signal: lastController.signal,
        })
            .then(response => response.json())
            .then(data => {
                datalist.innerHTML = '';

                data.forEach(item => {
                    const option = document.createElement('option');
                    option.value = item.reference; // ce qui sera vraiment mis dans l'input
                    option.label = item.label || item.reference;
                    option.textContent = item.label || item.reference;
                    datalist.appendChild(option);
                });
            })
            .catch(error => {
                if (error.name === 'AbortError') {
                    // requête annulée (nouvelle frappe)
                    return;
                }
                console.error('Erreur autocomplétion référence:', error);
            });
    });
};

// Auto-initialisation globale : tous les inputs avec data-autocomplete="product-reference"
document.addEventListener('DOMContentLoaded', function () {
    const inputs = document.querySelectorAll('[data-autocomplete="product-reference"]');
    if (!inputs.length) {
        return;
    }

    // On crée un <datalist> partagé si besoin
    let datalist = document.getElementById('product_reference_autocomplete');
    if (!datalist) {
        datalist = document.createElement('datalist');
        datalist.id = 'product_reference_autocomplete';
        document.body.appendChild(datalist);
    }

    inputs.forEach((input) => {
        const endpointUrl = input.dataset.autocompleteUrl || '/autocomplete/product-reference';

        // On relie l'input à ce datalist partagé
        input.setAttribute('list', datalist.id);

        window.ReferenceAutocomplete.initInput(input, endpointUrl, datalist);
    });
});

