/**
 * Real User Monitoring (RUM) & Core Web Vitals
 *
 * Tracks actual user performance metrics including:
 * - Core Web Vitals (LCP, FID, CLS)
 * - Page load timing
 * - Resource loading
 * - JavaScript errors
 *
 * Data is sent to /actions/rum.php for analysis
 */

(function() {
    'use strict';

    const RUM = {
        metrics: {},
        errors: [],
        resources: [],
        endpoint: '/actions/rum',
        sampleRate: 1.0, // 100% of users (reduce for high traffic)
        debug: false,

        /**
         * Initialize RUM
         */
        init: function() {
            // Check if we should sample this user
            if (Math.random() > this.sampleRate) {
                return;
            }

            // Collect page info
            this.metrics.url = window.location.pathname;
            this.metrics.referrer = document.referrer;
            this.metrics.userAgent = navigator.userAgent;
            this.metrics.connection = this.getConnectionInfo();
            this.metrics.timestamp = Date.now();

            // Core Web Vitals
            this.observeLCP();
            this.observeFID();
            this.observeCLS();

            // Navigation timing
            this.collectNavigationTiming();

            // Resource timing
            this.collectResourceTiming();

            // Error tracking
            this.setupErrorTracking();

            // Send data when page unloads
            this.setupBeaconSend();

            if (this.debug) {
                console.log('[RUM] Initialized');
            }
        },

        /**
         * Observe Largest Contentful Paint (LCP)
         * Target: < 2.5s
         */
        observeLCP: function() {
            if (!('PerformanceObserver' in window)) return;

            try {
                const observer = new PerformanceObserver((list) => {
                    const entries = list.getEntries();
                    const lastEntry = entries[entries.length - 1];
                    this.metrics.lcp = Math.round(lastEntry.startTime);

                    if (this.debug) {
                        console.log('[RUM] LCP:', this.metrics.lcp + 'ms');
                    }
                });

                observer.observe({ type: 'largest-contentful-paint', buffered: true });
            } catch (e) {
                // LCP not supported
            }
        },

        /**
         * Observe First Input Delay (FID)
         * Target: < 100ms
         */
        observeFID: function() {
            if (!('PerformanceObserver' in window)) return;

            try {
                const observer = new PerformanceObserver((list) => {
                    const entries = list.getEntries();
                    entries.forEach((entry) => {
                        if (!this.metrics.fid) {
                            this.metrics.fid = Math.round(entry.processingStart - entry.startTime);

                            if (this.debug) {
                                console.log('[RUM] FID:', this.metrics.fid + 'ms');
                            }
                        }
                    });
                });

                observer.observe({ type: 'first-input', buffered: true });
            } catch (e) {
                // FID not supported
            }
        },

        /**
         * Observe Cumulative Layout Shift (CLS)
         * Target: < 0.1
         */
        observeCLS: function() {
            if (!('PerformanceObserver' in window)) return;

            let clsValue = 0;
            let sessionValue = 0;
            let sessionEntries = [];

            try {
                const observer = new PerformanceObserver((list) => {
                    for (const entry of list.getEntries()) {
                        if (!entry.hadRecentInput) {
                            const firstSessionEntry = sessionEntries[0];
                            const lastSessionEntry = sessionEntries[sessionEntries.length - 1];

                            if (sessionValue &&
                                entry.startTime - lastSessionEntry.startTime < 1000 &&
                                entry.startTime - firstSessionEntry.startTime < 5000) {
                                sessionValue += entry.value;
                                sessionEntries.push(entry);
                            } else {
                                sessionValue = entry.value;
                                sessionEntries = [entry];
                            }

                            if (sessionValue > clsValue) {
                                clsValue = sessionValue;
                                this.metrics.cls = Math.round(clsValue * 1000) / 1000;

                                if (this.debug) {
                                    console.log('[RUM] CLS:', this.metrics.cls);
                                }
                            }
                        }
                    }
                });

                observer.observe({ type: 'layout-shift', buffered: true });
            } catch (e) {
                // CLS not supported
            }
        },

        /**
         * Collect navigation timing metrics
         */
        collectNavigationTiming: function() {
            if (!window.performance || !performance.timing) return;

            window.addEventListener('load', () => {
                setTimeout(() => {
                    const timing = performance.timing;
                    const navStart = timing.navigationStart;

                    this.metrics.timing = {
                        // DNS lookup
                        dns: timing.domainLookupEnd - timing.domainLookupStart,
                        // TCP connection
                        tcp: timing.connectEnd - timing.connectStart,
                        // TLS handshake (if HTTPS)
                        tls: timing.secureConnectionStart > 0 ?
                            timing.connectEnd - timing.secureConnectionStart : 0,
                        // Time to first byte
                        ttfb: timing.responseStart - navStart,
                        // DOM content loaded
                        domContentLoaded: timing.domContentLoadedEventEnd - navStart,
                        // Page load complete
                        load: timing.loadEventEnd - navStart,
                        // DOM interactive
                        domInteractive: timing.domInteractive - navStart,
                        // Download time
                        download: timing.responseEnd - timing.responseStart,
                    };

                    // First Contentful Paint (FCP)
                    const paintEntries = performance.getEntriesByType('paint');
                    paintEntries.forEach((entry) => {
                        if (entry.name === 'first-contentful-paint') {
                            this.metrics.fcp = Math.round(entry.startTime);
                        }
                        if (entry.name === 'first-paint') {
                            this.metrics.fp = Math.round(entry.startTime);
                        }
                    });

                    if (this.debug) {
                        console.log('[RUM] Timing:', this.metrics.timing);
                    }
                }, 100);
            });
        },

        /**
         * Collect resource timing
         */
        collectResourceTiming: function() {
            if (!window.performance) return;

            window.addEventListener('load', () => {
                setTimeout(() => {
                    const resources = performance.getEntriesByType('resource');

                    // Group by type
                    const grouped = {
                        script: { count: 0, size: 0, time: 0 },
                        css: { count: 0, size: 0, time: 0 },
                        img: { count: 0, size: 0, time: 0 },
                        font: { count: 0, size: 0, time: 0 },
                        other: { count: 0, size: 0, time: 0 },
                    };

                    resources.forEach((res) => {
                        const type = this.getResourceType(res.initiatorType, res.name);
                        if (grouped[type]) {
                            grouped[type].count++;
                            grouped[type].size += res.transferSize || 0;
                            grouped[type].time += res.duration;
                        }
                    });

                    this.metrics.resources = grouped;
                    this.metrics.resourceCount = resources.length;

                    // Find slow resources
                    this.resources = resources
                        .filter(r => r.duration > 500)
                        .map(r => ({
                            name: r.name.split('/').pop().split('?')[0],
                            type: r.initiatorType,
                            duration: Math.round(r.duration),
                            size: r.transferSize,
                        }))
                        .slice(0, 10);

                    if (this.debug) {
                        console.log('[RUM] Resources:', this.metrics.resources);
                    }
                }, 200);
            });
        },

        /**
         * Get resource type from initiator and URL
         */
        getResourceType: function(initiator, url) {
            if (initiator === 'script' || url.match(/\.js$/)) return 'script';
            if (initiator === 'css' || initiator === 'link' || url.match(/\.css$/)) return 'css';
            if (initiator === 'img' || url.match(/\.(jpg|jpeg|png|gif|svg|webp)$/i)) return 'img';
            if (url.match(/\.(woff2?|ttf|eot)$/)) return 'font';
            return 'other';
        },

        /**
         * Setup error tracking
         */
        setupErrorTracking: function() {
            window.addEventListener('error', (event) => {
                this.errors.push({
                    message: event.message,
                    source: event.filename,
                    line: event.lineno,
                    column: event.colno,
                    timestamp: Date.now(),
                });
            });

            window.addEventListener('unhandledrejection', (event) => {
                this.errors.push({
                    message: 'Unhandled Promise rejection: ' + (event.reason?.message || event.reason),
                    source: 'promise',
                    timestamp: Date.now(),
                });
            });
        },

        /**
         * Get connection info
         */
        getConnectionInfo: function() {
            const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
            if (!connection) return null;

            return {
                effectiveType: connection.effectiveType,
                downlink: connection.downlink,
                rtt: connection.rtt,
                saveData: connection.saveData,
            };
        },

        /**
         * Setup beacon send on page unload
         */
        setupBeaconSend: function() {
            const send = () => {
                const data = {
                    metrics: this.metrics,
                    errors: this.errors.slice(0, 5),
                    slowResources: this.resources,
                };

                // Use sendBeacon for reliable delivery
                if (navigator.sendBeacon) {
                    navigator.sendBeacon(this.endpoint, JSON.stringify(data));
                } else {
                    // Fallback to sync XHR
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', this.endpoint, false);
                    xhr.setRequestHeader('Content-Type', 'application/json');
                    xhr.send(JSON.stringify(data));
                }

                if (this.debug) {
                    console.log('[RUM] Data sent:', data);
                }
            };

            // Send on page hide (more reliable than unload)
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'hidden') {
                    send();
                }
            });

            // Fallback for older browsers
            window.addEventListener('pagehide', send);
        },

        /**
         * Get Web Vitals rating
         */
        getVitalsRating: function() {
            const ratings = {
                lcp: this.metrics.lcp <= 2500 ? 'good' : (this.metrics.lcp <= 4000 ? 'needs-improvement' : 'poor'),
                fid: this.metrics.fid <= 100 ? 'good' : (this.metrics.fid <= 300 ? 'needs-improvement' : 'poor'),
                cls: this.metrics.cls <= 0.1 ? 'good' : (this.metrics.cls <= 0.25 ? 'needs-improvement' : 'poor'),
            };

            return ratings;
        },
    };

    // Auto-initialize
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => RUM.init());
    } else {
        RUM.init();
    }

    // Expose for debugging
    window.MeshSiloRUM = RUM;
})();
