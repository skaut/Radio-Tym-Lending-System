(function () {
    var STORAGE_KEY = 'rtlsSignature';

    function getSignature() {
        try {
            return (window.localStorage.getItem(STORAGE_KEY) || '').trim();
        } catch (e) {
            // localStorage can throw in private-browsing/disabled-storage modes.
            return '';
        }
    }

    function setSignature(value) {
        try {
            window.localStorage.setItem(STORAGE_KEY, value.trim());
        } catch (e) {
            // Signature just won't persist across page loads in this case.
        }
    }

    window.rtlsGetSignature = getSignature;

    function getSignatureInput() {
        var input = document.getElementById('inputSignature');
        return input instanceof HTMLInputElement ? input : null;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var input = getSignatureInput();
        if (!input) {
            return;
        }

        input.value = getSignature();
        input.addEventListener('change', function () {
            setSignature(input.value);
        });
    });

    // Regular (non-AJAX) POST forms don't go through async-actions.js, so attach
    // the signature here too, right before the browser submits the form.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || form.method.toUpperCase() !== 'POST') {
            return;
        }

        var signatureField = form.querySelector('input[name="signature"]');
        if (!(signatureField instanceof HTMLInputElement)) {
            signatureField = document.createElement('input');
            signatureField.type = 'hidden';
            signatureField.name = 'signature';
            form.appendChild(signatureField);
        }
        signatureField.value = getSignature();
    }, true);
})();
