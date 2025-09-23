import { debounce } from './utils.js';

// Manages the Fabric.js canvas, annotations, history, and saving/loading.
export default class AnnotationEngine {
    constructor(viewer) {
        this.viewer = viewer; // A reference to the main PDFViewer instance

        this.debouncedSave = debounce(this.saveAnnotations.bind(this), 2000);
        this.debouncedSaveLastPage = debounce(this.saveLastPage.bind(this), 1500);
    }

    // --- Annotation & Data Logic ---
    async saveLastPage(pageNum) {
        if (!this.viewer.documentId || !pageNum) return;
        try {
            await fetch(`${interactiveCurriculumData.restUrl}interactive-curriculum/v2/last-page`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': interactiveCurriculumData.nonce },
                body: JSON.stringify({ document_id: this.viewer.documentId, page_num: pageNum })
            });
        } catch (error) {
            console.error('Error saving last page:', error);
        }
    }

    async saveUserColor(color) {
        try {
            await fetch(`${interactiveCurriculumData.restUrl}interactive-curriculum/v2/user-color`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': interactiveCurriculumData.nonce },
                body: JSON.stringify({ color: color })
            });
        } catch (error) {
            console.error('Error saving user color:', error);
        }
    }

    async saveAnnotations(pageNum, fabricCanvas) {
        if (!this.viewer.documentId || !pageNum) return;
        this.viewer.toolbar.saveStatusSpan.textContent = 'Saving...';
        this.viewer.toolbar.saveStatusSpan.style.opacity = '1';
        try {
            await fetch(`${interactiveCurriculumData.restUrl}interactive-curriculum/v2/annotations/${this.viewer.documentId}/${pageNum}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': interactiveCurriculumData.nonce },
                body: JSON.stringify(fabricCanvas.toJSON())
            });
            this.viewer.toolbar.saveStatusSpan.textContent = 'Saved';
        } catch (error) {
            this.viewer.toolbar.saveStatusSpan.textContent = 'Error';
            console.error('Error saving annotations:', error);
        } finally {
            setTimeout(() => { this.viewer.toolbar.saveStatusSpan.style.opacity = '0'; }, 2000);
        }
    }

    saveState(pageNum, fabricCanvas, doSave = true) {
        const pageState = this.viewer.pageStates[pageNum];
        if (!pageState || this.viewer.historyLock) return;
        pageState.redoStack = [];
        pageState.history.push(JSON.stringify(fabricCanvas.toJSON()));
        this.viewer.toolbar.updateUndoRedoButtons();
        if (doSave) {
            this.debouncedSave(pageNum, fabricCanvas);
        }
    }

    undo() {
        const pageState = this.viewer.pageStates[this.viewer.currentPageNum];
        if (!pageState || pageState.history.length <= 1) return;
        this.viewer.historyLock = true;
        pageState.redoStack.push(pageState.history.pop());
        const prevState = JSON.parse(pageState.history[pageState.history.length - 1]);
        pageState.fabricCanvas.loadFromJSON(prevState, () => {
            pageState.fabricCanvas.renderAll();
            this.viewer.historyLock = false;
            this.viewer.toolbar.updateUndoRedoButtons();
            this.debouncedSave(this.viewer.currentPageNum, pageState.fabricCanvas);
        });
    }

    redo() {
        const pageState = this.viewer.pageStates[this.viewer.currentPageNum];
        if (!pageState || pageState.redoStack.length === 0) return;
        this.viewer.historyLock = true;
        const nextStateRaw = pageState.redoStack.pop();
        pageState.history.push(nextStateRaw);
        pageState.fabricCanvas.loadFromJSON(JSON.parse(nextStateRaw), () => {
            pageState.fabricCanvas.renderAll();
            this.viewer.historyLock = false;
            this.viewer.toolbar.updateUndoRedoButtons();
            this.debouncedSave(this.viewer.currentPageNum, pageState.fabricCanvas);
        });
    }

    async loadAnnotations(pageNum, fabricCanvas) {
        try {
            const response = await fetch(`${interactiveCurriculumData.restUrl}interactive-curriculum/v2/annotations/${this.viewer.documentId}/${pageNum}`, {
                headers: { 'X-WP-Nonce': interactiveCurriculumData.nonce }
            });
            if (response.ok) {
                const data = await response.json();
                if (data && data.objects && data.objects.length > 0) {
                    fabricCanvas.loadFromJSON(data, () => {
                        fabricCanvas.setZoom(this.viewer.currentScale);
                        fabricCanvas.renderAll();
                    });
                }
            }
        } catch (error) {
            console.error('Error loading annotations:', error);
        }
        this.saveState(pageNum, fabricCanvas, false);
    }

    attachAnnotationListeners(fabricCanvas, pageNum) {
        fabricCanvas.on({
            'object:added': () => this.saveState(pageNum, fabricCanvas),
            'object:modified': () => this.saveState(pageNum, fabricCanvas),
            'object:removed': () => this.saveState(pageNum, fabricCanvas)
        });
        fabricCanvas.on('mouse:down', (opt) => {
            if (this.viewer.currentTool === 'text-note' && !opt.target) {
                const text = new fabric.IText('Type here...', {
                    left: opt.pointer.x,
                    top: opt.pointer.y,
                    fontSize: 18 / this.viewer.currentScale,
                    fill: this.viewer.toolbar.colorPicker.value
                });
                fabricCanvas.add(text);
                this.viewer.setActiveTool('select');
            }
        });
    }
}
