jQuery(document).ready(function($) {
    // --- Robust Page Count Calculation ---

    // Define the reusable function to calculate and set the page count.
    function calculateAndSetPageCount(pdfUrl) {
        if (typeof pdfjsLib === 'undefined') {
            console.error('pdf.js library is not loaded.');
            $('#icp-page-count-display').html('<p style="color: red;">Error: PDF library not loaded.</p>');
            return;
        }

        // Disable the publish/update button and show a status message.
        var $publishButton = $('#publish');
        var originalButtonText = $publishButton.val();
        $publishButton.prop('disabled', true);
        $('#icp-page-count-display').html('<p><em>Calculating page count...</em></p>');

        // Set the workerSrc for pdf.js
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.11.338/pdf.worker.min.js';

        var loadingTask = pdfjsLib.getDocument(pdfUrl);
        loadingTask.promise.then(function(pdf) {
            var totalPages = pdf.numPages;
            $('#icp_total_pages').val(totalPages);
            $('#icp-page-count-display').html('<p><strong>Total Pages:</strong> ' + totalPages + '</p>');
            // Re-enable the button
            $publishButton.prop('disabled', false).val(originalButtonText);
        }).catch(function(reason) {
            console.error('Error getting PDF for page count: ' + reason);
            $('#icp-page-count-display').html('<p style="color: red;">Could not determine page count. Please check the PDF URL or try again.</p>');
            // Re-enable the button even if there's an error
            $publishButton.prop('disabled', false).val(originalButtonText);
        });
    }

    // --- Event Handlers ---

    // 1. PDF Uploader for Book CPT
    $('#icp_upload_pdf_button').click(function(e) {
        e.preventDefault();
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
            // Use the new robust function
            calculateAndSetPageCount(attachment.url);
        }).open();
    });

    // 2. On Page Load: Check if we need to calculate pages for an existing book.
    var existingPdfUrl = $('#icp_pdf_url').val();
    var existingTotalPages = $('#icp_total_pages').val();

    if (existingPdfUrl && (!existingTotalPages || existingTotalPages === '0')) {
        calculateAndSetPageCount(existingPdfUrl);
    }
});