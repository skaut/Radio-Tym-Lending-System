(function () {
    function focusFilterInput() {
        const filterInput = document.getElementById('inputFilter');
        if (filterInput instanceof HTMLInputElement) {
            filterInput.focus();
            filterInput.select();
        }
    }

    async function submitAsyncForm(form) {
        if (!(form instanceof HTMLFormElement)) {
            return false;
        }

        const table = document.getElementById('mainTable');
        const notice = document.getElementById('asyncNotice');

        if (!(table instanceof HTMLElement) || !table.contains(form)) {
            return false;
        }

        function showNotice(message, isError) {
            if (!notice) {
                if (isError) {
                    window.alert(message);
                }
                return;
            }

            notice.textContent = message;
            notice.className = isError ? 'async-notice is-visible is-error' : 'async-notice is-visible';

            window.clearTimeout(showNotice.timeoutId);
            showNotice.timeoutId = window.setTimeout(function () {
                notice.className = 'async-notice';
            }, isError ? 5000 : 2200);
        }

        function setBusy(isBusy) {
            form.classList.toggle('is-busy', isBusy);
            Array.from(form.elements).forEach(function (element) {
                element.disabled = isBusy;
            });
        }

        function escapeHtml(value) {
            return value
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#39;');
        }

        function renderActionForm(radio) {
            if (radio.nextAction === 'return') {
                return '' +
                    '<form class="action-form" action="/radio-action/return" method="POST">' +
                    '<input type="hidden" name="id" value="' + escapeHtml(String(radio.id)) + '">' +
                    '<input type="hidden" name="radioId" value="' + escapeHtml(radio.radioId) + '">' +
                    '<button type="button" class="pure-button js-async-submit" onclick="return window.rtlsAsyncSubmit(this.form)">Vrátit</button>' +
                    '</form>';
            }

            const placeholder = radio.lastBorrower || 'zadej vypůjčitele';
            const required = radio.lastBorrower ? '' : ' required';

            return '' +
                '<form class="action-form action-form-lend" action="/radio-action/lend" method="POST">' +
                '<input type="text" name="borrower" placeholder="' + escapeHtml(placeholder) + '"' + required + '>' +
                '<input type="hidden" name="id" value="' + escapeHtml(String(radio.id)) + '">' +
                '<input type="hidden" name="radioId" value="' + escapeHtml(radio.radioId) + '">' +
                '<input type="hidden" name="last-borrower" value="' + escapeHtml(radio.lastBorrower) + '">' +
                '<button type="button" class="pure-button js-async-submit" onclick="return window.rtlsAsyncSubmit(this.form)">Vypůjčit</button>' +
                '</form>';
        }

        function updateCounts(counts) {
            const lent = document.getElementById('count-lent');
            const notLent = document.getElementById('count-not-lent');

            if (lent) {
                lent.textContent = String(counts.lent);
            }
            if (notLent) {
                notLent.textContent = String(counts.notLent);
            }
        }

        function applyRadioUpdate(radio) {
            const row = document.getElementById('radio-row_' + radio.id);
            if (!row) {
                return;
            }

            row.dataset.radioCode = radio.radioId;
            row.classList.toggle('highlight', radio.status === 'lent');
            row.classList.toggle('dimnish', radio.status !== 'lent');

            const statusDisplay = document.getElementById('status_' + radio.id);
            const lastActionCell = row.querySelector('.cell-last-action');
            const borrowerCell = row.querySelector('.cell-borrower');
            const channelSelect = row.querySelector('select[name="channel"]');
            const actionsCell = document.getElementById('actions_' + radio.id);

            if (statusDisplay) {
                statusDisplay.textContent = radio.statusLabel;
            }

            if (lastActionCell) {
                lastActionCell.textContent = radio.lastActionTimeDisplay;
            }

            if (borrowerCell) {
                borrowerCell.textContent = radio.lastBorrower;
                borrowerCell.classList.toggle('highlight', radio.status === 'lent');
                borrowerCell.classList.toggle('dimnish', radio.status !== 'lent');
            }

            if (channelSelect) {
                channelSelect.value = radio.channel;
            }

            if (actionsCell) {
                actionsCell.innerHTML = renderActionForm(radio);
            }

            if (typeof window.filterInput === 'function') {
                window.filterInput();
            }
        }

        const requestBody = new URLSearchParams(new FormData(form)).toString();
        setBusy(true);

        try {
            const response = await fetch(form.action, {
                method: form.method || 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: requestBody
            });

            if (!response.ok) {
                throw new Error('Server returned ' + response.status);
            }

            const payload = await response.json();

            if (!payload.success || !payload.radio || !payload.counts) {
                throw new Error('Unexpected response payload.');
            }

            applyRadioUpdate(payload.radio);
            updateCounts(payload.counts);
            showNotice(payload.message || 'Uloženo.', false);

            if (typeof window.rtlsAfterAsyncRadioUpdate === 'function') {
                window.rtlsAfterAsyncRadioUpdate(payload.radio, form);
            }
        } catch (error) {
            showNotice('Změnu se nepodařilo uložit. Zkus to znovu.', true);
        } finally {
            setBusy(false);
            focusFilterInput();
        }

        return false;
    }

    window.rtlsAsyncSubmit = function (form) {
        void submitAsyncForm(form);
        return false;
    };
    window.rtlsFocusFilterInput = focusFilterInput;

    document.addEventListener('change', function (event) {
        const target = event.target;

        if (
            !(target instanceof HTMLSelectElement)
            || target.name !== 'channel'
            || !target.form
            || !target.form.classList.contains('action-form-channel')
        ) {
            return;
        }

        event.preventDefault();
        window.rtlsAsyncSubmit(target.form);
    }, true);
})();
