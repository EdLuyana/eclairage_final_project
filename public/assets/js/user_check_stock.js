// public/assets/js/user_check_stock.js

document.addEventListener('DOMContentLoaded', function () {
    const refInput = document.querySelector('#check_reference');
    if (!refInput) {
        return;
    }

    const form = refInput.closest('form');

    // Quand la douchette envoie "REF-TAILLE" + Enter,
    // on ne garde que la partie référence avant de laisser le formulaire se soumettre.
    refInput.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') {
            return;
        }

        const value = refInput.value.trim();
        if (!value.length) {
            return;
        }

        if (window.UserScan && typeof window.UserScan.parseRefAndSize === 'function') {
            const parsed = window.UserScan.parseRefAndSize(value);
            if (parsed && parsed.reference) {
                refInput.value = parsed.reference;
            }
        }
        // ⚠️ On NE fait PAS preventDefault :
        // le navigateur va soumettre le formulaire GET normalement.
    });
});
