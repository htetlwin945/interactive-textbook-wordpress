document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('interactive-curriculum-container');
    if (!container) return;

    pdfjsLib.GlobalWorkerOptions.workerSrc = `https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.11.338/pdf.worker.min.js`;

    // --- START STYLES ---
    const style = document.createElement('style');
    style.innerHTML = `
        #interactive-curriculum-container {
            font-family: sans-serif; display: flex; flex-direction: column; width: 100%; height: 85vh;
            max-width: 1200px; margin: auto; background-color: #f0f0f0; border: 1px solid #ccc;
            border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); overflow: hidden;
        }
        #interactive-curriculum-container:fullscreen { max-width: 100%; height: 100%; border-radius: 0; border: none; }
        #pdf-viewer-toolbar {
            display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 8px;
            padding: 8px 12px; background-color: #404040; border-bottom: 1px solid #333; width: 100%;
            box-sizing: border-box; z-index: 10; flex-shrink: 0; color: #fff;
        }
        @media (max-width: 768px) {
            #interactive-curriculum-container {
                flex-direction: row;
            }
            #pdf-viewer-toolbar {
                flex-direction: column;
                flex-wrap: nowrap;
                width: 65px;
                height: 100%;
                overflow-y: auto;
                overflow-x: hidden;
                justify-content: flex-start;
                gap: 15px;
            }
            .toolbar-group {
                flex-direction: column;
                border-right: none;
                border-bottom: 1px solid #666;
                padding: 15px 0;
                width: 100%;
            }
            .toolbar-group:not(:last-child) {
                border-right: none;
            }
            .page-info {
                flex-direction: column;
                gap: 4px;
            }
            #page-input {
                width: 45px;
            }
            #size-slider {
                -webkit-appearance: slider-vertical;
                writing-mode: bt-lr;
                width: 8px;
                height: 100px;
                padding: 0 5px;
                margin: 10px 0;
            }
            #floating-zoom-controls {
                display: flex;
            }
        }
        .toolbar-group { display: flex; align-items: center; padding: 0 10px; }
        .toolbar-group:not(:last-child) { border-right: 1px solid #666; }
        .toolbar-btn {
            background-color: transparent; border: none; color: #fff; cursor: pointer;
            border-radius: 4px; padding: 6px; margin: 0 2px;
        }
        .toolbar-btn:disabled { cursor: not-allowed; opacity: 0.5; }
        .toolbar-btn.active-tool { background-color: #007bff; }
        .toolbar-btn svg { width: 24px; height: 24px; fill: currentColor; }
        .page-info { display: flex; align-items: center; }
        .toolbar-input {
            background-color: #222; border: 1px solid #666; color: #fff;
            border-radius: 4px; width: 50px; text-align: center; margin: 0 5px;
            -moz-appearance: textfield;
        }
        .toolbar-input::-webkit-outer-spin-button, .toolbar-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .color-picker-wrapper {
            position: relative; width: 28px; height: 28px; border-radius: 50%;
            overflow: hidden; margin: 0 4px; flex-shrink: 0;
            border: 1px solid #888;
        }
        #color-picker {
            position: absolute; top: -2px; left: -2px; width: 32px; height: 32px;
            border: none; background: transparent; cursor: pointer; padding: 0;
        }
        #color-picker::-webkit-color-swatch { border: none; border-radius: 50%; }
        #color-picker::-moz-color-swatch { border: none; border-radius: 50%; }
        #pdf-viewer-main { display: flex; flex-direction: row; width: 100%; height: 100%; overflow: hidden; position: relative; }
        #pdf-sidebar {
            width: 200px; height: 100%; background-color: #e4e4e4; overflow-y: auto;
            border-right: 1px solid #ccc; transition: margin-left 0.3s ease-in-out; flex-shrink: 0;
        }
        #pdf-scroll-container {
            flex-grow: 1; height: 100%; overflow: auto; background-color: #525659;
            text-align: center; cursor: default;
        }
        #pdf-scroll-container.pan-mode { cursor: grab; }
        #pdf-scroll-container.pan-mode.panning { cursor: grabbing; }
        #page-container { padding: 20px 0; }
        #interactive-curriculum-container.sidebar-closed #pdf-sidebar { margin-left: -200px; }
        .pdf-page-container {
            margin: 0 auto 20px auto; box-shadow: 0 4px 12px rgba(0,0,0,0.25);
            position: relative;
        }
        .pdf-page-container canvas {
            position: absolute; top: 0; left: 0; display: block;
            width: 100% !important; height: 100% !important;
        }
        .thumbnail-item {
             border: 3px solid transparent; margin-bottom: 10px; cursor: pointer;
             transition: border-color 0.2s; background-color: #bbb; min-height: 150px;
        }
        .thumbnail-item.active { border-color: #007bff; }
        #floating-zoom-controls {
            position: absolute;
            bottom: 20px;
            right: 20px;
            z-index: 20;
            display: none; /* Hide by default on larger screens */
            flex-direction: column;
            gap: 10px;
        }
        .floating-zoom-btn {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            border: 1px solid #aaa;
            background-color: rgba(64, 64, 64, 0.9);
            color: white;
            font-size: 32px;
            font-weight: bold;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
            display: flex;
            justify-content: center;
            align-items: center;
            line-height: 1;
        }
        @media (max-width: 768px) {
            #floating-zoom-controls {
                display: flex;
            }
        }
    `;
    document.head.appendChild(style);
    // --- END STYLES ---

    // --- START FABRIC.JS CUSTOMIZATION ---
    const deleteIcon = "data:image/svg+xml,%3C%3Fxml version='1.0' encoding='utf-8'%3F%3E%3C!DOCTYPE svg PUBLIC '-//W3C//DTD SVG 1.1//EN' 'http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd'%3E%3Csvg version='1.1' id='Ebene_1' xmlns='http://www.w3.org/2000/svg' xmlns:xlink='http://www.w3.org/1999/xlink' x='0px' y='0px' width='595.275px' height='595.275px' viewBox='0 0 595.275 595.275' enable-background='new 0 0 595.275 595.275' xml:space='preserve'%3E%3Cg%3E%3Cpath fill='%23FFFFFF' d='M353.905,297.638l225.856-225.856c8.034-8.034,8.034-21.054,0-29.088L545.08,8.012 c-8.034-8.034-21.054-8.034-29.088,0L290.136,233.867L64.279,8.012c-8.034-8.034-21.054-8.034-29.088,0L8.012,42.693 c-8.034,8.034-8.034,21.054,0,29.088l225.856,225.856L8.012,523.493c-8.034,8.034-8.034,21.054,0,29.088l34.681,34.681 c8.034,8.034,21.054,8.034,29.088,0l225.856-225.855l225.855,225.855c8.034,8.034,21.054,8.034,29.088,0l34.681-34.681 c8.034-8.034,8.034-21.054,0-29.088L353.905,297.638z'/%3E%3C/g%3E%3C/svg%3E";
    const deleteImg = document.createElement('img');
    deleteImg.src = deleteIcon;
    function renderIcon(ctx, left, top, styleOverride, fabricObject) { const size = this.cornerSize; ctx.save(); ctx.translate(left, top); ctx.rotate(fabric.util.degreesToRadians(fabricObject.angle)); ctx.fillStyle = 'red'; ctx.beginPath(); ctx.arc(0, 0, size / 2, 0, 2 * Math.PI); ctx.fill(); ctx.drawImage(deleteImg, -size / 2, -size / 2, size, size); ctx.restore(); }
    function deleteObject(eventData, transform) { const target = transform.target; const canvas = target.canvas; canvas.remove(target); canvas.requestRenderAll(); return true; }
    fabric.Object.prototype.transparentCorners = false;
    fabric.Object.prototype.cornerColor = '#007bff';
    fabric.Object.prototype.cornerStyle = 'circle';
    fabric.Object.prototype.cornerSize = 24;
    fabric.Object.prototype.controls.deleteControl = new fabric.Control({ x: 0.5, y: -0.5, offsetY: -10, offsetX: 10, cursorStyle: 'pointer', mouseUpHandler: deleteObject, render: renderIcon, cornerSize: 24 });
    // --- END FABRIC.JS CUSTOMIZATION ---

    // --- DOM ELEMENT REFERENCES ---
    const data = window.interactiveCurriculumData || {};
    const isReadOnly = data.isReadOnly || false;
    const pdfUrl = data.pdfUrl || container.dataset.pdfUrl;
    const documentId = data.documentId || container.dataset.documentId;
    const studentId = data.studentId || null;
    const initialPage = data.initialPage || (parseInt(container.dataset.initialPage, 10) || 1);
    const prevPageBtn = document.getElementById('prev-page-btn');
    const nextPageBtn = document.getElementById('next-page-btn');
    const fullscreenBtn = document.getElementById('fullscreen-btn');
    const zoomInBtn = document.getElementById('zoom-in-btn');
    const zoomOutBtn = document.getElementById('zoom-out-btn');
    const pageInput = document.getElementById('page-input');
    const pageCountSpan = document.getElementById('page-count');
    const pageContainer = document.getElementById('page-container');
    const pdfScrollContainer = document.getElementById('pdf-scroll-container');
    const clearPageBtn = document.getElementById('clear-page-btn');
    const selectToolBtn = document.getElementById('select-tool-btn');
    const panToolBtn = document.getElementById('pan-tool-btn');
    const freehandToolBtn = document.getElementById('freehand-tool-btn');
    const highlightToolBtn = document.getElementById('highlight-tool-btn');
    const textNoteToolBtn = document.getElementById('text-note-tool-btn');
    const colorPicker = document.getElementById('color-picker');
    const sizeSlider = document.getElementById('size-slider');
    const undoBtn = document.getElementById('undo-btn');
    const redoBtn = document.getElementById('redo-btn');
    const toolButtons = [selectToolBtn, panToolBtn, freehandToolBtn, highlightToolBtn, textNoteToolBtn];
    const sidebarToggleBtn = document.getElementById('sidebar-toggle-btn');
    const thumbnailView = document.getElementById('thumbnail-view');
    const saveStatusSpan = document.getElementById('save-status');
    const floatingZoomInBtn = document.getElementById('floating-zoom-in-btn');
    const floatingZoomOutBtn = document.getElementById('floating-zoom-out-btn');
    // --- END DOM ELEMENT REFERENCES ---

    // --- STATE VARIABLES ---
    let pdfDoc = null;
    let pageElements = [];
    let thumbnailElements = [];
    let pageStates = {};
    let currentPageNum = initialPage;
    let currentTool = 'pan';
    let historyLock = false;
    let currentScale = 1.0;
    const MIN_SCALE = 0.25;
    const MAX_SCALE = 5.0;
    let isPanMode = false;
    let isPanning = false;
    let lastPanPoint = { x: 0, y: 0 };
    let touchState = { pinching: false, panning: false, singleFingerPan: false, lastDist: 0, lastPanPoint: { x: 0, y: 0 } };
    // --- END STATE VARIABLES ---

    // --- UTILITY & CORE FUNCTIONS ---
    function debounce(func, delay) { let timeout; return function(...args) { clearTimeout(timeout); timeout = setTimeout(() => func.apply(this, args), delay); }; }
    function isElementInViewport(el) {
        if (!el || typeof el.getBoundingClientRect !== 'function') return false;
        const rect = el.getBoundingClientRect();
        const containerRect = pdfScrollContainer.getBoundingClientRect();

        // Check for a valid rectangle
        if (rect.width === 0 && rect.height === 0) return false;

        // Check if the element is within the vertical and horizontal viewport of the container
        return (
            rect.top < containerRect.bottom &&
            rect.bottom > containerRect.top &&
            rect.left < containerRect.right &&
            rect.right > containerRect.left
        );
    }
    function toggleFullScreen() { if (!document.fullscreenElement) { container.requestFullscreen(); } else { document.exitFullscreen(); } }

    const debouncedRenderVisiblePages = debounce(() => {
        pageElements.forEach(pageDiv => {
            if (pageDiv.hasAttribute('data-rendered') && isElementInViewport(pageDiv)) {
                renderPdfOnCanvas(pageDiv);
            }
        });
    }, 150);

    function setZoom(newScale) {
        currentScale = Math.max(MIN_SCALE, Math.min(MAX_SCALE, newScale));

        // When zoom changes, the actual PDF content of all pages needs to be re-rendered.
        // We delete the 'renderedScale' attribute so the lazy loader knows they are stale.
        pageElements.forEach(pageDiv => {
            delete pageDiv.dataset.renderedScale;
        });

        // First, update the structure of all pages quickly. This resizes containers and canvases.
        pageElements.forEach(pageDiv => {
            if (pageDiv.hasAttribute('data-rendered')) {
                updatePageStructure(pageDiv);
            }
        });

        // Then, render only the pages that are now visible after a short delay.
        debouncedRenderVisiblePages();
    }

    // --- ANNOTATION & DATA LOGIC ---
    async function saveLastPage(pageNum) { if (!documentId || !pageNum) return; try { await fetch(`${interactiveCurriculumData.restUrl}interactive-curriculum/v2/last-page`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': interactiveCurriculumData.nonce }, body: JSON.stringify({ document_id: documentId, page_num: pageNum }) }); } catch (error) { console.error('Error saving last page:', error); } }
    async function saveUserColor(color) { try { await fetch(`${interactiveCurriculumData.restUrl}interactive-curriculum/v2/user-color`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': interactiveCurriculumData.nonce }, body: JSON.stringify({ color: color }) }); } catch (error) { console.error('Error saving user color:', error); } }
    async function saveAnnotations(pageNum, fabricCanvas) { if (!documentId || !pageNum) return; saveStatusSpan.textContent = 'Saving...'; saveStatusSpan.style.opacity = '1'; try { await fetch(`${interactiveCurriculumData.restUrl}interactive-curriculum/v2/annotations/${documentId}/${pageNum}`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': interactiveCurriculumData.nonce }, body: JSON.stringify(fabricCanvas.toJSON()) }); saveStatusSpan.textContent = 'Saved'; } catch (error) { saveStatusSpan.textContent = 'Error'; console.error('Error saving annotations:', error); } finally { setTimeout(() => { saveStatusSpan.style.opacity = '0'; }, 2000); } }
    const debouncedSave = debounce(saveAnnotations, 2000);
    const debouncedSaveLastPage = debounce(saveLastPage, 1500);

    function saveState(pageNum, fabricCanvas, doSave = true) { if (!pageStates[pageNum] || historyLock) return; const state = pageStates[pageNum]; state.redoStack = []; state.history.push(JSON.stringify(fabricCanvas.toJSON())); updateUndoRedoButtons(); if (doSave) debouncedSave(pageNum, state.fabricCanvas); }
    function undo() { const state = pageStates[currentPageNum]; if (!state || state.history.length <= 1) return; historyLock = true; state.redoStack.push(state.history.pop()); const prevState = JSON.parse(state.history[state.history.length - 1]); state.fabricCanvas.loadFromJSON(prevState, () => { state.fabricCanvas.renderAll(); historyLock = false; updateUndoRedoButtons(); debouncedSave(currentPageNum, state.fabricCanvas); }); }
    function redo() { const state = pageStates[currentPageNum]; if (!state || state.redoStack.length === 0) return; historyLock = true; const nextStateRaw = state.redoStack.pop(); state.history.push(nextStateRaw); state.fabricCanvas.loadFromJSON(JSON.parse(nextStateRaw), () => { state.fabricCanvas.renderAll(); historyLock = false; updateUndoRedoButtons(); debouncedSave(currentPageNum, state.fabricCanvas); }); }
    function updateUndoRedoButtons() { const state = pageStates[currentPageNum]; undoBtn.disabled = !state || state.history.length <= 1; redoBtn.disabled = !state || state.redoStack.length === 0; }

    async function loadAnnotations(pageNum, fabricCanvas) {
        let url = `${data.restUrl}interactive-curriculum/v2/annotations/${documentId}/${pageNum}`;
        if (isReadOnly && studentId) {
            url += `?student_id=${studentId}`;
        }
        try {
            const response = await fetch(url, { headers: { 'X-WP-Nonce': data.nonce } });
            if (response.ok) {
                const resData = await response.json();
                if (resData && resData.objects && resData.objects.length > 0) {
                    fabricCanvas.loadFromJSON(resData, () => {
                        fabricCanvas.setZoom(currentScale);
                        fabricCanvas.renderAll();
                    });
                }
            }
        } catch (error) { console.error('Error loading annotations:', error); }
        saveState(pageNum, fabricCanvas, false);
    }

    function setActiveTool(toolName) {
        currentTool = toolName;
        toolButtons.forEach(btn => btn.classList.remove('active-tool'));
        document.getElementById(`${toolName}-tool-btn`).classList.add('active-tool');

        pdfScrollContainer.classList.remove('pan-mode');
        isPanMode = false;

        Object.values(pageStates).forEach(state => {
            if (state.fabricCanvas) {
                const canvas = state.fabricCanvas;
                canvas.isDrawingMode = false;

                // Default to non-interactive mode for annotations
                canvas.selection = false;
                canvas.forEachObject(obj => obj.selectable = false);

                if (toolName === 'select') {
                    // Only enable selection for the select tool
                    canvas.selection = true;
                    canvas.forEachObject(obj => obj.selectable = true);
                } else if (toolName === 'pan') {
                    isPanMode = true;
                    pdfScrollContainer.classList.add('pan-mode');
                    // Selection is already false from the default setting above
                } else if (toolName === 'freehand' || toolName === 'highlight') {
                    canvas.isDrawingMode = true;
                    const color = colorPicker.value;
                    const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(color);
                    const rgb = result ? `${parseInt(result[1], 16)}, ${parseInt(result[2], 16)}, ${parseInt(result[3], 16)}` : '255, 255, 0';
                    canvas.freeDrawingBrush.color = (toolName === 'highlight') ? `rgba(${rgb}, 0.4)` : color;
                    canvas.freeDrawingBrush.width = parseInt(sizeSlider.value, 10);
                }
            }
        });
    }

    function attachAnnotationListeners(fabricCanvas, pageNum) {
        fabricCanvas.on({ 'object:added': () => saveState(pageNum, fabricCanvas), 'object:modified': () => saveState(pageNum, fabricCanvas), 'object:removed': () => saveState(pageNum, fabricCanvas) });
        fabricCanvas.on('mouse:down', (opt) => {
            if (currentTool === 'text-note' && !opt.target) {
                const text = new fabric.IText('Type here...', { left: opt.pointer.x, top: opt.pointer.y, fontSize: 18 / currentScale, fill: colorPicker.value });
                fabricCanvas.add(text);
                setActiveTool('select');
            }
        });
    }

    // --- PAGE & THUMBNAIL RENDERING ---
    async function renderThumbnails() {
        const thumbWidth = 180;
        for (let i = 1; i <= pdfDoc.numPages; i++) {
            try {
                const page = await pdfDoc.getPage(i);
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

                thumbnailView.appendChild(thumbContainer);
                thumbnailElements.push(thumbContainer);

                page.render({ canvasContext: canvas.getContext('2d'), viewport: thumbViewport });

                thumbContainer.addEventListener('click', () => {
                    pageElements[i - 1].scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            } catch(error) {
                console.error(`Error rendering thumbnail for page ${i}:`, error);
            }
        }
    }

    // --- LAZY RENDERING REFACTOR ---
    // This function only updates the size of the page container and canvases. It's fast.
    function updatePageStructure(pageDiv) {
        const pageNum = parseInt(pageDiv.dataset.pageNumber, 10);
        const pageState = pageStates[pageNum];
        if (!pageState || !pageState.baseViewport) return;

        const viewport = pageState.baseViewport.clone({ scale: currentScale });

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
            fabricCanvas.setZoom(currentScale);
            fabricCanvas.renderAll();
        }
    }

    // This function renders the actual PDF content onto the canvas. It's slow.
    async function renderPdfOnCanvas(pageDiv) {
        const pageNum = parseInt(pageDiv.dataset.pageNumber, 10);
        const pageState = pageStates[pageNum];
        const pdfCanvas = pageDiv.querySelector('.pdf-canvas');

        // Don't re-render if it's already rendered at the current scale or is currently rendering
        if (!pageState || !pageState.page || !pdfCanvas || pageDiv.dataset.renderedScale === String(currentScale) || pageDiv.hasAttribute('data-pdf-rendering')) {
            return;
        }

        const viewport = pageState.baseViewport.clone({ scale: currentScale });

        pageDiv.setAttribute('data-pdf-rendering', 'true');
        try {
            await pageState.page.render({ canvasContext: pdfCanvas.getContext('2d'), viewport }).promise;
            pageDiv.dataset.renderedScale = String(currentScale);
        } catch (error) {
            console.error(`Error rendering page ${pageNum}`, error);
        } finally {
            pageDiv.removeAttribute('data-pdf-rendering');
        }
    }
    // --- END LAZY RENDERING REFACTOR ---

    async function renderInitialPage(pageDiv) {
        if (pageDiv.hasAttribute('data-rendered')) return;
        const pageNum = parseInt(pageDiv.dataset.pageNumber, 10);
        try {
            const page = await pdfDoc.getPage(pageNum);
            const baseViewport = page.getViewport({ scale: 1.0 });

            const pdfCanvas = document.createElement('canvas');
            pdfCanvas.className = 'pdf-canvas';
            pageDiv.appendChild(pdfCanvas);

            const annotationCanvasEl = document.createElement('canvas');
            annotationCanvasEl.className = 'annotation-canvas';
            pageDiv.appendChild(annotationCanvasEl);

            const fabricCanvas = new fabric.Canvas(annotationCanvasEl);

            if (isReadOnly) {
                fabricCanvas.selection = false;
                fabricCanvas.forEachObject(obj => {
                    obj.selectable = false;
                    obj.evented = false;
                });
            }

            pageStates[pageNum] = {
                fabricCanvas,
                history: [],
                redoStack: [],
                page, // Store the page object
                baseViewport // Store the viewport at scale 1.0
            };

            pageDiv.setAttribute('data-rendered', 'true');

            // Use the new refactored functions for the initial render
            updatePageStructure(pageDiv);
            await renderPdfOnCanvas(pageDiv);

            await loadAnnotations(pageNum, fabricCanvas);
            attachAnnotationListeners(fabricCanvas, pageNum);
        } catch(error) {
            console.error(`Error rendering page ${pageNum}:`, error);
        }
    }

    // --- MAIN INITIALIZATION ---
    async function initPDFViewer() {
        if (isReadOnly) {
            // Hide all annotation-related tools
            [freehandToolBtn, highlightToolBtn, textNoteToolBtn, colorPicker, sizeSlider, undoBtn, redoBtn, clearPageBtn, saveStatusSpan].forEach(el => {
                if (el && el.parentElement) el.parentElement.style.display = 'none';
            });
             // Also hide the select tool as it's for annotations
            if (selectToolBtn && selectToolBtn.parentElement) selectToolBtn.parentElement.style.display = 'none';
        }

        if (!pdfUrl) {
            if(isReadOnly) {
                container.innerHTML = '<p>PDF URL not found for this book. Cannot display viewer.</p>';
            }
            return;
        };

        try {
            const loadingTask = pdfjsLib.getDocument(pdfUrl);
            pdfDoc = await loadingTask.promise;
            pageCountSpan.textContent = pdfDoc.numPages;
            pageInput.max = pdfDoc.numPages;

            renderThumbnails(); // Render sidebar previews

            const pageRenderObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        renderInitialPage(entry.target);
                        pageRenderObserver.unobserve(entry.target);
                    }
                });
            }, { root: pdfScrollContainer, rootMargin: '300px 0px' });

            const pageNumObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const pageNum = parseInt(entry.target.dataset.pageNumber, 10);
                        currentPageNum = pageNum;
                        pageInput.value = pageNum;
                        updateUndoRedoButtons(); // Update buttons for the new page's history
                        debouncedSaveLastPage(pageNum);
                        thumbnailElements.forEach(thumb => thumb.classList.remove('active'));
                        const activeThumb = thumbnailView.querySelector(`[data-page-num-thumb="${pageNum}"]`);
                        if (activeThumb) {
                            activeThumb.classList.add('active');
                            activeThumb.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                        }
                    }
                });
            }, { root: pdfScrollContainer, threshold: 0.5 });

            // --- Page Creation and Initial Sizing ---
            // Reverted to a more stable, fixed-scale initialization to prevent rendering bugs.
            // The previous "fit-to-width" logic was causing some PDFs to fail loading.
            currentScale = 1.0;
            const firstPage = await pdfDoc.getPage(1);
            const initialViewport = firstPage.getViewport({ scale: currentScale });

            for (let i = 1; i <= pdfDoc.numPages; i++) {
                const pageDiv = document.createElement('div');
                pageDiv.className = 'pdf-page-container';
                pageDiv.dataset.pageNumber = i;

                // Set placeholder size
                pageDiv.style.width = `${initialViewport.width}px`;
                pageDiv.style.height = `${initialViewport.height}px`;

                pageContainer.appendChild(pageDiv);
                pageElements.push(pageDiv);

                pageRenderObserver.observe(pageDiv);
                pageNumObserver.observe(pageDiv);
            }

            const initialPageDiv = pageElements[initialPage - 1];
            if (initialPageDiv) {
                initialPageDiv.scrollIntoView({ behavior: 'auto', block: 'start' });
                // Manually set the initial page number in the input and state,
                // as the IntersectionObserver may not fire on initial load.
                pageInput.value = initialPage;
                currentPageNum = initialPage;
            }
        } catch (reason) {
            console.error('Error loading PDF:', reason);
        }
    }

    // --- BIND EVENT LISTENERS ---
    sidebarToggleBtn.addEventListener('click', () => container.classList.toggle('sidebar-closed'));
    container.classList.add('sidebar-closed');
    zoomInBtn.addEventListener('click', () => setZoom(currentScale + 0.25));
    zoomOutBtn.addEventListener('click', () => setZoom(currentScale - 0.25));
    if (floatingZoomInBtn) floatingZoomInBtn.addEventListener('click', () => setZoom(currentScale + 0.25));
    if (floatingZoomOutBtn) floatingZoomOutBtn.addEventListener('click', () => setZoom(currentScale - 0.25));
    fullscreenBtn.addEventListener('click', toggleFullScreen);
    prevPageBtn.addEventListener('click', () => { if (currentPageNum > 1 && pageElements[currentPageNum - 2]) pageElements[currentPageNum - 2].scrollIntoView({behavior: 'smooth', block: 'start'}); });
    nextPageBtn.addEventListener('click', () => { if (currentPageNum < pdfDoc.numPages && pageElements[currentPageNum]) pageElements[currentPageNum].scrollIntoView({behavior: 'smooth', block: 'start'}); });
    pageInput.addEventListener('change', () => { const newPageNum = parseInt(pageInput.value, 10); if (newPageNum > 0 && newPageNum <= pdfDoc.numPages) pageElements[newPageNum - 1].scrollIntoView({behavior: 'auto', block: 'start'}); else pageInput.value = currentPageNum; });
    toolButtons.forEach(btn => btn.addEventListener('click', () => setActiveTool(btn.id.replace('-tool-btn', ''))));
    colorPicker.addEventListener('change', () => { saveUserColor(colorPicker.value); setActiveTool(currentTool); });
    sizeSlider.addEventListener('input', () => setActiveTool(currentTool));
    undoBtn.addEventListener('click', undo);
    redoBtn.addEventListener('click', redo);
    clearPageBtn.addEventListener('click', () => { const state = pageStates[currentPageNum]; if (state && confirm('Clear all annotations on this page?')) { state.fabricCanvas.clear(); saveState(currentPageNum, state.fabricCanvas); } });

    document.addEventListener('keydown', e => { if (e.key === 'v' && currentTool !== 'pan') { setActiveTool('pan'); } });
    document.addEventListener('keyup', e => { if (e.key === 'v' && currentTool === 'pan') { setActiveTool('select'); } });

    pdfScrollContainer.addEventListener('mousedown', e => { if (isPanMode) { isPanning = true; lastPanPoint = { x: e.clientX, y: e.clientY }; pdfScrollContainer.classList.add('panning'); } });
    document.addEventListener('mousemove', e => { if (isPanning) { const dx = e.clientX - lastPanPoint.x; const dy = e.clientY - lastPanPoint.y; pdfScrollContainer.scrollLeft -= dx; pdfScrollContainer.scrollTop -= dy; lastPanPoint = { x: e.clientX, y: e.clientY }; } });
    document.addEventListener('mouseup', () => { if (isPanning) { isPanning = false; pdfScrollContainer.classList.remove('panning'); } });

    pdfScrollContainer.addEventListener('wheel', e => { if (e.ctrlKey) { e.preventDefault(); const delta = e.deltaY > 0 ? -0.15 : 0.15; setZoom(currentScale + delta); } }, { passive: false });

    // Lazy render pages on scroll
    pdfScrollContainer.addEventListener('scroll', debouncedRenderVisiblePages);

    // --- New, Intelligent Touch Controls ---
    let lastAnimationFrame = null;

    pdfScrollContainer.addEventListener('touchstart', e => {
        // Two-finger gestures are always for panning and zooming.
        if (e.touches.length === 2) {
            e.preventDefault();
            // Explicitly end one-finger panning when a second finger is introduced.
            touchState.singleFingerPan = false;

            touchState.pinching = true;
            touchState.panning = true;
            touchState.lastDist = Math.hypot(e.touches[0].pageX - e.touches[1].pageX, e.touches[0].pageY - e.touches[1].pageY);
            touchState.lastPanPoint = { x: (e.touches[0].clientX + e.touches[1].clientX) / 2, y: (e.touches[0].clientY + e.touches[1].clientY) / 2 };

            // Prevent accidental selection by disabling canvas selection during pinch
            Object.values(pageStates).forEach(state => {
                if (state.fabricCanvas) {
                    state.fabricCanvas.selection = false;
                    state.fabricCanvas.forEachObject(obj => obj.selectable = false);
                }
            });
        }
        // A single finger touch is for panning/scrolling ONLY if a navigation tool is active.
        else if (e.touches.length === 1 && (currentTool === 'select' || currentTool === 'pan')) {
            e.preventDefault();
            touchState.singleFingerPan = true;
            touchState.lastPanPoint = { x: e.touches[0].clientX, y: e.touches[0].clientY };
        }
        // If an annotation tool is active, the single touch is passed through to Fabric.js for drawing.
    }, { passive: false });

    pdfScrollContainer.addEventListener('touchmove', e => {
        // Always cancel the previous animation frame to work with the latest touch data.
        if (lastAnimationFrame) {
            cancelAnimationFrame(lastAnimationFrame);
        }

        lastAnimationFrame = requestAnimationFrame(() => {
            // Handle two-finger pan and zoom gestures.
            if (touchState.pinching && e.touches.length === 2) {
                e.preventDefault();

                const newPanPoint = { x: (e.touches[0].clientX + e.touches[1].clientX) / 2, y: (e.touches[0].clientY + e.touches[1].clientY) / 2 };
                // Pinch-to-zoom is disabled. This gesture now only pans.

                // --- Panning Logic ---
                const dx = newPanPoint.x - touchState.lastPanPoint.x;
                const dy = newPanPoint.y - touchState.lastPanPoint.y;
                pdfScrollContainer.scrollLeft -= dx;
                pdfScrollContainer.scrollTop -= dy;

                touchState.lastPanPoint = newPanPoint;
            }
            // Handle single-finger panning for navigation.
            else if (touchState.singleFingerPan && e.touches.length === 1) {
                 e.preventDefault();
                const dx = e.touches[0].clientX - touchState.lastPanPoint.x;
                const dy = e.touches[0].clientY - touchState.lastPanPoint.y;
                pdfScrollContainer.scrollLeft -= dx;
                pdfScrollContainer.scrollTop -= dy;
                touchState.lastPanPoint = { x: e.touches[0].clientX, y: e.touches[0].clientY };
            }
        });
    }, { passive: false });

    pdfScrollContainer.addEventListener('touchend', e => {
        // If we were doing a two-finger pan, restore the selection state based on the current tool.
        // This prevents accidental selections after a pan/zoom gesture.
        if (touchState.panning) {
            setTimeout(() => {
                setActiveTool(currentTool);
            }, 10);
        }

        // Reset all touch states when the interaction ends.
        touchState.pinching = false;
        touchState.panning = false;
        touchState.singleFingerPan = false;

        if (lastAnimationFrame) {
            cancelAnimationFrame(lastAnimationFrame);
            lastAnimationFrame = null;
        }
    });

    initPDFViewer();
});
