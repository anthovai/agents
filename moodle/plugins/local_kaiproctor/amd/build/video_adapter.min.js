// Gives AttentionMonitor something video-shaped to police, whatever is
// actually playing on the page.
//
// The monitor only ever touches pause(), play(), paused, currentTime and
// parentElement, so anything exposing those works. Two sources are handled:
//
//   * a plain <video> element — used directly, nothing to adapt. This covers
//     mod_kaivideo's file, upload and HLS backends, and any core activity
//     that embeds a video.
//   * window.KAIVIDEO — mod_kaivideo's published player object, which is how
//     its YouTube and Vimeo lessons stay watchable: both play inside an
//     iframe where no <video> is reachable, so the module publishes a
//     play/pause/currentTime/isPaused contract instead.
define([], function() {

    /**
     * Wraps the published player object.
     *
     * isPaused() is treated as possibly asynchronous — the iframe backends
     * answer over postMessage — so paused state is tracked locally and only
     * reconciled with the player, never read from it synchronously.
     *
     * @param {Object} player window.KAIVIDEO
     * @param {HTMLElement} host element the overlay is drawn over
     */
    var PublishedPlayerAdapter = function(player, host) {
        var self = this;
        this.player = player;
        this.parentElement = host;
        this._paused = true;
        this._currentTime = 0;

        var reconcile = function() {
            try {
                Promise.resolve(player.isPaused()).then(function(paused) {
                    if (typeof paused === 'boolean') {
                        self._paused = paused;
                    }
                    return paused;
                }).catch(function() {
                    return null;
                });
                Promise.resolve(player.currentTime()).then(function(time) {
                    if (typeof time === 'number' && !isNaN(time)) {
                        self._currentTime = time;
                    }
                    return time;
                }).catch(function() {
                    return null;
                });
            } catch (error) {
                // A player that has gone away leaves the last known state.
                return;
            }
        };
        reconcile();
        this._poll = setInterval(reconcile, 1000);
    };

    Object.defineProperty(PublishedPlayerAdapter.prototype, 'paused', {
        get: function() {
            return this._paused;
        }
    });

    Object.defineProperty(PublishedPlayerAdapter.prototype, 'currentTime', {
        get: function() {
            return this._currentTime;
        }
    });

    PublishedPlayerAdapter.prototype.pause = function() {
        this._paused = true;
        try {
            this.player.pause();
        } catch (error) {
            // Nothing else to do: the monitor has already logged the reason
            // it wanted playback stopped.
            return;
        }
    };

    PublishedPlayerAdapter.prototype.play = function() {
        this._paused = false;
        try {
            this.player.play();
        } catch (error) {
            return;
        }
    };

    PublishedPlayerAdapter.prototype.destroy = function() {
        clearInterval(this._poll);
    };

    return {
        /**
         * Find something playable on the current page.
         *
         * @param {Number} [timeoutMs] how long to wait for a player to appear
         * @return {Promise<Object|null>} adapter, or null if there is nothing
         */
        forPage: function(timeoutMs) {
            var deadline = Date.now() + (timeoutMs === undefined ? 15000 : timeoutMs);

            var attempt = function(resolve) {
                var video = document.querySelector('video');
                if (video) {
                    resolve(video);
                    return;
                }

                if (window.KAIVIDEO) {
                    // The backend publishes the element it lives in as .host —
                    // the iframe for YouTube and Vimeo — which is where the
                    // monitor's overlay belongs. The old version reached for a
                    // variable declared in a later branch, which hoisting made
                    // undefined rather than an error, so the overlay quietly
                    // anchored to nothing.
                    resolve(new PublishedPlayerAdapter(window.KAIVIDEO,
                        window.KAIVIDEO.host || document.body));
                    return;
                }

                if (Date.now() > deadline) {
                    resolve(null);
                    return;
                }
                setTimeout(function() {
                    attempt(resolve);
                }, 400);
            };

            return new Promise(attempt);
        },

        PublishedPlayerAdapter: PublishedPlayerAdapter
    };
});
