import PDFViewer from './PDFViewer.js';

document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('interactive-curriculum-container');
    if (container) {
        const viewer = new PDFViewer(container);
        viewer.init();
    }
});
