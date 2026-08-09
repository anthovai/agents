// Wiring for the monitored lesson page.
//
// The camera must be running before the video is allowed to play: a lesson
// that starts while the monitor cannot see anyone is not a monitored lesson.
define([
    'local_kaiproctor/camera',
    'local_kaiproctor/attention_monitor',
    'core/str',
    'core/notification'
], function(Camera, AttentionMonitor, Str, Notification) {

    return {
        /**
         * @param {Object} config policy values and context, from the page
         */
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

            var setStatus = function(message, level) {
                status.className = 'alert alert-' + level;
                status.textContent = message;
                status.hidden = false;
            };

            startButton.addEventListener('click', function() {
                startButton.disabled = true;
                status.hidden = true;

                camera.start().then(function() {
                    monitor = new AttentionMonitor({
                        video: lesson,
                        contextid: config.contextid,
                        getSnapshot: function() {
                            return camera.snapshot();
                        },
                        getStream: function() {
                            return camera.getStream();
                        },
                        identityEnabled: config.enrolled,
                        strictLockdown: config.strictlockdown,
                        blurAllowance: config.blurallowance,
                        presenceMinutes: config.presenceminutes,
                        verifyMinutes: config.verifyminutes,
                        clickConfirmMinutes: config.clickconfirmminutes,
                        clickConfirmGraceSec: config.clickconfirmgracesec,
                        mouseIdleMinutes: config.mouseidleminutes,
                        randomClipsPerHour: config.randomclipsperhour,
                        clipSeconds: config.clipseconds,
                        desktopNotification: config.desktopnotification,
                        onTerminate: function(info) {
                            if (!info.closed) {
                                return;
                            }
                            camera.stop();
                            window.location.href = config.returnurl;
                        }
                    });
                    monitor.start();
                    lesson.play();
                    return Str.get_string('lesson:monitoring', 'local_kaiproctor');
                }).then(function(message) {
                    setStatus(message, 'info');
                    return message;
                }).catch(function(error) {
                    startButton.disabled = false;
                    camera.stop();
                    var key = (error && error.message === 'nocamera')
                        ? 'error:nocamera' : 'error:generic';
                    Str.get_string(key, 'local_kaiproctor').then(function(message) {
                        setStatus(message, 'danger');
                        return message;
                    }).catch(Notification.exception);
                });
            });

            // Leaving the page mid-lesson still has to release the camera; the
            // monitor has already logged whatever caused the departure.
            window.addEventListener('pagehide', function() {
                if (monitor) {
                    monitor.stop();
                }
                camera.stop();
            });
        }
    };
});
