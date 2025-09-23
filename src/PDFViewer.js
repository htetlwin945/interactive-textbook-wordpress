import ToolbarManager from './ToolbarManager.js';
import AnnotationEngine from './AnnotationEngine.js';
import { debounce, isElementInViewport } from './utils.js';

// Orchestrates the entire PDF viewer application.
export default class PDFViewer {
    constructor(container) {
        this.container = container;
        this.pdfUrl = container.dataset.pdfUrl;
        this.documentId = container.dataset.documentId;
        this.initialPage = parseInt(container.dataset.initialPage, 10) || 1;

        // --- DOM Elements (owned by the viewer) ---
        this.pageContainer = document.getElementById('page-container');
        this.pdfScrollContainer = document.getElementById('pdf-scroll-container');
        this.thumbnailView = document.getElementById('thumbnail-view');

        // --- State ---
        this.pdfDoc = null;
        this.pageElements = [];
        this.thumbnailElements = [];
        this.pageStates = {};
        this.currentPageNum = this.initialPage;
        this.currentTool = 'pan';
        this.historyLock = false;
        this.currentScale = 1.0;
        this.isPanMode = false;
        this.isPanning = false;
        this.lastPanPoint = { x: 0, y: 0 };
        this.touchState = { pinching: false, panning: false, singleFingerPan: false, lastDist: 0, lastPanPoint: { x: 0, y: 0 } };

        // --- Constants ---
        this.MIN_SCALE = 0.25;
        this.MAX_SCALE = 5.0;

        // --- Child Components ---
        this.toolbar = new ToolbarManager(this);
        this.annotations = new AnnotationEngine(this);

        this.debouncedRenderVisiblePages = debounce(this.renderVisiblePages.bind(this), 150);
    }

    async init() {
        this.toolbar.init();
        this.toolbar.pageInput.value = this.currentPageNum;
        this.container.classList.add('sidebar-closed');

        if (!this.pdfUrl) {
            console.error('PDF URL is missing.');
            return;
        }

        pdfjsLib.GlobalWorkerOptions.workerSrc = `https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.11.338/pdf.worker.min.js`;

        try {
            const loadingTask = pdfjsLib.getDocument(this.pdfUrl);
            this.pdfDoc = await loadingTask.promise;
            this.toolbar.pageCountSpan.textContent = this.pdfDoc.numPages;
            this.toolbar.pageInput.max = this.pdfDoc.numPages;

            this.setupObservers();
            this.createPagePlaceholders();
            this.renderThumbnails();

            const initialPageDiv = this.pageElements[this.initialPage - 1];
            if (initialPageDiv) {
                initialPageDiv.scrollIntoView({ behavior: 'auto', block: 'start' });
            }
        } catch (reason) {
            console.error('Error loading PDF:', reason);
        }

        this.bindCoreEventListeners();
    }

