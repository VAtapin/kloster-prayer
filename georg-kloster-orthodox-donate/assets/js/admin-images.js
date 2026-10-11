jQuery(function ($) {
    const preview = $('#klp-image-preview');
    if (!preview.length) return;
    let frame;
    const labels = window.klosterPrayerAdmin || {
        empty: 'No image selected.', open: 'Open background image',
        title: 'Service background image', view: 'View image ↗', use: 'Use image'
    };
    function show(thumbnail, url) {
        preview.empty();
        if (!thumbnail) {
            $('<span>', {class: 'description', text: labels.empty}).appendTo(preview);
            return;
        }
        const link = $('<a>', {href: url || thumbnail, target: '_blank', rel: 'noopener noreferrer', 'aria-label': labels.open}).appendTo(preview);
        $('<img>', {src: thumbnail, alt: labels.title, width: 240}).appendTo(link);
        $('<span>', {text: labels.view}).appendTo(link);
    }
    $('#klp-image-select').on('click', function () {
        if (!frame) {
            frame = wp.media({title: labels.title, button: {text: labels.use}, library: {type: 'image'}, multiple: false});
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
