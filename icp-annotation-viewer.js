jQuery(document).ready(function($) {
    const classSelect = $('#icp_class_id');
    const studentSelect = $('#icp_student_id');
    const bookSelect = $('#icp_book_id');

    classSelect.on('change', function() {
        const classId = $(this).val();

        // Reset and disable student and book dropdowns
        studentSelect.html('<option value="">— Loading Students... —</option>').prop('disabled', true);
        bookSelect.html('<option value="">— Loading Books... —</option>').prop('disabled', true);

        if (!classId) {
            studentSelect.html('<option value="">— Select a Class First —</option>');
            bookSelect.html('<option value="">— Select a Class First —</option>');
            return;
        }

        // AJAX request for students
        $.post(ajaxurl, {
            action: 'icp_get_students_for_class',
            class_id: classId,
            nonce: icp_admin_data.nonce
        }, function(response) {
            if (response.success) {
                studentSelect.html('<option value="">— Select a Student —</option>');
                response.data.forEach(function(student) {
                    studentSelect.append(new Option(student.name, student.id));
                });
                studentSelect.prop('disabled', false);
            } else {
                studentSelect.html('<option value="">— No Students Found —</option>');
            }
        });

        // AJAX request for books
        $.post(ajaxurl, {
            action: 'icp_get_books_for_class',
            class_id: classId,
            nonce: icp_admin_data.nonce
        }, function(response) {
            if (response.success) {
                bookSelect.html('<option value="">— Select a Book —</option>');
                response.data.forEach(function(book) {
                    bookSelect.append(new Option(book.title, book.id));
                });
                bookSelect.prop('disabled', false);
            } else {
                bookSelect.html('<option value="">— No Books Found —</option>');
            }
        });
    });
});