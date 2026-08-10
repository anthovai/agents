// Runs the attention monitor on a course activity that somebody flagged as
// monitored — an interactive video, a page, whatever it happens to be.
//
// The camera starts on the learner's first interaction, because getUserMedia
// needs a user gesture. Until then the learner sees a banner saying the
// activity is monitored and asking them to begin.
//
// The rules being enforced come from the server when the sitting opens, not
// from the page: whatever policy is recorded against that sitting is what an
// auditor will be shown later, so it has to be the same one that governs the
// monitor's behaviour.
define([
    'local_kaiproctor/camera',
    'local_kaiproctor/attention_monitor',
    'local_kaiproctor/video_adapter',
    'local_kaiproctor/api',
    'core/str',
    'core/notification'
], function(Camera, AttentionMonitor, VideoAdapter, Api, Str, Notification) {

    return {
        init: function(config) {
            var started = false;
            var monitor = null;
            var adapter = null;
            var sessionid = 0;
            var closed = false;

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

            var closeSession = function(status, reason) {
                if (!sessionid || closed) {
                    return;
                }
                closed = true;
                Api.endSession(sessionid, status, reason).catch(function() {
                    // A sitting nobody managed to close is picked up by the
                    // cleanup task and marked abandoned, which is the truth.
                    return null;
                });
            };

            var start = function() {
                if (started) {
                    return;
                }
                started = true;

                document.body.appendChild(preview);
                var camera = new Camera(preview);
                var policy = null;

                camera.start().then(function() {
                    return Api.startSession(config.contextid, 0);
                }).then(function(response) {
                    policy = response;
                    sessionid = response.sessionid;
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

                    return Str.get_string('activity:monitoring', 'local_kaiproctor');
                }).then(function(text) {
                    banner.className = 'alert alert-success kaiproctor-monitor-banner';
                    banner.textContent = text;
                    return text;
                }).catch(function(error) {
                    started = false;
                    camera.stop();
                    closeSession('terminated', 'startup_failed');
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

            // Deliberately does not close the sitting: see lesson_page.js.
            // There is no completion signal for a page the learner simply
            // navigates away from, and inventing one would be a lie in an
            // audit trail.
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
