// Runs the attention monitor on a course activity that somebody flagged as
// monitored — an interactive video, a page, whatever it happens to be.
//
// The camera starts on the learner's first interaction, because getUserMedia
// needs a user gesture. Until then the learner sees a banner saying the
// activity is monitored and asking them to begin.
define([
    'local_kaiproctor/camera',
    'local_kaiproctor/attention_monitor',
    'local_kaiproctor/video_adapter',
    'core/str',
    'core/notification'
], function(Camera, AttentionMonitor, VideoAdapter, Str, Notification) {

    return {
        init: function(config) {
            var started = false;
            var monitor = null;
            var adapter = null;

            var preview = document.createElement('video');
            preview.setAttribute('playsinline', '');
            preview.muted = true;
            preview.className = 'kaiproctor-preview kaiproctor-preview-attempt';

            var banner = document.createElement('div');
            banner.className = 'alert alert-info kaiproctor-monitor-banner';
            banner.setAttribute('role', 'status');
            Str.get_string('activity:willmonitor', 'local_kaiproctor').then(function(text) {
                banner.textContent = text;
                return text;
            }).catch(Notification.exception);

            var region = document.querySelector('#region-main') || document.body;
            region.insertBefore(banner, region.firstChild);

            var start = function() {
                if (started) {
                    return;
                }
                started = true;

                document.body.appendChild(preview);
                var camera = new Camera(preview);

                camera.start().then(function() {
                    return VideoAdapter.forPage();
                }).then(function(found) {
                    // No player is not a reason to stop watching: presence,
                    // identity and focus still apply to whatever the learner
                    // is looking at. A detached element stands in so pause()
                    // is a harmless no-op.
                    adapter = found || document.createElement('video');

                    monitor = new AttentionMonitor({
                        video: adapter,
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

                    return Str.get_string('activity:monitoring', 'local_kaiproctor');
                }).then(function(text) {
                    banner.className = 'alert alert-success kaiproctor-monitor-banner';
                    banner.textContent = text;
                    return text;
                }).catch(function(error) {
                    started = false;
                    camera.stop();
                    var key = (error && error.message === 'nocamera')
                        ? 'error:nocamera' : 'error:generic';
                    Str.get_string(key, 'local_kaiproctor').then(function(text) {
                        banner.className = 'alert alert-danger kaiproctor-monitor-banner';
                        banner.textContent = text;
                        return text;
                    }).catch(Notification.exception);
                });
            };

            ['click', 'keydown'].forEach(function(name) {
                document.addEventListener(name, start, {once: true});
            });

            window.addEventListener('pagehide', function() {
                if (monitor) {
                    monitor.stop();
                }
                if (adapter && adapter.destroy) {
                    adapter.destroy();
                }
            });
        }
    };
});
