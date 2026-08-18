(function () {
    'use strict';

    var SAVE_URL = window.ORDER_SAVE_URL;
    var CSRF_TOKEN = window.ORDER_CSRF_TOKEN;
    var ASSETS_URL = window.BO_ASSETS_URL;

    var wrapper = document.getElementById('needs-ordering-content');
    var tbody = document.getElementById('needs-ordering-body');
    var totalEl = document.getElementById('total-to-order');
    var countEl = document.getElementById('design-count');

    function recomputeTotals() {
        var rows = tbody ? Array.prototype.slice.call(tbody.querySelectorAll('tr[data-card-row]')) : [];
        var total = 0;

        rows.forEach(function (row) {
            var input = row.querySelector('.order-input');
            total += parseInt(input ? input.value : '0', 10) || 0;
        });

        if (totalEl) totalEl.textContent = String(total);
        if (countEl) countEl.textContent = String(rows.length);
        if (wrapper) wrapper.style.display = rows.length > 0 ? '' : 'none';
    }

    function buildThumbCell(imagePath) {
        var cell = document.createElement('td');

        if (imagePath) {
            var img = document.createElement('img');
            img.className = 'table-thumb table-thumb-card';
            img.src = ASSETS_URL + '/' + imagePath;
            img.alt = '';
            cell.appendChild(img);
        } else {
            var placeholder = document.createElement('div');
            placeholder.className = 'table-thumb table-thumb-card';
            cell.appendChild(placeholder);
        }

        return cell;
    }

    function buildNeedsOrderingRow(data) {
        var row = document.createElement('tr');
        row.setAttribute('data-card-row', String(data.card_id));

        var skuCell = document.createElement('td');
        skuCell.className = 'reference';
        skuCell.textContent = data.sku;

        var titleCell = document.createElement('td');
        titleCell.textContent = data.title;

        var minStockCell = document.createElement('td');
        minStockCell.setAttribute('data-min-stock-display', '');
        minStockCell.textContent = String(data.min_stock);

        var toOrderCell = document.createElement('td');
        var input = document.createElement('input');
        input.type = 'number';
        input.min = '0';
        input.className = 'order-input';
        input.setAttribute('data-card-id', String(data.card_id));
        input.value = String(data.to_order);
        var indicator = document.createElement('span');
        indicator.className = 'save-indicator';
        indicator.setAttribute('data-indicator-for', String(data.card_id));
        toOrderCell.appendChild(input);
        toOrderCell.appendChild(indicator);

        row.appendChild(buildThumbCell(data.image_path));
        row.appendChild(skuCell);
        row.appendChild(titleCell);
        row.appendChild(minStockCell);
        row.appendChild(toOrderCell);

        return row;
    }

    function syncNeedsOrderingRow(data) {
        if (!tbody) return;

        var row = tbody.querySelector('tr[data-card-row="' + data.card_id + '"]');

        if (!data.needs_ordering) {
            if (row) row.parentNode.removeChild(row);
            recomputeTotals();
            return;
        }

        if (!row) {
            tbody.appendChild(buildNeedsOrderingRow(data));
        } else {
            var input = row.querySelector('.order-input');
            if (input && document.activeElement !== input) {
                input.value = String(data.to_order);
            }

            var minStockDisplay = row.querySelector('[data-min-stock-display]');
            if (minStockDisplay) minStockDisplay.textContent = String(data.min_stock);
        }

        recomputeTotals();
    }

    function syncAllInputsForCard(cardId, value) {
        // Dezelfde kaart/product kan zowel in de "moet besteld worden"-tabel als in de
        // volledige lijst staan - hou beide in sync, zonder het veld te overschrijven
        // waar de gebruiker op dit moment in aan het typen is.
        document.querySelectorAll('.order-input[data-card-id="' + cardId + '"]').forEach(function (input) {
            if (document.activeElement !== input) {
                input.value = String(value);
            }
        });
    }

    function showIndicator(cardId, isError) {
        document.querySelectorAll('[data-indicator-for="' + cardId + '"]').forEach(function (indicator) {
            indicator.textContent = isError ? '⚠ opslaan mislukt' : '✓ opgeslagen';
            indicator.classList.toggle('error', isError);
            indicator.classList.add('visible');

            window.clearTimeout(indicator._hideTimeout);
            indicator._hideTimeout = window.setTimeout(function () {
                indicator.classList.remove('visible');
            }, 2000);
        });
    }

    // Event delegation (i.p.v. per-input listeners) zodat dynamisch toegevoegde rijen
    // (zie buildNeedsOrderingRow) meteen ook autosaven, zonder opnieuw te hoeven binden.
    document.addEventListener('change', function (e) {
        var input = e.target.closest('.order-input');
        if (!input) return;

        var cardId = input.getAttribute('data-card-id');
        var value = Math.max(0, parseInt(input.value, 10) || 0);
        input.value = String(value);
        syncAllInputsForCard(cardId, value);

        fetch(SAVE_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ card_id: Number(cardId), to_order: value, csrf_token: CSRF_TOKEN })
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { ok: response.ok, data: data };
                });
            })
            .then(function (result) {
                if (!result.ok || !result.data.ok) {
                    showIndicator(cardId, true);
                    return;
                }

                showIndicator(cardId, false);
                syncAllInputsForCard(cardId, result.data.to_order);
                syncNeedsOrderingRow(result.data);
            })
            .catch(function () {
                showIndicator(cardId, true);
            });
    });
})();
