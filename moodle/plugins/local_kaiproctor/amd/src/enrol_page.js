// Wiring for the face enrolment page.
//
// Camera on -> run the randomised pose challenge -> store the embedding.
// The learner is never shown a raw score: the outcome is enrolled or not, and
// why in words they can act on.
define([
    'local_kaiproctor/camera',
    'local_kaiproctor/active_liveness',
    'core/str',
    'core/notification'
], function(Camera, ActiveLiveness, Str, Notification) {

    return {
        /**
         * @param {Object} config from the page's JS call
         * @param {Boolean} config.alreadyenrolled whether to warn about replacing
         */
        init: function(config) {
            var root = document.querySelector('[data-region="kaiproctor-enrol"]');
            if (!root) {
                return;
            }

            var video = root.querySelector('[data-region="preview"]');
            var startButton = root.querySelector('[data-action="start"]');
            var instruction = root.querySelector('[data-region="instruction"]');
            var status = root.querySelector('[data-region="status"]');
            var camera = new Camera(video);

            var setStatus = function(message, level) {
                status.className = 'alert alert-' + level;
                status.textContent = message;
                status.hidden = false;
            };

            var setInstruction = function(text) {
                instruction.textContent = text;
            };

            var fail = function(key) {
                Str.get_string(key, 'local_kaiproctor').then(function(message) {
                    setStatus(message, 'danger');
                    return message;
                }).catch(Notification.exception);
            };

            startButton.addEventListener('click', function() {
                startButton.disabled = true;
                status.hidden = true;

                camera.start().then(function() {
                    var liveness = new ActiveLiveness({
                        mode: 'enrol',
                        getSnapshot: function() {
                            return camera.snapshot();
                        },
                        onProgress: function(progress) {
                            if (progress.phase === 'step_start') {
                                liveness.label(progress.pose).then(setInstruction)
                                    .catch(Notification.exception);
                            } else if (progress.phase === 'hint') {
                                Str.get_string('hint:' + progress.hint, 'local_kaiproctor')
                                    .then(setInstruction).catch(Notification.exception);
                            }
                        }
                    });
                    return liveness.run();
                }).then(function(result) {
                    camera.stop();
                    setInstruction('');
                    if (result.ok) {
                        return Str.get_string('enrol:success', 'local_kaiproctor')
                            .then(function(message) {
                                setStatus(message, 'success');
                                startButton.disabled = false;
                                return message;
                            });
                    }
                    // Say what was actually in the way.
                    //
                    // This used to be one of two messages, and the general one
                    // told the learner the room might be too dark — for every
                    // cause, including causes that have nothing to do with
                    // light. A message that misattributes the reason is worse
                    // than a vague one: it sends somebody to turn lamps on
                    // while the real problem is that they are sitting too far
                    // from the camera.
                    var blocked = {
                        noface: 'enrol:noface',
                        toosmall: 'enrol:toosmall',
                        multiplefaces: 'enrol:multiplefaces',
                        spoof: 'enrol:spoof'
                    };
                    var key = blocked[result.blockedBy]
                        || ((result.reason || '').indexOf('timeout_') === 0
                            ? 'enrol:timeout' : 'enrol:failed');
                    return Str.get_string(key, 'local_kaiproctor').then(function(message) {
                        setStatus(message, 'danger');
                        startButton.disabled = false;
                        return message;
                    });
                }).catch(function(error) {
                    camera.stop();
                    startButton.disabled = false;
                    fail(error && error.message === 'nocamera' ? 'error:nocamera' : 'error:generic');
                });
            });

            if (config && config.alreadyenrolled) {
                Str.get_string('enrol:replacing', 'local_kaiproctor').then(function(message) {
                    setStatus(message, 'warning');
                    return message;
                }).catch(Notification.exception);
            }
        }
    };
});
