// Manages all toolbar elements and their event listeners.
export default class ToolbarManager {
    constructor(viewer) {
        this.viewer = viewer; // A reference to the main PDFViewer instance

        // --- DOM ELEMENT REFERENCES ---
        this.prevPageBtn = document.getElementById('prev-page-btn');
        this.nextPageBtn = document.getElementById('next-page-btn');
        this.fullscreenBtn = document.getElementById('fullscreen-btn');
        this.zoomInBtn = document.getElementById('zoom-in-btn');
        this.zoomOutBtn = document.getElementById('zoom-out-btn');
        this.pageInput = document.getElementById('page-input');
        this.clearPageBtn = document.getElementById('clear-page-btn');
        this.selectToolBtn = document.getElementById('select-tool-btn');
        this.panToolBtn = document.getElementById('pan-tool-btn');
        this.freehandToolBtn = document.getElementById('freehand-tool-btn');
        this.highlightToolBtn = document.getElementById('highlight-tool-btn');
        this.textNoteToolBtn = document.getElementById('text-note-tool-btn');
        this.colorPicker = document.getElementById('color-picker');
        this.sizeSlider = document.getElementById('size-slider');
        this.undoBtn = document.getElementById('undo-btn');
        this.redoBtn = document.getElementById('redo-btn');
        this.sidebarToggleBtn = document.getElementById('sidebar-toggle-btn');
        this.floatingZoomInBtn = document.getElementById('floating-zoom-in-btn');
        this.floatingZoomOutBtn = document.getElementById('floating-zoom-out-btn');

        this.toolButtons = [this.selectToolBtn, this.panToolBtn, this.freehandToolBtn, this.highlightToolBtn, this.textNoteToolBtn];
    }

    init() {
        // --- BIND EVENT LISTENERS ---
        this.sidebarToggleBtn.addEventListener('click', () => this.viewer.container.classList.toggle('sidebar-closed'));
        this.zoomInBtn.addEventListener('click', () => this.viewer.setZoom(this.viewer.currentScale + 0.25));
        this.zoomOutBtn.addEventListener('click', () => this.viewer.setZoom(this.viewer.currentScale - 0.25));
        if (this.floatingZoomInBtn) this.floatingZoomInBtn.addEventListener('click', () => this.viewer.setZoom(this.viewer.currentScale + 0.25));
        if (this.floatingZoomOutBtn) this.floatingZoomOutBtn.addEventListener('click', () => this.viewer.setZoom(this.viewer.currentScale - 0.25));

        this.fullscreenBtn.addEventListener('click', () => this.viewer.toggleFullScreen());

        this.prevPageBtn.addEventListener('click', () => {
            const targetPage = this.viewer.currentPageNum - 1;
            if (targetPage > 0) {
                this.viewer.pageElements[targetPage - 1].scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });

        this.nextPageBtn.addEventListener('click', () => {
            const targetPage = this.viewer.currentPageNum + 1;
            if (targetPage <= this.viewer.pdfDoc.numPages) {
                this.viewer.pageElements[targetPage - 1].scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });

        this.pageInput.addEventListener('change', () => {
            const newPageNum = parseInt(this.pageInput.value, 10);
            if (newPageNum > 0 && newPageNum <= this.viewer.pdfDoc.numPages) {
                this.viewer.pageElements[newPageNum - 1].scrollIntoView({ behavior: 'auto', block: 'start' });
            } else {
                this.pageInput.value = this.viewer.currentPageNum;
            }
        });

        this.toolButtons.forEach(btn => {
            btn.addEventListener('click', () => this.viewer.setActiveTool(btn.id.replace('-tool-btn', '')));
        });

        this.colorPicker.addEventListener('change', () => {
            this.viewer.annotations.saveUserColor(this.colorPicker.value);
            this.viewer.setActiveTool(this.viewer.currentTool);
        });

        this.sizeSlider.addEventListener('input', () => this.viewer.setActiveTool(this.viewer.currentTool));

        this.undoBtn.addEventListener('click', () => this.viewer.annotations.undo());
        this.redoBtn.addEventListener('click', () => this.viewer.annotations.redo());

        this.clearPageBtn.addEventListener('click', () => {
            const state = this.viewer.pageStates[this.viewer.currentPageNum];
            if (state && confirm('Clear all annotations on this page?')) {
                state.fabricCanvas.clear();
                this.viewer.annotations.saveState(this.viewer.currentPageNum, state.fabricCanvas);
            }
        });
    }

    updateUndoRedoButtons() {
        const state = this.viewer.pageStates[this.viewer.currentPageNum];
        this.undoBtn.disabled = !state || state.history.length <= 1;
        this.redoBtn.disabled = !state || state.redoStack.length === 0;
    }
}
