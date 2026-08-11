// One playhead interface over two very different players.
//
// The rest of the module asks four things of whatever is playing: play, pause,
// where are we, and are we paused. A <video> element answers all four
// instantly. YouTube answers them across a postMessage boundary, so its
// position has to be polled and its paused state arrives as an event.
//
// So both are wrapped to look like the element, and the difference is confined
// to this file. The wrapper also publishes itself as window.KAIVIDEO, which is
// what lets the proctoring monitor watch a YouTube lesson without knowing
// anything about YouTube.
define([], function() {

    /** Poll interval for YouTube's playhead. Four times a second is what a
     *  <video> element's own timeupdate manages, so the question-due check
     *  behaves the same either way. */
    var POLL_MS = 250;

    /**
     * A plain <video>. Nothing to adapt; it already is the interface.
     *
     * @param {HTMLVideoElement} element
     */
    var FileBackend = function(element) {
        this.element = element;
        this.host = element;
    };

    FileBackend.prototype.play = function() {
        return Promise.resolve(this.element.play()).catch(function() {
            return null;
        });
    };
    FileBackend.prototype.pause = function() {
        this.element.pause();
    };
    FileBackend.prototype.currentTime = function() {
        return this.element.currentTime;
    };
    FileBackend.prototype.seek = function(seconds) {
        this.element.currentTime = seconds;
    };
    FileBackend.prototype.duration = function() {
        return this.element.duration || 0;
    };
    FileBackend.prototype.isPaused = function() {
        return this.element.paused;
    };
    FileBackend.prototype.onTick = function(callback) {
        this.element.addEventListener('timeupdate', callback);
        this.element.addEventListener('seeked', callback);
    };
    FileBackend.prototype.onEnded = function(callback) {
        this.element.addEventListener('ended', callback);
    };
    FileBackend.prototype.onPlayAttempt = function(callback) {
        this.element.addEventListener('play', callback);
    };

    /**
     * YouTube, through its own iframe API.
     *
     * Its native controls are switched off. Not for tidiness: with them on, a
     * learner can drag YouTube's seek bar and resume playing from inside the
     * iframe, and "the video will not continue past an unanswered question"
     * stops being something this module can promise. Our own controls sit
     * underneath instead.
     *
     * @param {Object} player YT.Player
     */
    var YouTubeBackend = function(player) {
        var self = this;
        this.player = player;
        this.host = player.getIframe();
        this._paused = true;
        this._time = 0;
        this._tick = [];
        this._ended = [];
        this._playAttempt = [];

        window.setInterval(function() {
            try {
                self._time = player.getCurrentTime() || 0;
            } catch (error) {
                return;
            }
            self._tick.forEach(function(callback) {
                callback();
            });
        }, POLL_MS);
    };

    YouTubeBackend.prototype.play = function() {
        this._paused = false;
        this.player.playVideo();
        return Promise.resolve();
    };
    YouTubeBackend.prototype.pause = function() {
        this._paused = true;
        this.player.pauseVideo();
    };
    YouTubeBackend.prototype.currentTime = function() {
        return this._time;
    };
    YouTubeBackend.prototype.seek = function(seconds) {
        this.player.seekTo(seconds, true);
    };
    YouTubeBackend.prototype.duration = function() {
        try {
            return this.player.getDuration() || 0;
        } catch (error) {
            return 0;
        }
    };
    YouTubeBackend.prototype.isPaused = function() {
        // Tracked locally rather than read from the player: getPlayerState is
        // available synchronously but lags the instruction, so asking it right
        // after pause() can still answer "playing".
        return this._paused;
    };
    YouTubeBackend.prototype.onTick = function(callback) {
        this._tick.push(callback);
    };
    YouTubeBackend.prototype.onEnded = function(callback) {
        this._ended.push(callback);
    };
    YouTubeBackend.prototype.onPlayAttempt = function(callback) {
        this._playAttempt.push(callback);
    };
    YouTubeBackend.prototype._state = function(state) {
        var self = this;
        if (state === 0) {
            this._paused = true;
            this._ended.forEach(function(callback) {
                callback();
            });
        } else if (state === 1) {
            this._paused = false;
            this._playAttempt.forEach(function(callback) {
                callback();
            });
        } else if (state === 2) {
            this._paused = true;
        }
        return self;
    };

    /** Load YouTube's API once, however many players are on the page. */
    var apiReady = null;
    var loadApi = function() {
        if (apiReady) {
            return apiReady;
        }
        apiReady = new Promise(function(resolve, reject) {
            if (window.YT && window.YT.Player) {
                resolve(window.YT);
                return;
            }
            var previous = window.onYouTubeIframeAPIReady;
            window.onYouTubeIframeAPIReady = function() {
                if (typeof previous === 'function') {
                    previous();
                }
                resolve(window.YT);
            };
            var script = document.createElement('script');
            script.src = 'https://www.youtube.com/iframe_api';
            script.onerror = function() {
                reject(new Error('youtube_api_unreachable'));
            };
            document.head.appendChild(script);
        });
        return apiReady;
    };

    return {
        /**
         * @param {Object} config from the page
         * @param {Element} root the activity's container
         * @return {Promise<Object>} the backend
         */
        create: function(config, root) {
            var published = function(backend) {
                // The proctoring monitor looks for this, exactly as it looks
                // for mod_interactivevideo's IVPLAYER. Publishing it here means
                // a YouTube lesson is watchable without the monitor knowing
                // what YouTube is.
                window.KAIVIDEO = backend;
                return backend;
            };

            if (config.provider !== 'youtube') {
                return Promise.resolve(published(
                    new FileBackend(root.querySelector('[data-region="video"]'))));
            }

            return loadApi().then(function(YT) {
                return new Promise(function(resolve) {
                    var backend;
                    var player = new YT.Player(
                        root.querySelector('[data-region="youtube"]'), {
                            videoId: config.videoid,
                            playerVars: {
                                controls: 0,
                                disablekb: 1,
                                modestbranding: 1,
                                rel: 0,
                                playsinline: 1
                            },
                            events: {
                                onReady: function() {
                                    backend = new YouTubeBackend(player);
                                    resolve(published(backend));
                                },
                                onStateChange: function(event) {
                                    if (backend) {
                                        backend._state(event.data);
                                    }
                                }
                            }
                        });
                });
            });
        }
    };
});
