// Monitoring for the duration of a quiz attempt.
//
// The camera starts on the learner's first interaction rather than on load:
// getUserMedia and requestFullscreen both need a user gesture, and a quiz page
// the learner has not touched yet has no gesture to use.
//
// The sitting is opened against the attempt, so every check, clip and signal
// from this attempt files under one record with the policy that was in force
// when it began.
define([
    'local_kaiproctor/camera',
    'local_kaiproctor/attention_monitor',
    'local_kaiproctor/lockdown',
    'local_kaiproctor/api',
    'core/str',
    'core/notification'
], function(Camera, AttentionMonitor, Lockdown, Api, Str, Notification) {

    return {
        init: function(config) {
            var started = false;
            var monitor = null;
            var lockdown = null;
            var sessionid = 0;
            var closed = false;

            var preview = document.createElement('video');
            preview.setAttribute('playsinline', '');
            preview.muted = true;
            preview.className = 'kaiproctor-preview kaiproctor-preview-attempt';
            document.body.appendChild(preview);

            // The monitor pauses "the lesson" on a violation. A quiz has no
            // video, so it is given a detached one: pause() is then a no-op
            // and every other signal behaves exactly as it does in a lesson.
            var standIn = document.createElement('video');

            var camera = new Camera(preview);

            var closeSession = function(status, reason) {
                if (!sessionid || closed) {
                    return;
                }
                closed = true;
                Api.endSession(sessionid, status, reason).catch(function() {
                    return null;
                });
            };

            var start = function() {
                if (started) {
                    return;
                }
                started = true;
                var policy = null;

                camera.start().then(function() {
                    return Api.startSession(config.contextid, config.attemptid || 0);
                }).then(function(response) {
                    policy = response;
                    sessionid = response.sessionid;

                    monitor = new AttentionMonitor({
                        video: standIn,
                        contextid: config.contextid,
                        attemptid: config.attemptid || 0,
                        sessionid: sessionid,
                        getSnapshot: function() {
                            return camera.snapshot();
                        },
                        getStream: function() {
                            return camera.getStream();
                        },
                        strictLockdown: policy.strictlockdown,
                        blurAllowance: policy.blurallowance,
                        presenceMinutes: policy.presenceminutes,
                        verifyMinutes: policy.verifyminutes,
                        // A quiz already demands attention; interrupting it to
                        // ask "are you still there" would cost answering time.
                        clickConfirmMinutes: 0,
                        mouseIdleMinutes: policy.mouseidleminutes,
                        randomClipsPerHour: policy.randomclipsperhour,
                        clipSeconds: policy.clipseconds,
                        desktopNotification: policy.desktopnotification,
                        onTerminate: function(info) {
                            closeSession('terminated', info.type);
                            if (!info.closed) {
                                return;
                            }
                            if (lockdown) {
                                lockdown.stop();
                            }
                            camera.stop();
                            window.location.href = config.returnurl;
                        }
                    });
                    monitor.start();

                    lockdown = new Lockdown({
                        // Warning on unload would fight the quiz's own submit
                        // and navigation; the monitor already logs departures.
                        warnOnUnload: false,
                        onViolation: function(type, detail) {
                            monitor.reportViolation(type, detail);
                        }
                    });
                    return lockdown.start();
                }).catch(function(error) {
                    started = false;
                    closeSession('terminated', 'startup_failed');
                    var key = (error && error.message === 'nocamera')
                        ? 'error:nocamera' : 'error:generic';
                    Str.get_string(key, 'local_kaiproctor').then(function(message) {
                        Notification.addNotification({message: message, type: 'error'});
                        return message;
                    }).catch(Notification.exception);
                    // A monitored attempt that cannot see the learner is not
                    // monitored — record the gap rather than letting it pass
                    // as a clean attempt.
                    Api.logEvent(config.contextid, 'presence_error',
                        {reason: 'camera_unavailable'}, null, sessionid).catch(function() {
                        return null;
                    });
                });
            };

            ['click', 'keydown'].forEach(function(name) {
                document.addEventListener(name, start, {once: true});
            });

            window.addEventListener('pagehide', function() {
                if (monitor) {
                    monitor.stop();
                }
                if (lockdown) {
                    lockdown.stop();
                }
                camera.stop();
                // The sitting is left open on purpose: leaving the page is not
                // the same as finishing the exam. mod_quiz says when it really
                // ends, through current_attempt_finished(), and the cleanup
                // task closes anything that never got that far.
            });
        }
    };
});