    setupObservers() {
        const pageRenderObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    this.renderInitialPage(entry.target);
                    pageRenderObserver.unobserve(entry.target);
                }
            });
        }, { root: this.pdfScrollContainer, rootMargin: '300px 0px' });

        const pageNumObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const pageNum = parseInt(entry.target.dataset.pageNumber, 10);
                    this.currentPageNum = pageNum;
                    this.toolbar.pageInput.value = pageNum;
                    this.toolbar.updateUndoRedoButtons();
                    this.annotations.debouncedSaveLastPage(pageNum);

                    this.thumbnailElements.forEach(thumb => thumb.classList.remove('active'));
                    const activeThumb = this.thumbnailView.querySelector(`[data-page-num-thumb="${pageNum}"]`);
                    if (activeThumb) {
                        activeThumb.classList.add('active');
                        activeThumb.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                }
            });
        }, { root: this.pdfScrollContainer, threshold: 0.5 });

        this.pageRenderObserver = pageRenderObserver;
        this.pageNumObserver = pageNumObserver;
    }

    createPagePlaceholders() {
        const firstPage = this.pdfDoc.getPage(1);
        const initialViewport = firstPage.getViewport({ scale: this.currentScale });

        for (let i = 1; i <= this.pdfDoc.numPages; i++) {
            const pageDiv = document.createElement('div');
            pageDiv.className = 'pdf-page-container';
            pageDiv.dataset.pageNumber = i;

            pageDiv.style.width = `${initialViewport.width}px`;
            pageDiv.style.height = `${initialViewport.height}px`;

            this.pageContainer.appendChild(pageDiv);
            this.pageElements.push(pageDiv);

            this.pageRenderObserver.observe(pageDiv);
            this.pageNumObserver.observe(pageDiv);
        }
    }

    async renderInitialPage(pageDiv) {
        if (pageDiv.hasAttribute('data-rendered')) return;
        const pageNum = parseInt(pageDiv.dataset.pageNumber, 10);
        try {
            const page = await this.pdfDoc.getPage(pageNum);
            const baseViewport = page.getViewport({ scale: 1.0 });

            const pdfCanvas = document.createElement('canvas');
            pdfCanvas.className = 'pdf-canvas';
            pageDiv.appendChild(pdfCanvas);

            const annotationCanvasEl = document.createElement('canvas');
            annotationCanvasEl.className = 'annotation-canvas';
            pageDiv.appendChild(annotationCanvasEl);

            const fabricCanvas = new fabric.Canvas(annotationCanvasEl);
            this.pageStates[pageNum] = {
                fabricCanvas,
                history: [],
                redoStack: [],
                page,
                baseViewport
            };

            pageDiv.setAttribute('data-rendered', 'true');

            this.updatePageStructure(pageDiv);
            await this.renderPdfOnCanvas(pageDiv);

            await this.annotations.loadAnnotations(pageNum, fabricCanvas);
            this.annotations.attachAnnotationListeners(fabricCanvas, pageNum);
        } catch(error) {
            console.error(`Error rendering page ${pageNum}:`, error);
        }
    }

    updatePageStructure(pageDiv) {
        const pageNum = parseInt(pageDiv.dataset.pageNumber, 10);
        const pageState = this.pageStates[pageNum];
        if (!pageState || !pageState.baseViewport) return;

        const viewport = pageState.baseViewport.clone({ scale: this.currentScale });

        pageDiv.style.width = `${viewport.width}px`;
        pageDiv.style.height = `${viewport.height}px`;

        const pdfCanvas = pageDiv.querySelector('.pdf-canvas');
        if (pdfCanvas) {
            pdfCanvas.width = viewport.width;
            pdfCanvas.height = viewport.height;
        }

        const fabricCanvas = pageState.fabricCanvas;
        if (fabricCanvas) {
            fabricCanvas.setDimensions({ width: viewport.width, height: viewport.height });
            fabricCanvas.setZoom(this.currentScale);
            fabricCanvas.renderAll();
        }
    }

    async renderPdfOnCanvas(pageDiv) {
        const pageNum = parseInt(pageDiv.dataset.pageNumber, 10);
        const pageState = this.pageStates[pageNum];
        const pdfCanvas = pageDiv.querySelector('.pdf-canvas');

        if (!pageState || !pageState.page || !pdfCanvas || pageDiv.dataset.renderedScale === String(this.currentScale) || pageDiv.hasAttribute('data-pdf-rendering')) {
            return;
        }

        const viewport = pageState.baseViewport.clone({ scale: this.currentScale });

        pageDiv.setAttribute('data-pdf-rendering', 'true');
        try {
            await pageState.page.render({ canvasContext: pdfCanvas.getContext('2d'), viewport }).promise;
            pageDiv.dataset.renderedScale = String(this.currentScale);
        } catch (error) {
            console.error(`Error rendering page ${pageNum}`, error);
        } finally {
            pageDiv.removeAttribute('data-pdf-rendering');
        }
    }

    renderVisiblePages() {
        this.pageElements.forEach(pageDiv => {
            if (pageDiv.hasAttribute('data-rendered') && isElementInViewport(pageDiv, this.pdfScrollContainer)) {
                this.renderPdfOnCanvas(pageDiv);
            }
        });
    }

    async renderThumbnails() {
        const thumbWidth = 180;
        for (let i = 1; i <= this.pdfDoc.numPages; i++) {
            try {
                const page = await this.pdfDoc.getPage(i);
                const viewport = page.getViewport({ scale: 1.0 });
                const scale = thumbWidth / viewport.width;
                const thumbViewport = page.getViewport({ scale });

                const thumbContainer = document.createElement('div');
                thumbContainer.className = 'thumbnail-item';
                thumbContainer.dataset.pageNumThumb = i;

                const canvas = document.createElement('canvas');
                canvas.width = thumbViewport.width;
                canvas.height = thumbViewport.height;
                thumbContainer.appendChild(canvas);

                this.thumbnailView.appendChild(thumbContainer);
                this.thumbnailElements.push(thumbContainer);

                page.render({ canvasContext: canvas.getContext('2d'), viewport: thumbViewport });

                thumbContainer.addEventListener('click', () => {
                    this.pageElements[i - 1].scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            } catch(error) {
                console.error(`Error rendering thumbnail for page ${i}:`, error);
            }
        }
    }

    setZoom(newScale) {
        this.currentScale = Math.max(this.MIN_SCALE, Math.min(this.MAX_SCALE, newScale));

        this.pageElements.forEach(pageDiv => {
            delete pageDiv.dataset.renderedScale;
        });

        this.pageElements.forEach(pageDiv => {
            if (pageDiv.hasAttribute('data-rendered')) {
                this.updatePageStructure(pageDiv);
            }
        });

        this.debouncedRenderVisiblePages();
    }

    setActiveTool(toolName) {
        this.currentTool = toolName;
        this.toolbar.toolButtons.forEach(btn => btn.classList.remove('active-tool'));
        document.getElementById(`${toolName}-tool-btn`).classList.add('active-tool');

        this.pdfScrollContainer.classList.remove('pan-mode');
        this.isPanMode = false;

        Object.values(this.pageStates).forEach(state => {
            if (state.fabricCanvas) {
                const canvas = state.fabricCanvas;
                canvas.isDrawingMode = false;
                canvas.selection = false;
                canvas.forEachObject(obj => obj.selectable = false);

                if (toolName === 'select') {
                    canvas.selection = true;
                    canvas.forEachObject(obj => obj.selectable = true);
                } else if (toolName === 'pan') {
                    this.isPanMode = true;
                    this.pdfScrollContainer.classList.add('pan-mode');
                } else if (toolName === 'freehand' || toolName === 'highlight') {
                    canvas.isDrawingMode = true;
                    const color = this.toolbar.colorPicker.value;
                    const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(color);
                    const rgb = result ? `${parseInt(result[1], 16)}, ${parseInt(result[2], 16)}, ${parseInt(result[3], 16)}` : '255, 255, 0';
                    canvas.freeDrawingBrush.color = (toolName === 'highlight') ? `rgba(${rgb}, 0.4)` : color;
                    canvas.freeDrawingBrush.width = parseInt(this.toolbar.sizeSlider.value, 10);
                }
            }
        });
    }

    toggleFullScreen() {
        if (!document.fullscreenElement) {
            this.container.requestFullscreen();
        } else {
            document.exitFullscreen();
        }
    }

    bindCoreEventListeners() {
        this.pdfScrollContainer.addEventListener('scroll', this.debouncedRenderVisiblePages);
        this.pdfScrollContainer.addEventListener('wheel', e => {
            if (e.ctrlKey) {
                e.preventDefault();
                const delta = e.deltaY > 0 ? -0.15 : 0.15;
                this.setZoom(this.currentScale + delta);
            }
        }, { passive: false });

        // Pan listeners
        this.pdfScrollContainer.addEventListener('mousedown', e => {
            if (this.isPanMode) {
                this.isPanning = true;
                this.lastPanPoint = { x: e.clientX, y: e.clientY };
                this.pdfScrollContainer.classList.add('panning');
            }
        });
        document.addEventListener('mousemove', e => {
            if (this.isPanning) {
                const dx = e.clientX - this.lastPanPoint.x;
                const dy = e.clientY - this.lastPanPoint.y;
                this.pdfScrollContainer.scrollLeft -= dx;
                this.pdfScrollContainer.scrollTop -= dy;
                this.lastPanPoint = { x: e.clientX, y: e.clientY };
            }
        });
        document.addEventListener('mouseup', () => {
            if (this.isPanning) {
                this.isPanning = false;
                this.pdfScrollContainer.classList.remove('panning');
            }
        });

        // Key listeners for tool switching can be added here
    }
}
