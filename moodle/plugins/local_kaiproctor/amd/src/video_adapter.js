// Gives AttentionMonitor something video-shaped to police, whatever is
// actually playing on the page.
//
// The monitor only ever touches pause(), play(), paused, currentTime and
// parentElement, so anything exposing those works. Two sources are handled:
//
//   * a plain <video> element — used directly, nothing to adapt
//   * mod_interactivevideo, which publishes window.IVPLAYER with a common
//     play/pause/getCurrentTime/isPaused contract across all 22 of its
//     backends (YouTube, Vimeo, PeerTube, HLS, HTML5, ...)
//
// The second case is why this exists: reaching into that plugin's internals
// would break on its next release, but its published player object is the
// interface it offers on purpose.
define([], function() {

    /**
     * Wraps mod_interactivevideo's player.
     *
     * isPaused() is treated as possibly asynchronous — some of its backends
     * answer over postMessage — so paused state is tracked locally and only
     * reconciled with the player, never read from it synchronously.
     *
     * @param {Object} player window.IVPLAYER
     * @param {HTMLElement} host element the overlay is drawn over
     */
    var InteractivePlayerAdapter = function(player, host) {
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
                Promise.resolve(player.getCurrentTime()).then(function(time) {
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

    Object.defineProperty(InteractivePlayerAdapter.prototype, 'paused', {
        get: function() {
            return this._paused;
        }
    });

    Object.defineProperty(InteractivePlayerAdapter.prototype, 'currentTime', {
        get: function() {
            return this._currentTime;
        }
    });

    InteractivePlayerAdapter.prototype.pause = function() {
        this._paused = true;
        try {
            this.player.pause();
        } catch (error) {
            // Nothing else to do: the monitor has already logged the reason
            // it wanted playback stopped.
            return;
        }
    };

    InteractivePlayerAdapter.prototype.play = function() {
        this._paused = false;
        try {
            this.player.play();
        } catch (error) {
            return;
        }
    };

    InteractivePlayerAdapter.prototype.destroy = function() {
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

                if (window.IVPLAYER) {
                    var host = document.querySelector('#interactivevideo-container, #video-wrapper')
                        || document.body;
                    resolve(new InteractivePlayerAdapter(window.IVPLAYER, host));
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

        InteractivePlayerAdapter: InteractivePlayerAdapter
    };
});
