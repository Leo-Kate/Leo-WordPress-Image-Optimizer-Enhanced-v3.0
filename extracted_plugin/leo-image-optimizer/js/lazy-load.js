/**
 * Manus Image Optimizer - Lazy Loading Script (Optimized)
 * No jQuery dependency - pure vanilla JavaScript for better performance
 */
(function() {
    'use strict';
    
    function initLazyLoading() {
        var threshold = (typeof manusLazyConfig !== 'undefined') ? manusLazyConfig.threshold : '200px';
        
        if ('IntersectionObserver' in window) {
            var imageObserver = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        var img = entry.target;
                        if (img.dataset.src) {
                            img.src = img.dataset.src;
                            img.removeAttribute('data-src');
                            img.classList.add('manus-lazy-loaded');
                            imageObserver.unobserve(img);
                        }
                    }
                });
            }, {
                rootMargin: threshold
            });
            
            // Observe all images with data-src
            var lazyImages = document.querySelectorAll('img[data-src]');
            lazyImages.forEach(function(img) {
                imageObserver.observe(img);
            });
        } else {
            // Fallback for browsers without IntersectionObserver
            var lazyImages = document.querySelectorAll('img[data-src]');
            lazyImages.forEach(function(img) {
                img.src = img.dataset.src;
                img.removeAttribute('data-src');
                img.classList.add('manus-lazy-loaded');
            });
        }
    }
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initLazyLoading);
    } else {
        initLazyLoading();
    }
})();
