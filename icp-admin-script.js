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

            // New: Calculate PDF page count
            if (typeof pdfjsLib === 'undefined') {
                console.error('pdf.js library is not loaded.');
                return;
            }

            // Set the workerSrc for pdf.js
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.11.338/pdf.worker.min.js';

            var loadingTask = pdfjsLib.getDocument(attachment.url);
            loadingTask.promise.then(function(pdf) {
                var totalPages = pdf.numPages;
                $('#icp_total_pages').val(totalPages);
                $('#icp-page-count-display').html('<p><strong>Total Pages:</strong> ' + totalPages + '</p>');
            }).catch(function(reason) {
                console.error('Error getting PDF for page count: ' + reason);
                 $('#icp-page-count-display').html('<p style="color: red;">Could not determine page count.</p>');
            });

        }).open();
    });
});