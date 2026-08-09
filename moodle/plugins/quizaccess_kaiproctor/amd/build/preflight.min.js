// Identity check on the quiz preflight form.
//
// Running the challenge writes a pass row server-side; the rule reads that row
// when the form is submitted. Nothing this module sets on the form is trusted.
define([
    'local_kaiproctor/camera',
    'local_kaiproctor/active_liveness',
    'core/str',
    'core/notification'
], function(Camera, ActiveLiveness, Str, Notification) {

    return {
        init: function(config) {
            var root = document.querySelector('[data-region="kaiproctor-preflight"]');
            if (!root) {
                return;
            }

            var preview = root.querySelector('[data-region="preview"]');
            var instruction = root.querySelector('[data-region="instruction"]');
            var status = root.querySelector('[data-region="status"]');
            var button = root.querySelector('[data-action="verify"]');
            var camera = new Camera(preview);

            var setStatus = function(message, level) {
                status.className = 'alert alert-' + level;
                status.textContent = message;
                status.hidden = false;
            };

            var show = function(key, level) {
                return Str.get_string(key, 'quizaccess_kaiproctor').then(function(message) {
                    setStatus(message, level);
                    return message;
                }).catch(Notification.exception);
            };

            button.addEventListener('click', function() {
                button.disabled = true;
                status.hidden = true;

                camera.start().then(function() {
                    var liveness = new ActiveLiveness({
                        mode: 'verify',
                        contextid: config.contextid,
                        attemptid: config.attemptid,
                        getSnapshot: function() {
                            return camera.snapshot();
                        },
                        onProgress: function(progress) {
                            if (progress.phase === 'step_start') {
                                liveness.label(progress.pose).then(function(text) {
                                    instruction.textContent = text;
                                    return text;
                                }).catch(Notification.exception);
                            } else if (progress.phase === 'hint') {
                                Str.get_string('hint:' + progress.hint, 'local_kaiproctor')
                                    .then(function(text) {
                                        instruction.textContent = text;
                                        return text;
                                    }).catch(Notification.exception);
                            }
                        }
                    });
                    return liveness.run();
                }).then(function(result) {
                    camera.stop();
                    instruction.textContent = '';
                    if (result.ok) {
                        // The form's own submit button is what starts the
                        // attempt; this only reports that the check passed.
                        // The field lives in the form, outside this region.
                        var marker = document.querySelector('[name="kaiproctorattempted"]');
                        if (marker) {
                            marker.value = 1;
                        }
                        button.disabled = true;
                        return show('preflight:verified', 'success');
                    }
                    button.disabled = false;
                    return show('preflight:failed', 'danger');
                }).catch(function(error) {
                    camera.stop();
                    button.disabled = false;
                    Str.get_string(
                        error && error.message === 'nocamera' ? 'error:nocamera' : 'error:generic',
                        'local_kaiproctor'
                    ).then(function(message) {
                        setStatus(message, 'danger');
                        return message;
                    }).catch(Notification.exception);
                });
            });
        }
    };
});
