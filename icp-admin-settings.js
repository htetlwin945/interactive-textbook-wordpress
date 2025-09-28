jQuery(document).ready(function($) {
    // Initialize the WordPress color picker
    $('.icp-color-picker').wpColorPicker();

    // Handle the logo uploader
    $('#icp_upload_logo_button').click(function(e) {
        e.preventDefault();

        var frame = wp.media({
            title: 'Select or Upload Logo',
            button: {
                text: 'Use this logo'
            },
            multiple: false
        });

        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            $('#icp_logo_url').val(attachment.url);
            $('#icp-logo-preview').html('<img src="' + attachment.url + '" style="max-width: 200px; height: auto;" />');
        });

        frame.open();
    });
});