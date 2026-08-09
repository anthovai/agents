// AttentionMonitor — enforces the training policy around a <video> element.
//
// Ported from face-re/app/static/attention-monitor.js. The six signals and
// their consequences are unchanged:
//
//  1. Tab/window   switching tab, minimising, leaving fullscreen
//                  -> pause the video and raise an OS notification
//                  -> strict mode ends the session
//  2. Presence     every N minutes, is anyone in front of the camera
//  3. Identity     every M minutes, is it still the same person
//  4. Click-confirm every N minutes the learner must confirm they are there
//  5. Mouse idle   no input for N minutes -> pause
//  6. Random clip  short camera clips at unpredictable times, kept as evidence
//
// What changed for Moodle: every signal goes to the plugin's web services
// instead of a bare API, and user-facing text comes from language packs.
define([
    'local_kaiproctor/api',
    'core/str',
    'core/notification'
], function(Api, Str, Notification) {

    var ACTIVITY_EVENTS = ['mousemove', 'mousedown', 'keydown', 'touchstart', 'wheel'];

    /**
     * @param {Object} opts
     * @param {HTMLVideoElement} opts.video the lesson video to police
     * @param {Number} opts.contextid where events and evidence are recorded
     * @param {Function} opts.getSnapshot returns Promise<Blob> of a camera frame
     * @param {Function} [opts.getStream] returns the MediaStream, for clips
     * @param {Function} [opts.onTerminate] called when the session is cut short
     */
    var AttentionMonitor = function(opts) {
        this.video = opts.video;
        this.contextid = opts.contextid;
        this.attemptid = opts.attemptid || 0;
        this.getSnapshot = opts.getSnapshot || null;
        this.getStream = opts.getStream || null;
        this.onTerminate = opts.onTerminate || function() {};

        var minutes = function(value, fallback) {
            return (value === undefined ? fallback : value) * 60000;
        };
        this.clickConfirmMs = minutes(opts.clickConfirmMinutes, 5);
        this.clickConfirmGraceMs = (opts.clickConfirmGraceSec === undefined ? 30 : opts.clickConfirmGraceSec) * 1000;
        this.mouseIdleMs = minutes(opts.mouseIdleMinutes, 3);
        this.presenceMs = minutes(opts.presenceMinutes, 2);
        this.verifyMs = minutes(opts.verifyMinutes, 10);

        this.strictLockdown = !!opts.strictLockdown;
        this.blurAllowance = opts.blurAllowance === undefined ? 0 : opts.blurAllowance;
        this.desktopNotification = opts.desktopNotification !== false;
        this.storeSnapshots = !!opts.storeSnapshots;
        this.identityEnabled = opts.identityEnabled !== false;

        this.clipsPerHour = opts.randomClipsPerHour === undefined ? 0 : opts.randomClipsPerHour;
        this.clipMs = (opts.clipSeconds === undefined ? 8 : opts.clipSeconds) * 1000;

        this._lastActivity = Date.now();
        this._lastConfirm = Date.now();
        this._lastPresence = Date.now();
        this._lastVerify = Date.now();
        this._confirmPending = false;
        this._confirmDeadline = 0;
        this._blurCount = 0;
        this._running = false;
        this._terminated = false;
        this._recording = false;
        this._overlay = null;
        this._nextClipAt = 0;
    };

    /* ---------- lifecycle ---------- */

    AttentionMonitor.prototype.start = function() {
        if (this._running) {
            return;
        }
        var self = this;
        this._running = true;
        this._terminated = false;
        var now = Date.now();
        this._lastActivity = now;
        this._lastConfirm = now;
        this._lastPresence = now;
        this._lastVerify = now;
        this._blurCount = 0;
        this._scheduleNextClip(now);

        if (this.desktopNotification) {
            this.requestNotificationPermission();
        }

        this._onActivity = function() {
            self._lastActivity = Date.now();
        };
        ACTIVITY_EVENTS.forEach(function(name) {
            document.addEventListener(name, self._onActivity, {passive: true});
        });

        this._onVisibility = function() {
            if (document.hidden) {
                self._onFocusLoss('tab_hidden');
            }
        };
        this._onBlur = function() {
            self._onFocusLoss('window_blur');
        };
        document.addEventListener('visibilitychange', this._onVisibility);
        window.addEventListener('blur', this._onBlur);

        this._timer = setInterval(function() {
            self._tick();
        }, 1000);

        this._log('monitor_started', {
            strict: this.strictLockdown,
            verify_minutes: this.verifyMs / 60000,
            presence_minutes: this.presenceMs / 60000,
            clips_per_hour: this.clipsPerHour
        });
    };

    AttentionMonitor.prototype.stop = function() {
        if (!this._running) {
            return;
        }
        var self = this;
        this._running = false;
        clearInterval(this._timer);
        ACTIVITY_EVENTS.forEach(function(name) {
            document.removeEventListener(name, self._onActivity);
        });
        document.removeEventListener('visibilitychange', this._onVisibility);
        window.removeEventListener('blur', this._onBlur);
        this._stopRecording();
        this._removeOverlay();
        this._log('monitor_stopped', {});
    };

    /** Lets the lockdown module fold its violations into the same log. */
    AttentionMonitor.prototype.reportViolation = function(type, detail) {
        if (type === 'fullscreen_exit') {
            this._onFocusLoss('fullscreen_exit');
            return;
        }
        this._log(type, detail || {});
        if (this.strictLockdown && type === 'devtools_suspected') {
            this._terminate('devtools_suspected');
        }
    };

    /* ---------- focus loss ---------- */

    AttentionMonitor.prototype._onFocusLoss = function(type) {
        var self = this;
        if (!this._running || this._terminated) {
            return;
        }
        this.video.pause();
        this._blurCount += 1;
        this._log(type, {occurrence: this._blurCount});
        this._captureEvidence('violation_' + type);

        Str.get_string('violation:' + type, 'local_kaiproctor').then(function(message) {
            self._notify(message);
            if (self.strictLockdown && self._blurCount > self.blurAllowance) {
                self._terminate(type);
                return;
            }
            self._interruptOverlay(type, message);
        }).catch(Notification.exception);
    };

    AttentionMonitor.prototype._terminate = function(type) {
        var self = this;
        if (this._terminated) {
            return;
        }
        this._terminated = true;
        this.video.pause();
        this._log('session_terminated', {cause: type});

        // Stop watching before the final overlay: stop() clears any overlay
        // still on screen, and the terminal one must survive it.
        this.stop();

        Str.get_strings([
            {key: 'terminated:title', component: 'local_kaiproctor'},
            {key: 'violation:' + type, component: 'local_kaiproctor'},
            {key: 'terminated:close', component: 'local_kaiproctor'}
        ]).then(function(strings) {
            self._notify(strings[1]);
            self._showOverlay(strings[0], strings[1], strings[2], function() {
                self.onTerminate({type: type, closed: true});
            }, true);
        }).catch(Notification.exception);

        // Tell the caller immediately so the server-side end is recorded
        // without waiting for the learner to click anything.
        this.onTerminate({type: type, closed: false});
    };

    /* ---------- OS notification ---------- */

    AttentionMonitor.prototype.requestNotificationPermission = function() {
        if (!('Notification' in window)) {
            return Promise.resolve(false);
        }
        if (window.Notification.permission === 'granted') {
            return Promise.resolve(true);
        }
        if (window.Notification.permission === 'denied') {
            return Promise.resolve(false);
        }
        return window.Notification.requestPermission().then(function(result) {
            return result === 'granted';
        }).catch(function() {
            return false;
        });
    };

    AttentionMonitor.prototype._notify = function(body) {
        if (!this.desktopNotification || !('Notification' in window)) {
            return;
        }
        if (window.Notification.permission !== 'granted') {
            return;
        }
        Str.get_string('notification:title', 'local_kaiproctor').then(function(title) {
            var notification = new window.Notification(title, {
                body: body,
                requireInteraction: true,
                tag: 'kaiproctor'
            });
            notification.onclick = function() {
                window.focus();
                notification.close();
            };
            return notification;
        }).catch(function() {
            // The in-page overlay already carries the message.
            return null;
        });
    };

    /* ---------- ticks ---------- */

    AttentionMonitor.prototype._tick = function() {
        if (this._terminated) {
            return;
        }
        var now = Date.now();

        // Random clips keep running even while the video is paused — who is
        // sitting there matters most when the lesson is not playing.
        if (this.clipsPerHour > 0 && now >= this._nextClipAt) {
            this._recordRandomClip(now);
        }

        if (this.video.paused && !this._confirmPending) {
            return;
        }

        if (this.clickConfirmMs > 0 && !this._confirmPending &&
                now - this._lastConfirm >= this.clickConfirmMs) {
            this._askConfirm(now);
        }

        if (this._confirmPending && now > this._confirmDeadline) {
            this._confirmPending = false;
            this._lastConfirm = now;
            this._interrupt('click_confirm_timeout');
        }

        if (this.mouseIdleMs > 0 && now - this._lastActivity >= this.mouseIdleMs) {
            this._lastActivity = now;
            this._interrupt('mouse_idle');
        }

        if (this.presenceMs > 0 && this.getSnapshot &&
                now - this._lastPresence >= this.presenceMs) {
            this._lastPresence = now;
            this._checkPresence();
        }

        if (this.identityEnabled && this.verifyMs > 0 && this.getSnapshot &&
                now - this._lastVerify >= this.verifyMs) {
            this._lastVerify = now;
            this._checkIdentity();
        }
    };

    AttentionMonitor.prototype._askConfirm = function(now) {
        var self = this;
        this._confirmPending = true;
        this._confirmDeadline = now + this.clickConfirmGraceMs;

        Str.get_strings([
            {key: 'confirm:title', component: 'local_kaiproctor'},
            {key: 'confirm:body', component: 'local_kaiproctor'},
            {key: 'confirm:button', component: 'local_kaiproctor'}
        ]).then(function(strings) {
            self._showOverlay(strings[0], strings[1], strings[2], function() {
                self._confirmPending = false;
                self._lastConfirm = Date.now();
                self._removeOverlay();
                self._log('click_confirm_ok', {});
                if (self.video.paused) {
                    self.video.play();
                }
            }, false);
            return strings;
        }).catch(Notification.exception);
    };

    /* ---------- face checks ---------- */

    AttentionMonitor.prototype._checkPresence = function() {
        var self = this;
        this.getSnapshot().then(function(blob) {
            return Api.analyze(blob);
        }).then(function(response) {
            if (response.ok && response.present === false) {
                self._interrupt('face_absent', {reason: response.reason});
            } else if (response.warning === 'multiple_faces') {
                self._interrupt('multiple_faces');
            } else {
                self._log('presence_ok', {});
            }
            return response;
        }).catch(function(error) {
            self._log('presence_error', {message: String(error)});
        });
    };

    AttentionMonitor.prototype._checkIdentity = function() {
        var self = this;
        this.getSnapshot().then(function(blob) {
            return Api.verify(self.contextid, blob, self.attemptid, self.storeSnapshots);
        }).then(function(response) {
            if (!response.ok) {
                self._log('verify_error', {code: response.errorcode});
                return response;
            }

            self._log('identity_check', {
                decision: response.decision,
                similarity: response.similarity,
                liveness: response.livenessscore
            });

            if (response.decision === 'fail' || response.decision === 'fail_liveness') {
                self._log('face_mismatch', {decision: response.decision});
                if (self.strictLockdown) {
                    self._terminate(response.decision);
                } else {
                    self._interrupt(response.decision);
                }
            } else if (response.decision === 'review') {
                self._interrupt('face_review');
            }
            return response;
        }).catch(function(error) {
            self._log('verify_error', {message: String(error)});
        });
    };

    /* ---------- random evidence clip ---------- */

    AttentionMonitor.prototype._scheduleNextClip = function(now) {
        if (!this.clipsPerHour || this.clipsPerHour <= 0) {
            this._nextClipAt = Infinity;
            return;
        }
        var mean = 3600000 / this.clipsPerHour;
        // Unpredictable spacing: 40%-160% of the mean interval.
        this._nextClipAt = now + mean * (0.4 + Math.random() * 1.2);
    };

    AttentionMonitor.prototype._recordRandomClip = function(now) {
        var self = this;
        this._scheduleNextClip(now);
        if (this._recording) {
            return;
        }
        var stream = this.getStream ? this.getStream() : null;
        if (!stream || typeof MediaRecorder === 'undefined') {
            this._log('clip_skipped', {reason: stream ? 'no_media_recorder' : 'no_stream'});
            return;
        }

        var recorder;
        try {
            var mime = ['video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm']
                .find(function(candidate) {
                    return MediaRecorder.isTypeSupported(candidate);
                }) || '';
            recorder = new MediaRecorder(stream, mime ? {mimeType: mime} : undefined);
        } catch (error) {
            this._log('clip_error', {message: String(error)});
            return;
        }

        this._recording = true;
        this._recorder = recorder;
        var chunks = [];
        recorder.ondataavailable = function(event) {
            if (event.data && event.data.size) {
                chunks.push(event.data);
            }
        };
        recorder.onstop = function() {
            self._recording = false;
            self._recorder = null;
            self._uploadClip(new Blob(chunks, {type: recorder.mimeType || 'video/webm'}));
        };
        recorder.start();
        this._log('clip_started', {seconds: this.clipMs / 1000});
        this._clipTimer = setTimeout(function() {
            if (recorder.state !== 'inactive') {
                recorder.stop();
            }
        }, this.clipMs);
    };

    AttentionMonitor.prototype._uploadClip = function(blob) {
        var self = this;
        Api.storeEvidence(this.contextid, 'clip', 'random_sample', blob, this.attemptid)
            .then(function(response) {
                self._log(response.ok ? 'clip_uploaded' : 'clip_error',
                    response.ok ? {evidenceid: response.evidenceid} : {code: response.errorcode});
                return response;
            }).catch(function(error) {
                self._log('clip_error', {message: String(error)});
            });
    };

    /** Capture a still immediately when a violation happens. */
    AttentionMonitor.prototype._captureEvidence = function(reason) {
        var self = this;
        if (!this.getSnapshot) {
            return;
        }
        this.getSnapshot().then(function(blob) {
            return Api.storeEvidence(self.contextid, 'snapshot', reason, blob, self.attemptid);
        }).catch(function() {
            // Supplementary evidence — never let it break policy enforcement.
            return null;
        });
    };

    AttentionMonitor.prototype._stopRecording = function() {
        clearTimeout(this._clipTimer);
        if (this._recorder && this._recorder.state !== 'inactive') {
            try {
                this._recorder.stop();
            } catch (error) {
                // Already stopped.
            }
        }
    };

    /* ---------- interruption and overlay ---------- */

    AttentionMonitor.prototype._interrupt = function(type, detail) {
        var self = this;
        if (this._terminated) {
            return;
        }
        this.video.pause();
        this._log(type, detail || {});
        Str.get_string('violation:' + type, 'local_kaiproctor').then(function(message) {
            self._interruptOverlay(type, message);
            return message;
        }).catch(Notification.exception);
    };

    AttentionMonitor.prototype._interruptOverlay = function(type, message) {
        var self = this;
        Str.get_strings([
            {key: 'paused:title', component: 'local_kaiproctor'},
            {key: 'paused:resume', component: 'local_kaiproctor'}
        ]).then(function(strings) {
            self._showOverlay(strings[0], message, strings[1], function() {
                self._removeOverlay();
                self._lastActivity = Date.now();
                self._lastConfirm = Date.now();
                self._log('resumed', {after: type});
                self.video.play();
            }, true);
            return strings;
        }).catch(Notification.exception);
    };

    AttentionMonitor.prototype._showOverlay = function(title, message, buttonText, onClick, blocking) {
        this._removeOverlay();
        var host = this.video.parentElement;
        if (window.getComputedStyle(host).position === 'static') {
            host.style.position = 'relative';
        }

        var overlay = document.createElement('div');
        overlay.className = 'kaiproctor-overlay' + (blocking ? ' kaiproctor-overlay-blocking' : '');
        overlay.dataset.blocking = blocking ? 'true' : 'false';

        var titleEl = document.createElement('div');
        titleEl.className = 'kaiproctor-overlay-title';
        titleEl.textContent = title;

        var messageEl = document.createElement('div');
        messageEl.className = 'kaiproctor-overlay-message';
        messageEl.textContent = message;

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-primary kaiproctor-overlay-btn';
        button.textContent = buttonText;
        button.addEventListener('click', onClick);

        overlay.appendChild(titleEl);
        overlay.appendChild(messageEl);
        overlay.appendChild(button);
        host.appendChild(overlay);
        this._overlay = overlay;
    };

    AttentionMonitor.prototype._removeOverlay = function() {
        if (this._overlay) {
            this._overlay.remove();
            this._overlay = null;
        }
    };

    AttentionMonitor.prototype._log = function(type, detail) {
        var videotime = this.video ? Math.round(this.video.currentTime) : null;
        Api.logEvent(this.contextid, type, detail, videotime).catch(function() {
            // Losing one audit line must not stop enforcement; the server-side
            // gap is itself visible in the log.
            return null;
        });
    };

    return AttentionMonitor;
});
