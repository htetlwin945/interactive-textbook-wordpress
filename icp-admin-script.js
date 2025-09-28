jQuery(document).ready(function($) {
    // PDF Uploader for Book CPT
    $('#icp_upload_pdf_button').click(function(e) {
        e.preventDefault();
        var button = $(this);
        var frame = wp.media({
            title: 'Select or Upload PDF',
            button: {
                text: 'Use this PDF'
            },
            library: {
                type: 'application/pdf'
            },
            multiple: false
        }).on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            $('#icp_pdf_url').val(attachment.url);
        }).open();
    });
});