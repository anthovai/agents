// Wiring for the monitored lesson page.
//
// The camera must be running before the video is allowed to play: a lesson
// that starts while the monitor cannot see anyone is not a monitored lesson.
//
// The policy comes back from start_session rather than being read off the
// page, so the rules recorded against the sitting are the same ones the
// monitor enforces.
define([
    'local_kaiproctor/camera',
    'local_kaiproctor/attention_monitor',
    'local_kaiproctor/api',
    'core/str',
    'core/notification'
], function(Camera, AttentionMonitor, Api, Str, Notification) {

    return {
        init: function(config) {
            var root = document.querySelector('[data-region="kaiproctor-lesson"]');
            if (!root) {
                return;
            }

            var lesson = root.querySelector('[data-region="lesson-video"]');
            var preview = root.querySelector('[data-region="preview"]');
            var startButton = root.querySelector('[data-action="start"]');
            var status = root.querySelector('[data-region="status"]');
            var camera = new Camera(preview);
            var monitor = null;
            var sessionid = 0;
            var closed = false;

            var setStatus = function(message, level) {
                status.className = 'alert alert-' + level;
                status.textContent = message;
                status.hidden = false;
            };

            var closeSession = function(status, reason) {
                if (!sessionid || closed) {
                    return;
                }
                closed = true;
                Api.endSession(sessionid, status, reason).catch(function() {
                    return null;
                });
            };

            startButton.addEventListener('click', function() {
                startButton.disabled = true;
                status.hidden = true;
                var policy = null;

                camera.start().then(function() {
                    return Api.startSession(config.contextid, 0);
                }).then(function(response) {
                    policy = response;
                    sessionid = response.sessionid;

                    monitor = new AttentionMonitor({
                        video: lesson,
                        contextid: config.contextid,
                        sessionid: sessionid,
                        getSnapshot: function() {
                            return camera.snapshot();
                        },
                        getStream: function() {
                            return camera.getStream();
                        },
                        identityEnabled: policy.enrolled,
                        strictLockdown: policy.strictlockdown,
                        blurAllowance: policy.blurallowance,
                        presenceMinutes: policy.presenceminutes,
                        verifyMinutes: policy.verifyminutes,
                        clickConfirmMinutes: policy.clickconfirmminutes,
                        clickConfirmGraceSec: policy.clickconfirmgracesec,
                        mouseIdleMinutes: policy.mouseidleminutes,
                        randomClipsPerHour: policy.randomclipsperhour,
                        clipSeconds: policy.clipseconds,
                        desktopNotification: policy.desktopnotification,
                        onTerminate: function(info) {
                            closeSession('terminated', info.type);
                            if (!info.closed) {
                                return;
                            }
                            camera.stop();
                            window.location.href = config.returnurl;
                        }
                    });
                    monitor.start();

                    // Reaching the end of the lesson is the one moment the
                    // browser can honestly call a completion.
                    lesson.addEventListener('ended', function() {
                        monitor.stop();
                        camera.stop();
                        closeSession('completed', 'video_ended');
                    }, {once: true});

                    lesson.play();
                    return Str.get_string('lesson:monitoring', 'local_kaiproctor');
                }).then(function(message) {
                    setStatus(message, 'info');
                    return message;
                }).catch(function(error) {
                    startButton.disabled = false;
                    camera.stop();
                    closeSession('terminated', 'startup_failed');
                    var key = (error && error.message === 'nocamera')
                        ? 'error:nocamera' : 'error:generic';
                    Str.get_string(key, 'local_kaiproctor').then(function(message) {
                        setStatus(message, 'danger');
                        return message;
                    }).catch(Notification.exception);
                });
            });

            // Leaving the page releases the camera but does NOT close the
            // sitting. A reload, a lost connection and giving up look identical
            // from here, and calling any of them "completed" would put a
            // finish in the record that nobody witnessed. An unclosed sitting
            // is picked up by the cleanup task instead, which records only
            // what is actually known: monitoring stopped.
            window.addEventListener('pagehide', function() {
                if (monitor) {
                    monitor.stop();
                }
                camera.stop();
            });
        }
    };
});
