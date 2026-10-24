(function ($, media) {
    'use strict';
    $(function () {
        var canvas = document.getElementById('gkod-thank-you-canvas');
        if (!canvas) return;
        var fields = Array.prototype.slice.call(canvas.querySelectorAll('.gkod-thank-you-field'));
        var selected = document.getElementById('gkod-thank-you-field-select');
        var inputs = {
            width: document.getElementById('gkod-thank-you-width'),
            size: document.getElementById('gkod-thank-you-size'),
            color: document.getElementById('gkod-thank-you-color'),
            align: document.getElementById('gkod-thank-you-align'),
            enabled: document.getElementById('gkod-thank-you-enabled')
        };

        function fieldElement(key) { return canvas.querySelector('[data-field="' + key + '"]'); }
        function hidden(key, property) { return document.querySelector('[data-field-value="' + key + '"][data-property="' + property + '"]'); }
        function val(key, property) { var el = hidden(key, property); return el ? el.value : ''; }
        function set(key, property, value) {
            var el = hidden(key, property), box = fieldElement(key);
            if (el) el.value = value;
            if (!box) return;
            if (property === 'x') box.style.left = value + '%';
            if (property === 'y') box.style.top = value + '%';
            if (property === 'width') box.style.width = value + '%';
            if (property === 'size') box.style.fontSize = value + 'pt';
            if (property === 'color') box.style.color = value;
            if (property === 'align') box.style.textAlign = value;
            if (property === 'enabled') box.classList.toggle('is-disabled', value !== '1');
        }
        function refreshControls() {
            var key = selected.value;
            fields.forEach(function (field) { field.classList.toggle('is-selected', field.dataset.field === key); });
            inputs.width.value = val(key, 'width'); inputs.size.value = val(key, 'size');
            inputs.color.value = val(key, 'color'); inputs.align.value = val(key, 'align');
            inputs.enabled.checked = val(key, 'enabled') === '1';
        }
        selected.addEventListener('change', refreshControls);
        var orientation = document.getElementById('gkod-thank-you-orientation');
        if (orientation) {
            orientation.addEventListener('change', function () { canvas.dataset.orientation = orientation.value; });
            canvas.dataset.orientation = orientation.value;
        }
        Object.keys(inputs).forEach(function (property) {
            var update = function () { set(selected.value, property, property === 'enabled' ? (inputs[property].checked ? '1' : '0') : inputs[property].value); };
            inputs[property].addEventListener('input', update);
            inputs[property].addEventListener('change', update);
        });
        fields.forEach(function (field) {
            field.addEventListener('pointerdown', function (event) {
                event.preventDefault(); selected.value = field.dataset.field; refreshControls();
                var rect = canvas.getBoundingClientRect(), key = field.dataset.field;
                field.setPointerCapture(event.pointerId);
                function move(e) {
                    set(key, 'x', Math.max(0, Math.min(100, ((e.clientX - rect.left) / rect.width) * 100)).toFixed(2));
                    set(key, 'y', Math.max(0, Math.min(100, ((e.clientY - rect.top) / rect.height) * 100)).toFixed(2));
                }
                function end() { field.removeEventListener('pointermove', move); field.removeEventListener('pointerup', end); field.removeEventListener('pointercancel', end); }
                field.addEventListener('pointermove', move); field.addEventListener('pointerup', end); field.addEventListener('pointercancel', end);
            });
        });
        refreshControls();
        [
            ['gkod-thank-you-body', 'body', gkodThankYouTemplate.bodySample],
            ['gkod-thank-you-quote', 'quote', ''],
            ['gkod-thank-you-extra', 'extra', ''],
            ['gkod-thank-you-signer', 'signer', gkodThankYouTemplate.signerSample],
            ['gkod-thank-you-address', 'address', '']
        ].forEach(function (entry) {
            var input = document.getElementById(entry[0]), box = fieldElement(entry[1]);
            if (!input || !box) return;
            input.addEventListener('input', function () {
                box.replaceChildren();
                (input.value || entry[2]).split(/\r\n|\r|\n/).forEach(function (line, index) {
                    if (index) box.appendChild(document.createElement('br'));
                    box.appendChild(document.createTextNode(line));
                });
            });
        });

        var frame;
        $('#gkod-thank-you-choose').on('click', function (event) {
            event.preventDefault();
            if (frame) { frame.open(); return; }
            frame = media({ title: gkodThankYouTemplate.title, button: { text: gkodThankYouTemplate.button }, multiple: false, library: { type: ['image/jpeg', 'image/png', 'application/pdf'] } });
            frame.on('select', function () {
                var item = frame.state().get('selection').first().toJSON();
                $('#gkod-thank-you-attachment').val(item.id);
                $('#gkod-thank-you-file').attr('href', item.url).text(item.filename || item.url);
                var canvas = document.getElementById('gkod-thank-you-canvas'), img = canvas.querySelector('#gkod-thank-you-preview-bg');
                if (item.mime === 'application/pdf') {
                    canvas.dataset.isPdf = '1';
                    var notice = canvas.querySelector('.gkod-thank-you-placeholder');
                    if (!notice) { notice = document.createElement('div'); notice.className = 'gkod-thank-you-placeholder'; canvas.prepend(notice); }
                    notice.textContent = gkodThankYouTemplate.pdfSelected;
                } else {
                    canvas.dataset.isPdf = '0';
                    var source = (item.sizes && item.sizes.full ? item.sizes.full.url : item.url);
                    if (img) img.src = source;
                    else { var image = document.createElement('img'); image.id = 'gkod-thank-you-preview-bg'; image.alt = ''; image.src = source; canvas.prepend(image); canvas.querySelector('.gkod-thank-you-placeholder')?.remove(); }
                }
            });
            frame.open();
        });
        $('#gkod-thank-you-clear').on('click', function () {
            $('#gkod-thank-you-attachment').val(''); $('#gkod-thank-you-file').attr('href', '#').text('');
            canvas.querySelector('#gkod-thank-you-preview-bg')?.remove();
            canvas.dataset.isPdf = '0';
            if (!canvas.querySelector('.gkod-thank-you-placeholder')) { var note = document.createElement('div'); note.className = 'gkod-thank-you-placeholder'; note.textContent = gkodThankYouTemplate.chooseBackground; canvas.prepend(note); }
        });
    });
}(jQuery, wp.media));
