export function debounce(func, delay) {
    let timeout;
    return function(...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), delay);
    };
}

// Note: I've modified this function to accept the container as an argument
// to make it a pure, reusable utility function without external dependencies.
export function isElementInViewport(el, container) {
    if (!el || !container || typeof el.getBoundingClientRect !== 'function') return false;
    const rect = el.getBoundingClientRect();
    const containerRect = container.getBoundingClientRect();

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
