document.addEventListener('DOMContentLoaded', function () {
    const refInput = document.querySelector('#add_stock_reference');
    const sizesContainer = document.querySelector('#add_stock_sizes_buttons');
    const sizeHiddenInput = document.querySelector('#add_stock_size_id');
    const submitBtn = document.querySelector('#add_stock_submit_btn');

    // Si on n'est pas sur la page d'ajout de stock, on ne fait rien
    if (!refInput || !sizesContainer || !sizeHiddenInput || !submitBtn) {
        return;
    }

    const form = refInput.closest('form');
    if (!form) {
        return;
    }

    const sizesWrapper = document.querySelector('#add_stock_sizes_container');

    function clearSizes(message) {
        sizesContainer.innerHTML = '';
        sizeHiddenInput.value = '';
        submitBtn.disabled = true;

        if (message) {
            const p = document.createElement('p');
            p.className = 'text-muted small mb-0';
            p.textContent = message;
            sizesContainer.appendChild(p);
        }
    }

    function setActiveSizeButton(button) {
        const allButtons = sizesContainer.querySelectorAll('.btn-add-stock-size');
        allButtons.forEach(btn => btn.classList.remove('active'));

        if (button) {
            button.classList.add('active');
        }
    }

    /**
     * Charge les tailles pour la référence tapée / scannée.
     * Utilise le même endpoint que la vente : /user/sell/get-sizes
     * On réutilise donc le travail déjà fait côté back.
     *
     * autoSelectSizeName : si fourni, essaie de sélectionner automatiquement cette taille.
     */
    async function loadSizesForReference(autoSelectSizeName = null) {
        const reference = refInput.value.trim();

        clearSizes();

        if (reference.length === 0) {
            clearSizes('Saisissez une référence pour voir les tailles disponibles.');
            return;
        }

        // Message de chargement
        const loading = document.createElement('p');
        loading.className = 'text-muted small mb-0';
        loading.textContent = 'Recherche des tailles disponibles…';
        sizesContainer.appendChild(loading);

        const url = '/user/sell/get-sizes?reference=' + encodeURIComponent(reference);

        try {
            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            sizesContainer.innerHTML = '';

            if (!response.ok) {
                clearSizes("Impossible de récupérer les tailles pour cette référence.");
                return;
            }

            const data = await response.json();

            if (!data.success) {
                clearSizes(data.message || "Aucun stock disponible pour cette référence dans ce magasin.");
                return;
            }

            if (!data.sizes || data.sizes.length === 0) {
                clearSizes("Aucun stock disponible pour cette référence dans ce magasin.");
                return;
            }

            let targetSizeName = null;
            if (autoSelectSizeName) {
                targetSizeName = String(autoSelectSizeName).trim().toUpperCase();
            }

            data.sizes.forEach(size => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-outline-primary btn-lg btn-add-stock-size';
                // On affiche aussi le stock actuel pour info
                btn.textContent = `${size.name} (stock actuel : ${size.quantity})`;
                btn.dataset.sizeId = size.id;
                btn.dataset.sizeName = String(size.name).trim().toUpperCase();

                btn.addEventListener('click', function () {
                    sizeHiddenInput.value = this.dataset.sizeId;
                    setActiveSizeButton(this);
                    submitBtn.disabled = false;
                });

                sizesContainer.appendChild(btn);
            });

            // Auto-sélection de la taille (cas code-barres REF-Taille)
            if (targetSizeName) {
                const btnToClick = sizesContainer.querySelector(
                    `.btn-add-stock-size[data-size-name="${targetSizeName}"]`
                );
                if (btnToClick) {
                    btnToClick.click();
                }
            }

        } catch (e) {
            console.error(e);
            clearSizes("Erreur lors de la récupération des tailles.");
        }
    }

    /**
     * Gère un scan clavier (douchette).
     * Utilise UserScan.parseRefAndSize pour être cohérent avec les autres pages.
     */
    async function handleScan(rawValue) {
        const Scan = (window.UserScan && typeof window.UserScan.parseRefAndSize === 'function')
            ? window.UserScan.parseRefAndSize(rawValue)
            : { raw: String(rawValue || '').trim(), reference: String(rawValue || '').trim(), size: null };

        if (!Scan.reference) {
            return;
        }

        // Mise à jour du champ référence avec la partie référence
        refInput.value = Scan.reference;

        // On charge les tailles, et si on a une taille dans le scan on essaie de l’auto-sélectionner
        await loadSizesForReference(Scan.size);
    }

    /**
     * Tente un auto-submit après un scan complet REF+TAILLE.
     * - Ne fait rien si la taille n'est pas présente
     * - Laisse la vendeuse gérer manuellement dans ce cas
     */
    function tryAutoSubmitFromScan(rawValue) {
        if (!window.UserScan || typeof window.UserScan.parseRefAndSize !== 'function') {
            return;
        }

        const parsed = window.UserScan.parseRefAndSize(rawValue || '');
        // Si pas de taille dans le scan → pas d'auto-submit
        if (!parsed || !parsed.reference || !parsed.size) {
            return;
        }

        // On laisse un petit délai pour que loadSizesForReference() ait le temps de :
        // - récupérer les tailles
        // - cliquer sur le bon bouton si la taille existe
        setTimeout(function () {
            if (!sizeHiddenInput.value) {
                // aucune taille sélectionnée → on ne force pas l'envoi
                return;
            }

            // Optionnel : s'assurer qu'on a au moins quantité = 1
            const quantityInput = form.querySelector('input[name="quantity"]');
            if (quantityInput && (!quantityInput.value || parseInt(quantityInput.value, 10) <= 0)) {
                quantityInput.value = '1';
            }

            form.submit();
        }, 300);
    }

    // Cas classique : référence tapée au clavier → on charge les tailles à la sortie du champ
    refInput.addEventListener('change', () => loadSizesForReference());
    refInput.addEventListener('blur', () => loadSizesForReference());

    // Cas douchette : code + Entrée
    refInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault(); // évite de soumettre le formulaire entier "à l'ancienne"
            const raw = refInput.value;
            handleScan(raw);
            tryAutoSubmitFromScan(raw);
        }
    });

    // Si une référence est déjà présente au chargement (retour sur la page),
    // on recharge les tailles.
    if (refInput.value.trim().length > 0) {
        loadSizesForReference();
    }
});
