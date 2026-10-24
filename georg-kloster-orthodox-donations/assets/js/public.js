document.addEventListener('DOMContentLoaded', function () {
  var modal = document.querySelector('[data-gkod-result-modal]');
  if (modal) {
    var card = modal.querySelector('.gkod-result-modal__card');
    var closeModal = function () {
      modal.classList.add('gkod-result-modal--closing');
      document.body.classList.remove('gkod-modal-open');
      window.setTimeout(function () { modal.remove(); }, 180);
    };
    document.body.classList.add('gkod-modal-open');
    modal.querySelectorAll('[data-gkod-result-close]').forEach(function (button) { button.addEventListener('click', closeModal); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && document.body.contains(modal)) closeModal(); });
    window.setTimeout(function () { card.focus(); }, 50);
    if (window.history && window.history.replaceState) {
      var cleanUrl = new URL(window.location.href);
      cleanUrl.searchParams.delete('gkod_payment');
      cleanUrl.searchParams.delete('gkod_order');
      window.history.replaceState({}, document.title, cleanUrl.toString());
    }
  }
  document.querySelectorAll('.gkod-donation-form').forEach(function (root) {
    var form = root.querySelector('form');
    var names = form.elements.names;
    var donation = root.dataset.requestType === 'donation';
    var amount = form.elements.donation_amount;
    var output = root.querySelector('output');
    var error = root.querySelector('.gkod-error');
    var submit = root.querySelector('.gkod-submit');
    var currency = root.dataset.currency || 'EUR';
    var lang = ({en: 'en-US', de: 'de-DE', ru: 'ru-RU', uk: 'uk-UA', pl: 'pl-PL'})[root.dataset.lang] || 'de-DE';
    var requiredMessage = root.dataset.required || window.georgKlosterOrthodoxDonate.required;
    var genericError = root.dataset.error || window.georgKlosterOrthodoxDonate.error;

    function normalizeNames(finalize) {
      if (donation) return;
      var start = names.selectionStart, end = names.selectionEnd;
      var convert = function (value) { return value.replace(/[ \t]*[,;，][ \t]*/g, '\n'); };
      var value = convert(names.value);
      if (finalize) value = value.split(/[\r\n\u2028\u2029]+/).map(function (v) { return v.trim(); }).filter(Boolean).join('\n');
      if (names.value !== value) {
        var nextStart = convert(names.value.slice(0, start)).length;
        var nextEnd = convert(names.value.slice(0, end)).length;
        names.value = value;
        if (!finalize) names.setSelectionRange(nextStart, nextEnd);
      }
    }
    function namesCount() {
      return names.value.split(/\r?\n/).map(function (v) { return v.trim(); }).filter(Boolean).length;
    }
    function refreshVisibility() {
      if (donation) { calculate(); return; }
      var selectedIntention = form.querySelector('[name="intention"]:checked');
      var intention = selectedIntention ? selectedIntention.value : '';
      root.querySelectorAll('.gkod-service').forEach(function (row) {
        var mode = row.dataset.intention;
        var visible = mode === 'both' || mode === 'none' || intention === '' || mode === intention;
        row.hidden = !visible;
        if (!visible) row.querySelector('input').checked = false;
      });
      calculate();
    }
    function calculate() {
      if (donation) {
        var value = amount.value.trim();
        var valid = /^[0-9]{1,6}([.,][0-9]{1,2})?$/.test(value);
        var parts = value.replace(',', '.').split('.');
        var donationCents = valid ? Number(parts[0]) * 100 + Number(((parts[1] || '') + '00').slice(0, 2)) : 0;
        var minimumCents = Math.round(Number(amount.min || 0.50) * 100);
        amount.setCustomValidity(valid && donationCents >= minimumCents && donationCents <= 99999999 ? '' : requiredMessage);
        output.textContent = new Intl.NumberFormat(lang, {style: 'currency', currency: currency}).format(donationCents / 100);
        return;
      }
      var count = namesCount();
      var cents = 0;
      root.querySelectorAll('.gkod-service input:checked').forEach(function (input) {
        var row = input.closest('.gkod-service');
        var price = Number(row.dataset.price);
        var limit = Math.max(1, Number(row.dataset.limit));
        if (row.dataset.mode === 'per_person') cents += price * count;
        else if (row.dataset.mode === 'per_sheet') cents += price * Math.max(1, Math.ceil(count / limit));
        else cents += price;
      });
      output.textContent = new Intl.NumberFormat(lang, {style: 'currency', currency: currency}).format(cents / 100);
    }
    form.addEventListener('change', function (event) {
      if (event.target.name === 'intention') refreshVisibility(); else calculate();
    });
    if (donation) amount.addEventListener('input', calculate);
    if (donation) {
      var amountChoices = form.querySelectorAll('input[name="donation_preset"]');
      amountChoices.forEach(function (choice) {
        choice.addEventListener('change', function () {
          if (choice.value !== 'custom') amount.value = choice.value;
          calculate();
        });
      });
      amount.addEventListener('focus', function () {
        var customChoice = form.querySelector('input[name="donation_preset"][value="custom"]');
        if (customChoice) customChoice.checked = true;
      });
    }
    else {
    names.addEventListener('input', function (event) { if (!event.isComposing) normalizeNames(false); calculate(); });
    names.addEventListener('compositionend', function () { normalizeNames(false); calculate(); });
    names.addEventListener('blur', function () { normalizeNames(true); calculate(); });
    }
    form.addEventListener('submit', function (event) {
      event.preventDefault(); error.hidden = true; normalizeNames(true); calculate();
      if (!form.checkValidity() || (!donation && !form.querySelector('.gkod-service input:checked'))) {
        error.textContent = requiredMessage; error.hidden = false; form.reportValidity(); return;
      }
      submit.disabled = true;
      fetch(window.georgKlosterOrthodoxDonate.ajaxUrl, {method: 'POST', body: new FormData(form), credentials: 'same-origin'})
        .then(function (response) { return response.json(); })
        .then(function (data) {
          if (!data.success || !data.data.redirect) throw new Error(data.data && data.data.message || genericError);
          window.location.assign(data.data.redirect);
        })
        .catch(function (reason) { error.textContent = reason.message || genericError; error.hidden = false; submit.disabled = false; });
    });
    normalizeNames(true);
    refreshVisibility();
  });
});
