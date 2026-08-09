// Monitoring for the duration of a quiz attempt.
//
// The camera starts on the learner's first interaction rather than on load:
// getUserMedia and requestFullscreen both need a user gesture, and a quiz page
// the learner has not touched yet has no gesture to use.
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

            var preview = document.createElement('video');
            preview.setAttribute('playsinline', '');
            preview.muted = true;
            preview.className = 'kaiproctor-preview kaiproctor-preview-attempt';
            document.body.appendChild(preview);

            // The monitor pauses "the lesson" on a violation. A quiz has no
            // video, so it is given a detached one: pause() is then a no-op
            // and every other signal behaves exactly as it does in a lesson.
            var stand_in = document.createElement('video');

            var camera = new Camera(preview);

            var start = function() {
                if (started) {
                    return;
                }
                started = true;

                camera.start().then(function() {
                    monitor = new AttentionMonitor({
                        video: stand_in,
                        contextid: config.contextid,
                        getSnapshot: function() {
                            return camera.snapshot();
                        },
                        getStream: function() {
                            return camera.getStream();
                        },
                        strictLockdown: config.strictlockdown,
                        blurAllowance: config.blurallowance,
                        presenceMinutes: config.presenceminutes,
                        verifyMinutes: config.verifyminutes,
                        // A quiz already demands attention; interrupting it to
                        // ask "are you still there" would cost answering time.
                        clickConfirmMinutes: 0,
                        mouseIdleMinutes: config.mouseidleminutes,
                        randomClipsPerHour: config.randomclipsperhour,
                        clipSeconds: config.clipseconds,
                        desktopNotification: config.desktopnotification,
                        onTerminate: function(info) {
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
                        {reason: 'camera_unavailable'}, null).catch(function() {
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
            });
        }
    };
});
