document.addEventListener('DOMContentLoaded', function () {
  var modal = document.querySelector('[data-klp-result-modal]');
  if (modal) {
    var card = modal.querySelector('.klp-result-modal__card');
    var closeModal = function () {
      modal.classList.add('klp-result-modal--closing');
      document.body.classList.remove('klp-modal-open');
      window.setTimeout(function () { modal.remove(); }, 180);
    };
    document.body.classList.add('klp-modal-open');
    modal.querySelectorAll('[data-klp-result-close]').forEach(function (button) { button.addEventListener('click', closeModal); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && document.body.contains(modal)) closeModal(); });
    window.setTimeout(function () { card.focus(); }, 50);
    if (window.history && window.history.replaceState) {
      var cleanUrl = new URL(window.location.href);
      cleanUrl.searchParams.delete('kloster_payment');
      cleanUrl.searchParams.delete('kloster_order');
      window.history.replaceState({}, document.title, cleanUrl.toString());
    }
  }
  document.querySelectorAll('.klp-form').forEach(function (root) {
    var form = root.querySelector('form');
    var names = form.elements.names;
    var output = root.querySelector('output');
    var error = root.querySelector('.klp-error');
    var submit = root.querySelector('.klp-submit');
    var currency = root.dataset.currency || 'EUR';
    var lang = root.dataset.lang === 'ru' ? 'ru-RU' : 'de-DE';

    function normalizeNames(finalize) {
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
      var intention = form.querySelector('[name="intention"]:checked').value;
      root.querySelectorAll('.klp-service').forEach(function (row) {
        var mode = row.dataset.intention;
        var visible = mode === 'both' || mode === 'none' || mode === intention;
        row.hidden = !visible;
        if (!visible) row.querySelector('input').checked = false;
      });
      calculate();
    }
    function calculate() {
      var count = namesCount();
      var cents = 0;
      root.querySelectorAll('.klp-service input:checked').forEach(function (input) {
        var row = input.closest('.klp-service');
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
    names.addEventListener('input', function (event) { if (!event.isComposing) normalizeNames(false); calculate(); });
    names.addEventListener('compositionend', function () { normalizeNames(false); calculate(); });
    names.addEventListener('blur', function () { normalizeNames(true); calculate(); });
    form.addEventListener('submit', function (event) {
      event.preventDefault(); error.hidden = true; normalizeNames(true); calculate();
      if (!form.checkValidity() || !form.querySelector('.klp-service input:checked')) {
        error.textContent = window.klosterPrayer.required; error.hidden = false; form.reportValidity(); return;
      }
      submit.disabled = true;
      fetch(window.klosterPrayer.ajaxUrl, {method: 'POST', body: new FormData(form), credentials: 'same-origin'})
        .then(function (response) { return response.json(); })
        .then(function (data) {
          if (!data.success || !data.data.redirect) throw new Error(data.data && data.data.message || window.klosterPrayer.error);
          window.location.assign(data.data.redirect);
        })
        .catch(function (reason) { error.textContent = reason.message || window.klosterPrayer.error; error.hidden = false; submit.disabled = false; });
    });
    normalizeNames(true);
    refreshVisibility();
  });
});
