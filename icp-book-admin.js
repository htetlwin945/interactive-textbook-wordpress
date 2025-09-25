jQuery(document).ready(function($) {
    // PDF Uploader for Book CPT
    if ($('#icp_upload_pdf_button').length) {
        pdfjsLib.GlobalWorkerOptions.workerSrc = `https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.11.338/pdf.worker.min.js`;

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

                // Automatically get page count
                var loadingTask = pdfjsLib.getDocument(attachment.url);
                loadingTask.promise.then(function(pdf) {
                    $('#icp_total_pages').val(pdf.numPages);
                }, function (reason) {
                    console.error('Error getting PDF page count:', reason);
                    alert('Could not get page count from PDF. Please enter it manually.');
                });

            }).open();
        });
    }
});