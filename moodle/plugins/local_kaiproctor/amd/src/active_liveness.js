// ActiveLiveness — enrolment and verification by performing a randomised
// sequence of head poses, in the style of ThaiID and other government apps.
//
// Ported from face-re/app/static/active-liveness.js. The challenge logic is
// unchanged; what changed is that /analyze, /embed and /verify now go through
// the plugin's web services, and the human-facing strings come from Moodle's
// language packs instead of being hard-coded Thai.
//
// Pose convention, on the raw frame sent to the server:
//   turning to the subject's own left  -> yaw positive
//   turning to the subject's own right -> yaw negative
// The camera preview should be mirrored for a natural feel; mirroring affects
// display only, not the frames sent for analysis, so the mapping holds.
define(['local_kaiproctor/api', 'core/str'], function(Api, Str) {

    /**
     * @param {Object} opts
     * @param {Function} opts.getSnapshot returns a Promise<Blob> of a raw frame
     * @param {String} [opts.mode] 'enrol' (default) or 'verify'
     * @param {Number} [opts.contextid] required when mode is 'verify'
     * @param {Function} [opts.onProgress] receives progress objects for the UI
     */
    var ActiveLiveness = function(opts) {
        this.getSnapshot = opts.getSnapshot;
        this.mode = opts.mode || 'enrol';
        this.contextid = opts.contextid || 0;
        this.attemptid = opts.attemptid || 0;
        this.onProgress = opts.onProgress || function() {};

        this.yawThreshold = opts.yawThreshold === undefined ? 22 : opts.yawThreshold;
        this.centerMax = opts.centerMax === undefined ? 12 : opts.centerMax;
        this.pollMs = opts.pollMs === undefined ? 500 : opts.pollMs;
        this.stepTimeoutMs = (opts.stepTimeoutSec === undefined ? 15 : opts.stepTimeoutSec) * 1000;
        this.requireLiveness = opts.requireLiveness === undefined ? true : opts.requireLiveness;
        this.steps = opts.steps || this._defaultSteps(opts.shuffle !== false);
    };

    /** Look straight ahead first, then the turns in a random order. */
    ActiveLiveness.prototype._defaultSteps = function(shuffle) {
        var turns = ['left', 'right'];
        if (shuffle) {
            for (var i = turns.length - 1; i > 0; i--) {
                var j = Math.floor(Math.random() * (i + 1));
                var tmp = turns[i];
                turns[i] = turns[j];
                turns[j] = tmp;
            }
        }
        return ['center'].concat(turns);
    };

    ActiveLiveness.prototype._sleep = function(ms) {
        return new Promise(function(resolve) {
            setTimeout(resolve, ms);
        });
    };

    ActiveLiveness.prototype._satisfies = function(yaw, step) {
        if (yaw === null || yaw === undefined) {
            return false;
        }
        if (step === 'center') {
            return Math.abs(yaw) <= this.centerMax;
        }
        if (step === 'left') {
            return yaw >= this.yawThreshold;
        }
        if (step === 'right') {
            return yaw <= -this.yawThreshold;
        }
        return false;
    };

    /** Instruction shown for a step, from the language pack. */
    ActiveLiveness.prototype.label = function(step) {
        return Str.get_string('liveness:' + step, 'local_kaiproctor');
    };

    /**
     * Run the whole sequence.
     *
     * @return {Promise<Object>} {ok, challenge, result?, reason?}
     */
    ActiveLiveness.prototype.run = function() {
        var self = this;
        var startedAt = Date.now();
        var record = [];
        var centerBlob = null;

        var runStep = function(index) {
            if (index >= self.steps.length) {
                return self._finish(centerBlob, record, startedAt);
            }

            var step = self.steps[index];
            var stepStart = Date.now();
            self.onProgress({
                phase: 'step_start',
                pose: step,
                index: index,
                total: self.steps.length
            });

            var state = {passed: false, lastYaw: null, lastLive: null, blob: null};

            var poll = function() {
                if (state.passed || Date.now() - stepStart >= self.stepTimeoutMs) {
                    record.push({
                        pose: step,
                        yaw: state.lastYaw,
                        liveness: state.lastLive,
                        ms: Date.now() - stepStart,
                        passed: state.passed
                    });
                    if (!state.passed) {
                        self.onProgress({phase: 'timeout', pose: step});
                        return Promise.resolve({
                            ok: false,
                            reason: 'timeout_' + step,
                            challenge: {
                                sequence: self.steps,
                                steps: record,
                                ms: Date.now() - startedAt
                            }
                        });
                    }
                    self.onProgress({phase: 'step_pass', pose: step, yaw: state.lastYaw});
                    if (step === 'center') {
                        centerBlob = state.blob;
                    }
                    return runStep(index + 1);
                }

                return self.getSnapshot().then(function(blob) {
                    state.blob = blob;
                    return Api.analyze(blob);
                }).then(function(response) {
                    if (!response.ok || !response.present) {
                        self.onProgress({phase: 'hint', pose: step, hint: 'noface'});
                        return self._sleep(self.pollMs);
                    }
                    if (response.warning === 'multiple_faces') {
                        self.onProgress({phase: 'hint', pose: step, hint: 'multiplefaces'});
                        return self._sleep(self.pollMs);
                    }

                    state.lastYaw = response.yaw;
                    state.lastLive = response.livenessscore;

                    // Looking straight ahead must also clear passive liveness:
                    // that is the frame kept as the reference, so a photograph
                    // held up to the camera must not get through here.
                    var liveOk = !self.requireLiveness ||
                        !response.livenessevaluated || response.live;

                    if (self._satisfies(response.yaw, step)) {
                        if (liveOk) {
                            state.passed = true;
                            return Promise.resolve();
                        }
                        self.onProgress({phase: 'hint', pose: step, hint: 'spoof'});
                    }
                    return self._sleep(self.pollMs);
                }).catch(function() {
                    // A dropped request is not a failed challenge — keep polling
                    // until the step's own timeout decides.
                    return self._sleep(self.pollMs);
                }).then(poll);
            };

            return poll();
        };

        return runStep(0);
    };

    /** Every pose passed: enrol or verify using the straight-ahead frame. */
    ActiveLiveness.prototype._finish = function(centerBlob, record, startedAt) {
        var self = this;
        var challenge = {
            sequence: this.steps,
            steps: record,
            ms: Date.now() - startedAt
        };

        var withBlob = centerBlob ? Promise.resolve(centerBlob) : this.getSnapshot();

        return withBlob.then(function(blob) {
            if (self.mode === 'verify') {
                return Api.verify(self.contextid, blob, self.attemptid, true)
                    .then(function(response) {
                        self.onProgress({phase: 'done', ok: response.ok});
                        return {
                            ok: response.ok && response.decision === 'pass',
                            result: response,
                            challenge: challenge
                        };
                    });
            }
            return Api.enrolFace(blob, challenge).then(function(response) {
                self.onProgress({phase: 'done', ok: response.ok});
                return {ok: response.ok, result: response, challenge: challenge};
            });
        }).catch(function() {
            return {ok: false, reason: 'capture_failed', challenge: challenge};
        });
    };

    return ActiveLiveness;
});
