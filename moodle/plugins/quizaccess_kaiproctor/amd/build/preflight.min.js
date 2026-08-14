// Identity check on the quiz preflight form.
//
// Running the challenge writes a pass row server-side; the rule reads that row
// when the form is submitted. Nothing this module sets on the form is trusted.
//
// The UI is a small state machine, mirrored on the region's data-state so the
// stylesheet can draw each stage: idle -> running -> checking -> passed|failed.
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
            var steps = root.querySelector('[data-region="steps"]');
            var camera = new Camera(preview);

            var setState = function(state) {
                root.setAttribute('data-state', state);
            };

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

            // One chip per pose, plus the final face-match step. Poses are
            // only revealed as they start (the order is randomised), so chips
            // begin as numbers and take their label when their turn comes.
            var renderSteps = function(total) {
                steps.textContent = '';
                for (var i = 0; i < total + 1; i++) {
                    var chip = document.createElement('li');
                    chip.className = 'kaiproctor-preflight-step';
                    chip.dataset.status = 'pending';
                    chip.textContent = (i + 1);
                    steps.appendChild(chip);
                }
                Str.get_string('preflight:stepverify', 'quizaccess_kaiproctor')
                    .then(function(text) {
                        steps.lastElementChild.textContent = text;
                        return text;
                    }).catch(Notification.exception);
                steps.hidden = false;
            };

            var markStep = function(index, state) {
                var chip = steps.children[index];
                if (chip) {
                    chip.dataset.status = state;
                }
            };

            button.addEventListener('click', function() {
                button.disabled = true;
                status.hidden = true;
                setState('running');

                // A retry gets a fresh sequence, so the chips start over too.
                steps.textContent = '';
                steps.hidden = true;

                // Which pose is on screen, so step_pass (which does not carry
                // an index) can close the right chip and spot the last one.
                var current = -1;
                var total = 0;

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
                                if (steps.children.length === 0) {
                                    renderSteps(progress.total);
                                }
                                current = progress.index;
                                total = progress.total;
                                markStep(current, 'active');
                                liveness.label(progress.pose).then(function(text) {
                                    instruction.textContent = text;
                                    steps.children[current].textContent = text;
                                    return text;
                                }).catch(Notification.exception);
                            } else if (progress.phase === 'step_pass') {
                                markStep(current, 'done');
                                if (current === total - 1) {
                                    // Every pose passed; the frame is now on
                                    // its way to the face service.
                                    setState('checking');
                                    markStep(total, 'active');
                                    Str.get_string('preflight:checking',
                                        'quizaccess_kaiproctor')
                                        .then(function(text) {
                                            instruction.textContent = text;
                                            return text;
                                        }).catch(Notification.exception);
                                }
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
                        markStep(total, 'done');
                        setState('passed');
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
                    setState('failed');
                    button.disabled = false;
                    return show('preflight:failed', 'danger');
                }).catch(function(error) {
                    camera.stop();
                    instruction.textContent = '';
                    setState('failed');
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
