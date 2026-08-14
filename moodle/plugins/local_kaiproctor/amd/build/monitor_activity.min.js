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
    'local_kaiproctor/beacon',
    'core/str',
    'core/notification'
], function(Camera, AttentionMonitor, VideoAdapter, Api, Beacon, Str, Notification) {

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

            // Record that the page went away. Nothing else did: clicking back
            // to the course fires neither blur nor visibilitychange, so a
            // learner leaving a lesson left no trace at all, and the sitting
            // was only ever closed by the cleanup task calling it abandoned.
            //
            // Only logged here — the sitting is deliberately NOT closed. A
            // sitting spans page loads on purpose, so that a reload does not
            // split one lesson's evidence into two records, and pagehide fires
            // on a reload exactly as it does on leaving. The browser cannot
            // tell those apart; only the server can, by whether anybody came
            // back. Closing here ended a sitting every time somebody pressed
            // F5. Deciding it in the browser is deciding it too early.
            //
            // Not on visibilitychange either: switching tabs is somebody
            // looking away from a lesson that is still open, which the
            // focus-loss rules already handle by pausing and, in strict mode,
            // terminating.
            var onLeave = function() {
                if (!sessionid || closed) {
                    return;
                }

                // Rounded, because videotime is PARAM_INT and Moodle rejects
                // the whole call rather than truncating: passing the raw
                // currentTime silently dropped every one of these while the
                // call beside it went through, which looked like a transport
                // problem and was a type one.
                var at = adapter ? adapter.currentTime : null;
                Beacon.send('local_kaiproctor_log_event', {
                    contextid: config.contextid,
                    type: 'page_left',
                    detail: '{}',
                    videotime: (at === undefined || at === null) ? -1 : Math.round(at),
                    sessionid: sessionid
                });
            };
            window.addEventListener('pagehide', onLeave);

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
                    // Named by Camera.reason(): a learner who pressed "block"
                    // on the permission prompt needs telling that, not
                    // "something went wrong, try again" — retrying cannot fix
                    // a decision the browser has remembered.
                    var keys = {
                        denied: 'error:cameradenied',
                        busy: 'error:camerabusy',
                        nocamera: 'error:nocamera'
                    };
                    var key = keys[(error && error.message) || ''] || 'error:generic';
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
