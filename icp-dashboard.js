document.addEventListener('DOMContentLoaded', function() {
    const gridViewBtn = document.getElementById('icp-grid-view-btn');
    const listViewBtn = document.getElementById('icp-list-view-btn');
    const bookList = document.querySelector('.dashboard-book-list');

    // Exit if any of the required elements are not found
    if (!gridViewBtn || !listViewBtn || !bookList) {
        return;
    }

    gridViewBtn.addEventListener('click', function() {
        if (!this.classList.contains('active')) {
            bookList.classList.remove('list-view');
            gridViewBtn.classList.add('active');
            listViewBtn.classList.remove('active');
        }
    });

    listViewBtn.addEventListener('click', function() {
        if (!this.classList.contains('active')) {
            bookList.classList.add('list-view');
            listViewBtn.classList.add('active');
            gridViewBtn.classList.remove('active');
        }
    });
});