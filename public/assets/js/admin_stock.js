// public/assets/js/admin_stock.js

document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('stockAdjustModal');
    if (!modalEl) {
        return; // On n'est pas sur la page stock admin
    }

    const productLabelEl = modalEl.querySelector('.js-adjust-product-label');
    const selectEl       = modalEl.querySelector('select[name="stock_id"]');
    const qtyInputEl     = modalEl.querySelector('input[name="quantity"]');
    const maxInfoEl      = modalEl.querySelector('.js-adjust-max-info');

    modalEl.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        if (!button) {
            return;
        }

        const productRef   = button.getAttribute('data-product-ref') || '';
        const productName  = button.getAttribute('data-product-name') || '';
        const size         = button.getAttribute('data-size') || '';
        const locationsRaw = button.getAttribute('data-locations') || '[]';

        // Libellé produit
        let label = productRef;
        if (productName) {
            label += ' - ' + productName;
        }
        if (size) {
            label += ' (' + size + ')';
        }
        if (productLabelEl) {
            productLabelEl.textContent = label;
        }

        // Réinitialiser le contenu du select et du champ quantité
        if (selectEl) {
            selectEl.innerHTML = '';
        }
        if (qtyInputEl) {
            qtyInputEl.value = 1;
            qtyInputEl.removeAttribute('max');
            qtyInputEl.disabled = false;
        }
        if (maxInfoEl) {
            maxInfoEl.textContent = '';
        }

        let locations;
        try {
            locations = JSON.parse(locationsRaw);
        } catch (e) {
            locations = [];
        }

        if (!Array.isArray(locations) || locations.length === 0) {
            // Pas de stock disponible pour ce produit
            if (selectEl) {
                const opt = document.createElement('option');
                opt.textContent = 'Aucun stock disponible pour ce produit';
                opt.disabled = true;
                opt.selected = true;
                selectEl.appendChild(opt);
            }
            if (qtyInputEl) {
                qtyInputEl.disabled = true;
            }
            return;
        }

        // Remplir le select avec les magasins disponibles
        locations.forEach(function (loc, index) {
            const opt = document.createElement('option');
            opt.value = String(loc.stockId);
            opt.textContent = loc.code + ' - ' + loc.name + ' (' + loc.qty + ' en stock)';
            opt.dataset.maxQty = String(loc.qty);

            if (index === 0) {
                opt.selected = true;
                if (qtyInputEl) {
                    qtyInputEl.setAttribute('max', String(loc.qty));
                }
                if (maxInfoEl) {
                    maxInfoEl.textContent = 'Stock disponible : ' + loc.qty;
                }
            }

            if (selectEl) {
                selectEl.appendChild(opt);
            }
        });

        // Quand on change de magasin dans le select, adapter la quantité max
        if (selectEl) {
            selectEl.onchange = function () {
                const selectedOption = selectEl.options[selectEl.selectedIndex];
                const maxQty = selectedOption ? selectedOption.dataset.maxQty : null;

                if (maxQty && qtyInputEl) {
                    qtyInputEl.setAttribute('max', maxQty);

                    const currentVal = parseInt(qtyInputEl.value, 10);
                    const maxVal = parseInt(maxQty, 10);
                    if (!isNaN(currentVal) && !isNaN(maxVal) && currentVal > maxVal) {
                        qtyInputEl.value = String(maxVal);
                    }

                    if (maxInfoEl) {
                        maxInfoEl.textContent = 'Stock disponible : ' + maxQty;
                    }
                }
            };
        }
    });
});
