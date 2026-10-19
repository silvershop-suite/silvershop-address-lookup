<style>
.silvershop-address-fields .address-lookup-status {
    display: none;
    margin: 0.3em 0 0.6em;
    font-size: 0.85em;
    line-height: 1.3;
}
.silvershop-address-fields .address-lookup-status.is-visible { display: block; }
.silvershop-address-fields .address-lookup-status.is-error { color: #b00020; }
</style>
<script type="application/json" class="address-lookup-config">$ConfigJson.RAW</script>
<script>
(function () {
    if (window.__addressLookupInit) { return; }
    window.__addressLookupInit = true;

    var DEBOUNCE_MS = 500;

    function fieldIn(scope, base) {
        return scope.querySelector('[name="' + base + '"], [name$="_' + base + '"]');
    }

    function currentCountry(scope, config) {
        var el = scope.querySelector('[name="Country"], [name$="_Country"]');
        var code = el ? String(el.value || '').toUpperCase() : '';
        return code || (config.defaultCountry || '').toUpperCase();
    }

    // Set a field's value, leaving it editable, and clear the "manually touched" flag so a later lookup for a
    // changed postcode can refresh it — but never clobber a value the customer has since edited by hand.
    function fill(input, value, filledFlag) {
        if (!input || !value) { return; }
        if (input.getAttribute('data-al-touched') === 'true' && input.value !== '') { return; }
        filledFlag.on = true;
        input.value = value;
        input.setAttribute('data-al-touched', 'false');
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        filledFlag.on = false;
        input.classList.add('address-lookup-filled');
    }

    function markTouchable(input, filledFlag) {
        if (!input || input.__alTouchWired) { return; }
        input.__alTouchWired = true;
        input.addEventListener('input', function () {
            if (!filledFlag.on) { input.setAttribute('data-al-touched', 'true'); }
        });
    }

    function status(scope) {
        var el = scope.querySelector('.address-lookup-status');
        if (!el) {
            el = document.createElement('div');
            el.className = 'address-lookup-status';
            el.setAttribute('aria-live', 'polite');
            scope.appendChild(el);
        }
        return el;
    }

    function showStatus(scope, message, isError) {
        var el = status(scope);
        el.textContent = message || '';
        el.classList.toggle('is-visible', !!message);
        el.classList.toggle('is-error', !!isError);
    }

    function wire(scope, config) {
        if (scope.__alWired) { return; }
        scope.__alWired = true;

        var map = config.fields || {};
        var postcode = fieldIn(scope, map.postalCode || 'PostalCode');
        var houseNumber = fieldIn(scope, map.houseNumber || 'HouseNumber');
        var streetInput = fieldIn(scope, map.street || 'Address');
        var cityInput = fieldIn(scope, map.city || 'City');
        var stateInput = fieldIn(scope, map.state || 'State');
        if (!postcode) { return; }

        var filledFlag = { on: false };
        [streetInput, cityInput, stateInput].forEach(function (i) { markTouchable(i, filledFlag); });

        var timer = null;
        function schedule() {
            if (timer) { clearTimeout(timer); }
            timer = setTimeout(run, DEBOUNCE_MS);
        }

        function run() {
            var country = currentCountry(scope, config);
            var rule = (config.countries || {})[country];
            if (!rule || rule.mode === 'none') { return; }

            var pc = String(postcode.value || '').trim();
            if (pc === '') { return; }
            // postcode_number: wait for the house number too, but only when the country actually has that field.
            var hn = houseNumber ? String(houseNumber.value || '').trim() : '';
            if (rule.mode === 'postcode_number' && houseNumber && hn === '') { return; }

            showStatus(scope, config.i18nLooking || 'Looking up address…', false);

            var headers = { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
            if (config.securityId) { headers['X-SecurityID'] = config.securityId; }

            var payload = { country: country, postalCode: pc, houseNumber: hn, suffix: '' };
            if (config.securityId && config.securityName) { payload[config.securityName] = config.securityId; }

            fetch(config.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: headers,
                body: JSON.stringify(payload)
            }).then(function (r) {
                return r.ok ? r.json() : null;
            }).then(function (data) {
                if (!data || !data.ok || !data.address) { showStatus(scope, '', false); return; }
                var a = data.address;
                fill(streetInput, a.street, filledFlag);
                fill(cityInput, a.city, filledFlag);
                if (stateInput && stateInput.offsetParent !== null) { fill(stateInput, a.state, filledFlag); }
                showStatus(scope, '', false);
                scope.dispatchEvent(new CustomEvent('addresslookup:resolved', { detail: a, bubbles: true }));
            }).catch(function () { showStatus(scope, '', false); });
        }

        postcode.addEventListener('input', schedule);
        postcode.addEventListener('change', schedule);
        if (houseNumber) {
            houseNumber.addEventListener('input', schedule);
            houseNumber.addEventListener('change', schedule);
        }
        // Re-evaluate when address-formats switches country (fields may have shown/hidden).
        scope.addEventListener('addressformat:changed', schedule);
    }

    function init() {
        document.querySelectorAll('script.address-lookup-config').forEach(function (s) {
            var config;
            try { config = JSON.parse(s.textContent); } catch (e) { return; }
            var scope = s.closest('.silvershop-address-fields') || s.closest('form');
            if (scope) { wire(scope, config); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
