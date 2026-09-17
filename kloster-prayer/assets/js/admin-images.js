jQuery(function ($) {
    const preview = $('#klp-image-preview');
    if (!preview.length) return;
    let frame;
    function show(thumbnail, url) {
        preview.empty();
        if (!thumbnail) {
            $('<span>', {class: 'description', text: 'Изображение не выбрано.'}).appendTo(preview);
            return;
        }
        const link = $('<a>', {href: url || thumbnail, target: '_blank', rel: 'noopener noreferrer', 'aria-label': 'Открыть фоновое изображение'}).appendTo(preview);
        $('<img>', {src: thumbnail, alt: 'Фоновое изображение требы', width: 240}).appendTo(link);
        $('<span>', {text: 'Просмотреть изображение ↗'}).appendTo(link);
    }
    $('#klp-image-select').on('click', function () {
        if (!frame) {
            frame = wp.media({title: 'Фоновое изображение требы', button: {text: 'Использовать'}, library: {type: 'image'}, multiple: false});
            frame.on('select', function () {
                const attachment = frame.state().get('selection').first().toJSON();
                const sizes = attachment.sizes || {};
                $('#klp-image-id').val(attachment.id);
                show((sizes.medium || sizes.thumbnail || {}).url || attachment.url,
                    (sizes.medium_large || sizes.medium || {}).url || attachment.url);
            });
        }
        frame.open();
    });
    $('#klp-image-clear').on('click', function () {
        $('#klp-image-id').val(0);
        show(preview.attr('data-default'));
    });
});
