// The interactive part of an interactive video.
//
// The whole module turns on one decision: when is a question due? Watching for
// the playhead to *cross* a timestamp is the obvious answer and the wrong one,
// because a viewer can drag the seek bar straight past it. So a question is
// due whenever the playhead is at or beyond it and it has not been answered —
// which makes seeking, resuming and ordinary playback the same case, and
// leaves no route around a question that must be answered.
//
// That rule is why the controls below are ours rather than the player's. With
// YouTube's own controls a viewer can seek and resume from inside the iframe,
// where nothing here can intervene, and "the video will not continue past an
// unanswered question" would stop being true.
//
// Nothing here decides whether an answer was right. The timeline arrives
// without correct answers in it; the server says what happened, afterwards.
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory(root.InteractiveVideo && root.InteractiveVideo.backends);
    } else if (typeof define === 'function' && define.amd) {
        define(['./backends'], factory);
    } else {
        root.InteractiveVideo = root.InteractiveVideo || {};
        root.InteractiveVideo.Player = factory(root.InteractiveVideo.backends);
    }
}(typeof self !== 'undefined' ? self : this, function (backends) {

    /** Close enough to "now" — the playhead is sampled about four times a
     *  second by either backend. */
    var EPSILON = 0.25;

    /** How often to tell the server where we are. Often enough to be useful
     *  after a browser crash, rare enough not to be chatty. */
    var PROGRESS_EVERY = 15000;

    /** What a backend threw, and what the viewer should be told about it. */
    var BACKEND_ERRORS = {
        vimeo_api_unreachable: 'error:vimeo',
        vimeo_api_unusable: 'error:vimeo',
        hls_unavailable: 'error:stream',
        hls_unsupported: 'error:stream',
        youtube_api_unreachable: 'error:youtube'
    };

    var el = function (tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    };

    /**
     * @param {Object} opts
     * @param {Element} opts.mount where the player is built
     * @param {Object} opts.api see api.js — four calls, all yours to implement
     * @param {Object} [opts.strings] the words the viewer reads
     * @param {Function} [opts.onAnswer] called with (item, result) after each
     * @param {Function} [opts.onFinished] called once the video ends
     * @param {Function} [opts.onError] where an error inside a promise goes
     */
    var Player = function (opts) {
        this.mount = opts.mount;
        this.api = opts.api;
        this.strings = opts.strings || {};
        this.onAnswer = opts.onAnswer || function () {};
        this.onFinished = opts.onFinished || function () {};
        this.onError = opts.onError || function (error) {
            if (typeof console !== 'undefined') {
                console.error('[interactive-video]', error);
            }
        };

        this.answered = {};
        this.current = null;
        this.lastReported = 0;
        this.timeline = [];

        this.build();
        this.load();
    };

    /**
     * One piece of text.
     *
     * Falls back to the key rather than to an empty string: a button with no
     * words on it looks like a broken page, where one reading "submit" looks
     * like a missing translation, which is what it is.
     *
     * @param {String} key
     * @return {String}
     */
    Player.prototype.text = function (key) {
        return this.strings[key] === undefined ? key : this.strings[key];
    };

    /* ---------- the page ---------- */

    Player.prototype.build = function () {
        var self = this;
        var root = el('div', 'iv-root');
        root.setAttribute('data-state', 'loading');

        var stage = el('div', 'iv-stage');
        // All four providers get a slot. Only one is ever filled, and the
        // backend chooses which — keeping them all here means the backend
        // needs no knowledge of how this page is put together.
        this.video = el('video', 'iv-video');
        this.video.setAttribute('playsinline', '');
        stage.appendChild(this.video);
        this.youtube = el('div', 'iv-frame');
        stage.appendChild(this.youtube);
        this.vimeo = el('div', 'iv-frame');
        stage.appendChild(this.vimeo);
        // A transparent sheet over the iframe providers. Vimeo's controls can
        // be asked to go away, but whether the request is honoured depends on
        // the account the video sits in — and a guarantee that holds only on
        // some customers' accounts is not one. Putting them out of reach is.
        this.sheet = el('div', 'iv-sheet');
        stage.appendChild(this.sheet);

        this.panel = this.buildPanel();
        stage.appendChild(this.panel);
        root.appendChild(stage);

        var bar = el('div', 'iv-controls');
        this.playButton = el('button', 'iv-btn', this.text('play'));
        this.playButton.type = 'button';
        this.playButton.addEventListener('click', function () {
            self.toggle();
        });
        var back = el('button', 'iv-btn', this.text('back'));
        back.type = 'button';
        back.addEventListener('click', function () {
            // Forward only: seeking back to re-watch is fine, and a control
            // that jumps ahead is a control for skipping questions.
            self.backend.seek(Math.max(0, self.backend.currentTime() - 10));
            self.check();
        });
        bar.appendChild(this.playButton);
        bar.appendChild(back);

        this.progressBar = el('div', 'iv-progress');
        this.progressFill = el('div', 'iv-progress-fill');
        this.progressBar.appendChild(this.progressFill);
        bar.appendChild(this.progressBar);

        this.clock = el('span', 'iv-clock', '0:00');
        bar.appendChild(this.clock);
        root.appendChild(bar);

        this.problem = el('p', 'iv-problem');
        this.problem.hidden = true;
        root.appendChild(this.problem);

        this.mount.appendChild(root);
        this.root = root;
    };

    Player.prototype.buildPanel = function () {
        var self = this;
        var panel = el('div', 'iv-panel');
        panel.hidden = true;

        this.questionText = el('p', 'iv-question');
        panel.appendChild(this.questionText);

        this.choices = el('div', 'iv-choices');
        panel.appendChild(this.choices);

        this.typed = el('div', 'iv-typed');
        this.input = el('input', 'iv-input');
        this.input.type = 'text';
        this.input.addEventListener('keydown', function (event) {
            // A one-line answer box that ignores Enter is a box people press
            // Enter in and then wonder why nothing happened.
            if (event.key === 'Enter') {
                event.preventDefault();
                self.submit();
            }
        });
        this.typed.appendChild(this.input);
        panel.appendChild(this.typed);

        this.submitButton = el('button', 'iv-btn iv-btn-primary', this.text('submit'));
        this.submitButton.type = 'button';
        this.submitButton.addEventListener('click', function () {
            self.submit();
        });
        panel.appendChild(this.submitButton);

        this.outcome = el('div', 'iv-outcome');
        this.outcome.hidden = true;
        this.verdict = el('p', 'iv-verdict');
        this.feedback = el('p', 'iv-feedback');
        this.retryButton = el('button', 'iv-btn', this.text('retry'));
        this.retryButton.type = 'button';
        this.retryButton.addEventListener('click', function () {
            self.show(self.current);
        });
        this.continueButton = el('button', 'iv-btn iv-btn-primary',
                                 this.text('continue'));
        this.continueButton.type = 'button';
        this.continueButton.addEventListener('click', function () {
            self.dismiss();
        });
        this.outcome.appendChild(this.verdict);
        this.outcome.appendChild(this.feedback);
        this.outcome.appendChild(this.retryButton);
        this.outcome.appendChild(this.continueButton);
        panel.appendChild(this.outcome);

        return panel;
    };

    /* ---------- loading ---------- */

    Player.prototype.load = function () {
        var self = this;
        this.api.load().then(function (data) {
            if (!data || !data.ok) {
                self.fail('error:player');
                return null;
            }
            self.config = data.video;
            self.timeline = data.timeline || [];
            (data.answered || []).forEach(function (row) {
                self.answered[row.item_id] = row;
            });
            self.resumeAt = data.resume_at || 0;

            return backends.create(
                {provider: data.video.provider, source: data.video.source},
                {video: self.video, youtube: self.youtube, vimeo: self.vimeo});
        }).then(function (backend) {
            if (!backend) {
                return;
            }
            self.backend = backend;
            self.sheet.hidden = (self.config.provider !== 'vimeo'
                                 && self.config.provider !== 'youtube');
            self.attach();
            self.root.setAttribute('data-state', 'ready');
        }).catch(function (error) {
            var reason = (error && error.message) || '';
            self.fail(BACKEND_ERRORS[reason] || 'error:player');
            self.onError(error);
        });
    };

    Player.prototype.attach = function () {
        var self = this;

        this.backend.onTick(function () {
            self.check();
            self.tickUi();
            self.maybeReport();
        });

        // Leaving the page is the commonest way a video ends, and without
        // this the position is whatever the last fifteen-second tick wrote: a
        // viewer who watched to 28s and left is sent back to 15s and made to
        // sit through it again.
        //
        // Both events, because neither fires reliably alone: pagehide is
        // skipped when a mobile browser is backgrounded and killed, and
        // visibilitychange does not fire on some desktop navigations. Sending
        // twice is harmless — the server keeps the furthest point.
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                self.report(false, true);
            }
        });
        window.addEventListener('pagehide', function () {
            self.report(false, true);
        });

        this.backend.onPlayAttempt(function () {
            // A question that is due must not be playable past.
            if (self.config.must_answer && self.due()) {
                self.check();
            }
        });

        this.backend.onEnded(function () {
            self.report(true);
            self.onFinished();
        });

        if (this.resumeAt > 5) {
            this.backend.seek(this.resumeAt);
        }
    };

    /* ---------- the rule ---------- */

    /**
     * The question that should be on screen now, if any.
     *
     * Earliest first, so a viewer who seeks past three unanswered questions
     * answers them in the order the author wrote them rather than in reverse.
     *
     * @return {Object|null}
     */
    Player.prototype.due = function () {
        var now = this.backend.currentTime() + EPSILON;
        for (var i = 0; i < this.timeline.length; i++) {
            var item = this.timeline[i];
            if (item.at <= now && !this.answered[item.id]) {
                return item;
            }
        }
        return null;
    };

    Player.prototype.check = function () {
        if (this.current) {
            return;
        }
        var item = this.due();
        if (item) {
            this.backend.pause();
            this.show(item);
        }
    };

    /* ---------- asking ---------- */

    Player.prototype.show = function (item) {
        var self = this;
        this.current = item;
        this.root.setAttribute('data-state', 'question');
        this.root.setAttribute('data-question-type', item.type);

        this.questionText.textContent = item.text;
        this.outcome.hidden = true;
        this.choices.textContent = '';
        this.choices.hidden = (item.type !== 'choice' && item.type !== 'multichoice');
        this.typed.hidden = (item.type !== 'shorttext');
        this.submitButton.hidden = (item.type !== 'multichoice'
                                    && item.type !== 'shorttext');

        // Rebuilt options arrive enabled; these two do not, and without this
        // the submit button stayed disabled for the whole rest of the video
        // after the first question — every later one displayed and could not
        // be sent.
        this.submitButton.disabled = false;
        this.input.disabled = false;
        this.input.value = '';
        this.panel.hidden = false;

        if (item.type === 'info') {
            this.answer(item, '');
            return;
        }
        if (item.type === 'shorttext') {
            this.input.focus();
            return;
        }

        var single = (item.type === 'choice');
        (item.choices || []).forEach(function (label, index) {
            if (single) {
                // Clicking one *is* the answer — there is nothing to submit.
                var button = el('button', 'iv-choice', label);
                button.type = 'button';
                button.addEventListener('click', function () {
                    self.answer(item, JSON.stringify([index]));
                });
                self.choices.appendChild(button);
                return;
            }
            // Checkboxes, because there is no way to click three things at
            // once and every click cannot be a submission.
            var wrap = el('label', 'iv-check');
            var box = el('input');
            box.type = 'checkbox';
            box.value = String(index);
            wrap.appendChild(box);
            wrap.appendChild(el('span', null, label));
            self.choices.appendChild(wrap);
        });
    };

    Player.prototype.submit = function () {
        var item = this.current;
        if (!item) {
            return;
        }

        if (item.type === 'shorttext') {
            var value = this.input.value;
            // An empty box is not an answer. Sending it would record a wrong
            // answer they did not give and, where retries are off, spend
            // their only attempt on a mis-click.
            if (!value.trim()) {
                return;
            }
            this.answer(item, value);
            return;
        }

        var picked = [];
        Array.prototype.forEach.call(
            this.choices.querySelectorAll('input:checked'),
            function (box) {
                picked.push(parseInt(box.value, 10));
            });
        if (!picked.length) {
            return;
        }
        this.answer(item, JSON.stringify(picked));
    };

    Player.prototype.answer = function (item, response) {
        var self = this;
        var fields = this.panel.querySelectorAll('button, input');
        var enable = function (state) {
            Array.prototype.forEach.call(fields, function (field) {
                field.disabled = !state;
            });
        };
        enable(false);

        this.api.answer(item.id, response).then(function (result) {
            if (!result || !result.ok) {
                // The answer did not reach the server, so it did not happen.
                // Let them try again rather than recording nothing and moving
                // on.
                self.fail('error:answer');
                enable(true);
                return;
            }
            self.answered[item.id] = {item_id: item.id, correct: result.correct};
            self.outcomeFor(item, result);
            self.onAnswer(item, result);
        }).catch(function (error) {
            self.fail('error:answer');
            enable(true);
            self.onError(error);
        });
    };

    Player.prototype.outcomeFor = function (item, result) {
        this.choices.hidden = true;
        this.typed.hidden = true;
        this.submitButton.hidden = true;

        var parts = [];
        // The expected answer only ever arrives once it is theirs to see —
        // the server withholds it while a retry is still on offer, so showing
        // whatever came back cannot give away a mark.
        if (result.answers && result.answers.length) {
            parts.push(this.text('answer:expected') + ' ' + result.answers.join(' / '));
        }
        if (result.feedback) {
            parts.push(result.feedback);
        }
        this.feedback.textContent = parts.join(' — ');

        if (item.type === 'info') {
            // An info card has no verdict. "Correct" under a message that was
            // only ever meant to be read makes it look like a question they
            // got right, which is the confusion the type exists to avoid.
            this.root.setAttribute('data-state', 'info');
            this.verdict.textContent = '';
            this.retryButton.hidden = true;
        } else {
            this.root.setAttribute('data-state', result.correct ? 'correct' : 'wrong');
            this.verdict.textContent = this.text(result.correct ? 'correct' : 'wrong');
            this.verdict.className = 'iv-verdict ' +
                (result.correct ? 'iv-right' : 'iv-wrong');
            // A wrong answer is worth another go when the video allows it:
            // the point of a question in the middle of a lesson is that they
            // learn the answer, not that they are caught out by it.
            this.retryButton.hidden = !result.may_retry;
        }

        this.outcome.hidden = false;
        this.continueButton.disabled = false;
        this.retryButton.disabled = false;
    };

    Player.prototype.dismiss = function () {
        this.panel.hidden = true;
        this.current = null;
        this.root.setAttribute('data-state', 'playing');

        // Another question may be due at the same moment, or the viewer may
        // have seeked past several. check() finds the next one; if there is
        // none, play resumes.
        if (this.due()) {
            this.check();
            return;
        }
        this.backend.play();
    };

    /* ---------- controls and chrome ---------- */

    Player.prototype.toggle = function () {
        if (this.current) {
            return;
        }
        if (this.backend.isPaused()) {
            this.backend.play();
        } else {
            this.backend.pause();
        }
    };

    Player.prototype.tickUi = function () {
        var now = this.backend.currentTime();
        var total = this.backend.duration();
        this.playButton.textContent =
            this.text(this.backend.isPaused() ? 'play' : 'pause');
        this.progressFill.style.width =
            (total ? Math.min(100, (now / total) * 100) : 0) + '%';
        var minutes = Math.floor(now / 60);
        var seconds = Math.floor(now % 60);
        this.clock.textContent = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
    };

    Player.prototype.fail = function (key) {
        this.problem.textContent = this.text(key);
        this.problem.hidden = false;
    };

    /* ---------- progress ---------- */

    Player.prototype.maybeReport = function () {
        if (Date.now() - this.lastReported < PROGRESS_EVERY) {
            return;
        }
        this.report(false);
    };

    /**
     * @param {Boolean} finished whether the video reached its end
     * @param {Boolean} [leaving] the page is going away, so an ordinary
     *        request would be cancelled before it left the browser
     */
    Player.prototype.report = function (finished, leaving) {
        if (!this.backend) {
            return;
        }
        var seconds = this.backend.currentTime();
        this.lastReported = Date.now();

        if (leaving && this.api.beacon && this.api.beacon(seconds, finished)) {
            return;
        }
        this.api.progress(seconds, finished).catch(function () {
            // Progress is advisory — the record comes from the answers — so a
            // failure here must not interrupt the video.
            return null;
        });
    };

    return Player;
}));
