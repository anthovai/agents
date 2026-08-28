// One playhead interface over four very different players.
//
// The rest of the library asks four things of whatever is playing: play,
// pause, where are we, and are we paused. A <video> element answers all four
// instantly. YouTube and Vimeo answer them across a postMessage boundary, so
// their position is polled or pushed and their paused state arrives as an
// event.
//
// So all of them are wrapped to look like the element, and the difference is
// confined to this file.
//
// HLS is the odd one out and the cheapest: it plays in a real <video>, with
// the stream attached by hls.js — loaded only when a stream is actually
// asked for, so the common case costs no download. Nothing downstream can
// tell an HLS video from a plain file, which is the whole point.
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else {
        root.InteractiveVideo = root.InteractiveVideo || {};
        root.InteractiveVideo.backends = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {

    // Third-party players, loaded from their own CDNs because that is the
    // only place they are served from and a self-hosted copy of the YouTube
    // API stops working the week they change it. Each is fetched only when a
    // video of that kind is actually played.
    //
    // Override them if your deployment must not reach these hosts — a video
    // of that provider then simply fails to load, with a named error, which
    // is the honest outcome.
    var HLS_JS = 'https://cdn.jsdelivr.net/npm/hls.js@1/dist/hls.min.js';
    var VIMEO_SDK = 'https://player.vimeo.com/api/player.js';
    var YOUTUBE_API = 'https://www.youtube.com/iframe_api';

    /**
     * Load a script once, and hand back the global it defines.
     *
     * Requests for the same URL share one promise: two Vimeo videos on a page
     * must not fetch the SDK twice, and the second must not start before the
     * first has finished defining its global.
     *
     * @param {String} url
     * @param {String} global the window property it sets
     * @return {Promise<*>}
     */
    var pending = {};
    var loadScript = function(url, global) {
        if (window[global]) {
            return Promise.resolve(window[global]);
        }
        if (pending[url]) {
            return pending[url];
        }
        pending[url] = new Promise(function(resolve, reject) {
            var script = document.createElement('script');
            script.src = url;
            script.async = true;
            script.onload = function() {
                if (window[global]) {
                    resolve(window[global]);
                } else {
                    reject(new Error('script_loaded_but_empty'));
                }
            };
            script.onerror = function() {
                delete pending[url];
                reject(new Error('script_unreachable'));
            };
            document.head.appendChild(script);
        });
        return pending[url];
    };

    /** Poll interval for YouTube's playhead. Four times a second is what a
     *  <video> element's own timeupdate manages, so the question-due check
     *  behaves the same either way. */
    var POLL_MS = 250;

    /** How long to wait for Vimeo's player before calling it a failure. */
    var VIMEO_READY_MS = 15000;

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

    /**
     * Vimeo, through its Player SDK.
     *
     * Nearly everything it answers is a Promise, which is why the playhead is
     * cached from its own timeupdate event rather than asked for: the
     * due-question check runs on every tick and has to answer synchronously.
     *
     * Its controls are not hidden here. They can be asked to go away, but
     * whether the request is honoured depends on the account the video sits in,
     * and a guarantee that holds only on some customers' accounts is not one.
     * A transparent sheet over the iframe puts them out of reach instead — see
     * the template.
     *
     * @param {Object} player Vimeo.Player
     * @param {Element} host the iframe it created
     */
    var VimeoBackend = function(player, host) {
        var self = this;
        this.player = player;
        this.host = host;
        this._paused = true;
        this._time = 0;
        this._tick = [];
        this._ended = [];
        this._playAttempt = [];
        this._duration = 0;

        player.on('timeupdate', function(data) {
            self._time = data.seconds || 0;
            self._duration = data.duration || self._duration;
            self._tick.forEach(function(callback) {
                callback();
            });
        });
        // Seeking fires no timeupdate until playback resumes, so without this
        // a learner could drag ahead while paused and the question due at the
        // new position would not come up until they pressed play.
        player.on('seeked', function(data) {
            self._time = data.seconds || 0;
            self._tick.forEach(function(callback) {
                callback();
            });
        });
        player.on('play', function() {
            self._paused = false;
            self._playAttempt.forEach(function(callback) {
                callback();
            });
        });
        player.on('pause', function() {
            self._paused = true;
        });
        player.on('ended', function() {
            self._paused = true;
            self._ended.forEach(function(callback) {
                callback();
            });
        });
    };

    VimeoBackend.prototype.play = function() {
        this._paused = false;
        return this.player.play().catch(function() {
            return null;
        });
    };
    VimeoBackend.prototype.pause = function() {
        this._paused = true;
        this.player.pause().catch(function() {
            return null;
        });
    };
    VimeoBackend.prototype.currentTime = function() {
        return this._time;
    };
    VimeoBackend.prototype.seek = function(seconds) {
        // Set locally as well as sent: the answer comes back asynchronously,
        // and a check() running in between would still read the old position.
        this._time = seconds;
        this.player.setCurrentTime(seconds).catch(function() {
            return null;
        });
    };
    VimeoBackend.prototype.duration = function() {
        return this._duration;
    };
    VimeoBackend.prototype.isPaused = function() {
        return this._paused;
    };
    VimeoBackend.prototype.onTick = function(callback) {
        this._tick.push(callback);
    };
    VimeoBackend.prototype.onEnded = function(callback) {
        this._ended.push(callback);
    };
    VimeoBackend.prototype.onPlayAttempt = function(callback) {
        this._playAttempt.push(callback);
    };

    /**
     * Put an HLS stream onto a <video> element.
     *
     * Safari plays a playlist natively; nothing else does. video.js is asked
     * for only when it is needed, so the common case costs no download.
     *
     * @param {HTMLVideoElement} element
     * @param {String} url the .m3u8
     * @return {Promise}
     */
    var attachStream = function(element, url) {
        var native = element.canPlayType('application/vnd.apple.mpegurl');
        if (native === 'probably' || native === 'maybe') {
            // Safari plays a playlist by itself. Nothing else does.
            element.src = url;
            return Promise.resolve(element);
        }

        return loadScript(HLS_JS, 'Hls').then(function(Hls) {
            if (!Hls.isSupported()) {
                throw new Error('hls_unsupported');
            }
            var hls = new Hls();
            hls.loadSource(url);
            hls.attachMedia(element);
            // Kept on the element so a caller that tears the player down can
            // free the buffers; without it a page that swaps videos leaks a
            // worker and its segments each time.
            element._hls = hls;
            return element;
        }).catch(function(error) {
            throw new Error(error && error.message === 'hls_unsupported'
                ? 'hls_unsupported' : 'hls_unavailable');
        });
    };

    /**
     * Load Vimeo's SDK once, and hand back its Player constructor.
     *
     * Through RequireJS rather than a <script> tag, which is what YouTube's
     * API gets. Vimeo ships a UMD bundle: dropped onto a page that has an AMD
     * loader it calls define() anonymously, and RequireJS rejects that with
     * "Mismatched anonymous define() module" because it never asked for it.
     * The page then had no player and an error nothing on it explained.
     *
     * Asking RequireJS to fetch it means the define() belongs to a request it
     * is tracking, which is the case the loader is built for. The alternative —
     * hiding define.amd while the script loads — mutates a global that every
     * other module on the page is using, to work around one file.
     *
     * @return {Promise<Function>} the Player constructor
     */
    var loadVimeo = function() {
        return loadScript(VIMEO_SDK, 'Vimeo').then(function(Vimeo) {
            if (!Vimeo || typeof Vimeo.Player !== 'function') {
                throw new Error('vimeo_api_unusable');
            }
            return Vimeo.Player;
        }, function() {
            throw new Error('vimeo_api_unreachable');
        });
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
            // YouTube announces itself through a global callback rather than
            // the script's load event, and any other code on the page may
            // have set one first — so the previous handler is kept and called.
            var previous = window.onYouTubeIframeAPIReady;
            window.onYouTubeIframeAPIReady = function() {
                if (typeof previous === 'function') {
                    previous();
                }
                resolve(window.YT);
            };
            var script = document.createElement('script');
            script.src = YOUTUBE_API;
            script.onerror = function() {
                reject(new Error('youtube_api_unreachable'));
            };
            document.head.appendChild(script);
        });
        return apiReady;
    };

    return {
        /**
         * Put a stream on a plain <video>, for pages that only need to show it.
         *
         * The editor's preview uses this: it is not taking the lesson, so it
         * keeps the browser's own controls and needs none of the rest.
         *
         * @param {String} selector
         * @param {String} url the .m3u8
         * @return {Promise}
         */
        attachStream: function(element, url) {
            return element ? attachStream(element, url) : Promise.resolve(null);
        },

        /**
         * @param {Object} config {provider, source}
         * @param {Object} slots {video, youtube, vimeo} — the elements each
         *        provider may claim. All three are passed rather than looked
         *        up, so this file needs no knowledge of how the page around
         *        it is put together.
         * @return {Promise<Object>} the backend
         */
        create: function(config, slots) {
            var published = function(backend) {
                // Published so that something else on the page — an attention
                // monitor, an analytics hook — can watch a YouTube or Vimeo
                // video without knowing anything about either provider. The
                // four methods above are the whole contract.
                window.InteractiveVideoBackend = backend;
                return backend;
            };

            if (config.provider === 'hls') {
                var element = slots.video;
                return attachStream(element, config.source).then(function() {
                    // A file backend over it, deliberately: once the stream is
                    // attached there is nothing left that is HLS-specific, and
                    // a separate wrapper would be a second copy of the same
                    // four methods waiting to drift.
                    return published(new FileBackend(element));
                });
            }

            if (config.provider === 'vimeo') {
                return loadVimeo().then(function(VimeoPlayer) {
                    // The unlisted hash travels with the id as "id:hash",
                    // because without it the player refuses to load — and an
                    // unlisted video is exactly what a paid course sits behind.
                    var parts = String(config.source).split(':');
                    var options = {id: parts[0], controls: false,
                        responsive: true, dnt: true};
                    if (parts[1]) {
                        options.h = parts[1];
                    }

                    var player = new VimeoPlayer(
                        slots.vimeo, options);

                    // Raced against a clock, because ready() does not always
                    // settle. A video whose privacy settings do not list this
                    // site is answered with a 401 inside the iframe, where the
                    // SDK never sees it: the promise stays pending and the
                    // learner is left looking at an empty box with nothing on
                    // the page to explain it. Not hypothetical — "which domains
                    // may embed this" is the setting customers get wrong.
                    return Promise.race([
                        player.ready(),
                        new Promise(function(resolve, reject) {
                            window.setTimeout(function() {
                                reject(new Error('vimeo_api_unusable'));
                            }, VIMEO_READY_MS);
                        })
                    ]).then(function() {
                        return published(new VimeoBackend(player, player.element));
                    });
                });
            }

            if (config.provider !== 'youtube') {
                return Promise.resolve(published(
                    new FileBackend(slots.video)));
            }

            return loadApi().then(function(YT) {
                return new Promise(function(resolve) {
                    var backend;
                    var player = new YT.Player(
                        slots.youtube, {
                            videoId: config.source,
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
}));
