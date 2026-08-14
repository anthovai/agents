// The interactive part of an interactive video.
//
// The whole module turns on one decision: when is a question due? Watching for
// the playhead to *cross* a timestamp is the obvious answer and the wrong one,
// because a learner can drag the seek bar straight past it. So a question is
// due whenever the playhead is at or beyond it and it has not been answered —
// which makes seeking, resuming and ordinary playback the same case, and leaves
// no route around a question that must be answered.
//
// That rule is why the controls below are ours rather than the player's. With
// YouTube's own controls, a learner can seek and resume from inside the iframe
// where nothing here can intervene, and "the video will not continue past an
// unanswered question" would stop being true.
//
// Nothing here decides whether an answer was right. The timeline arrives
// without correct answers in it; the server says what happened, afterwards.
define([
    'core/ajax',
    'core/str',
    'mod_kaivideo/backend'
], function(Ajax, Str, Backend) {

    /** Close enough to "now" — the playhead is sampled about four times a
     *  second by either backend. */
    var EPSILON = 0.25;

    /** How often to tell the server where we are. Often enough to be useful
     *  after a browser crash, rare enough not to be chatty. */
    var PROGRESS_EVERY = 15000;

    /** What a backend threw, and what the learner should be told about it. */
    var BACKEND_ERRORS = {
        vimeo_api_unreachable: 'error:vimeofailed',
        vimeo_api_unusable: 'error:vimeofailed',
        videojs_unavailable: 'error:streamfailed'
    };

    var Player = function(config) {
        this.config = config;
        this.root = document.querySelector('[data-region="kaivideo"]');
        if (!this.root) {
            return;
        }

        this.panel = this.root.querySelector('[data-region="question"]');
        this.answered = {};
        this.current = null;
        this.lastReported = 0;

        (config.answered || []).forEach(function(row) {
            this.answered[row.itemid] = row;
        }.bind(this));

        var self = this;
        Backend.create(config, this.root).then(function(backend) {
            self.backend = backend;
            self.attach();
            self.root.setAttribute('data-state', 'ready');
            return backend;
        }).catch(function(error) {
            // Named, because "the video could not be loaded" sends somebody to
            // check their connection when the real answer is that the Vimeo
            // video does not allow this site, or that the stream's server does
            // not let us read it. Both are somebody else's setting, and neither
            // is findable from a generic message.
            var reason = (error && error.message) || '';
            self.fail(BACKEND_ERRORS[reason] || 'error:playerfailed');
        });
    };

    /**
     * The question that should be on screen now, if any.
     *
     * Earliest first, so a learner who seeks past three unanswered questions
     * answers them in the order the author wrote them rather than in reverse.
     *
     * @return {Object|null}
     */
    Player.prototype.due = function() {
        var now = this.backend.currentTime() + EPSILON;
        var timeline = this.config.timeline || [];

        for (var i = 0; i < timeline.length; i++) {
            var item = timeline[i];
            if (item.attime <= now && !this.answered[item.id]) {
                return item;
            }
        }
        return null;
    };

    Player.prototype.attach = function() {
        var self = this;

        this.backend.onTick(function() {
            self.check();
            self.maybeReport();
        });

        this.backend.onPlayAttempt(function() {
            // A question that is due must not be playable past.
            if (self.config.mustanswer && self.due()) {
                self.check();
            }
        });

        this.backend.onEnded(function() {
            self.report(true);
        });

        this.control('play', function() {
            self.backend.play();
        });
        this.control('pause', function() {
            self.backend.pause();
        });
        this.control('back', function() {
            self.backend.seek(Math.max(0, self.backend.currentTime() - 10));
            self.check();
        });

        this.panel.querySelector('[data-action="continue"]')
            .addEventListener('click', function() {
                self.dismiss();
            });
        this.panel.querySelector('[data-action="retry"]')
            .addEventListener('click', function() {
                self.show(self.current);
            });
        this.panel.querySelector('[data-action="submit"]')
            .addEventListener('click', function() {
                self.submit();
            });
        // Enter submits. A one-line answer box that ignores Enter is a box
        // people press Enter in and then wonder why nothing happened.
        this.panel.querySelector('[data-region="typedinput"]')
            .addEventListener('keydown', function(event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    self.submit();
                }
            });

        // Forward only: seeking back to re-watch is fine, but a control that
        // jumps ahead is a control for skipping questions, and there is no
        // reason to build one.
        if (this.config.resumeat > 5) {
            this.backend.seek(this.config.resumeat);
        }
    };

    /**
     * @param {String} action data-action of the button
     * @param {Function} handler
     */
    Player.prototype.control = function(action, handler) {
        var button = this.root.querySelector('[data-action="' + action + '"]');
        if (button) {
            button.addEventListener('click', handler);
        }
    };

    Player.prototype.check = function() {
        if (this.current) {
            return;
        }
        var item = this.due();
        if (item) {
            this.backend.pause();
            this.show(item);
        }
    };

    Player.prototype.show = function(item) {
        this.current = item;
        this.root.setAttribute('data-state', 'question');
        this.root.setAttribute('data-questiontype', item.type);

        this.panel.querySelector('[data-region="questiontext"]').textContent =
            item.questiontext;
        this.panel.querySelector('[data-region="outcome"]').hidden = true;

        var choices = this.panel.querySelector('[data-region="choices"]');
        var typed = this.panel.querySelector('[data-region="typed"]');
        var submit = this.panel.querySelector('[data-action="submit"]');

        var input = this.panel.querySelector('[data-region="typedinput"]');

        choices.textContent = '';
        choices.hidden = (item.type !== 'choice' && item.type !== 'multichoice');
        typed.hidden = (item.type !== 'shorttext');
        submit.hidden = (item.type !== 'multichoice' && item.type !== 'shorttext');

        // The options are rebuilt every time, so they arrive enabled. These two
        // are not: answering disables them, and without this the submit button
        // stayed disabled for the whole rest of the video after the first
        // question — every later one displayed correctly and could not be sent.
        submit.disabled = false;
        input.disabled = false;
        input.value = '';

        this.panel.hidden = false;

        if (item.type === 'info') {
            this.acknowledge(item);
            return;
        }
        if (item.type === 'shorttext') {
            input.focus();
            return;
        }

        this.options(item);
    };

    /**
     * The option list, for the two types that have one.
     *
     * A single-answer question gets buttons — clicking one *is* the answer.
     * A multiple-answer question gets checkboxes, because there is no way to
     * click three things at once and every click cannot be a submission.
     *
     * @param {Object} item
     */
    Player.prototype.options = function(item) {
        var self = this;
        var choices = this.panel.querySelector('[data-region="choices"]');
        var single = (item.type === 'choice');

        item.choices.forEach(function(text, index) {
            if (single) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn btn-outline-primary text-start';
                button.textContent = text;
                button.setAttribute('data-action', 'choose');
                button.setAttribute('data-choice', index);
                button.addEventListener('click', function() {
                    self.answer(item, JSON.stringify([index]));
                });
                choices.appendChild(button);
                return;
            }

            var id = 'kaivideo-choice-' + index;
            var wrapper = document.createElement('div');
            wrapper.className = 'form-check text-start';

            var box = document.createElement('input');
            box.type = 'checkbox';
            box.className = 'form-check-input';
            box.id = id;
            box.setAttribute('data-action', 'choose');
            box.setAttribute('data-choice', index);

            var label = document.createElement('label');
            label.className = 'form-check-label';
            label.htmlFor = id;
            label.textContent = text;

            wrapper.appendChild(box);
            wrapper.appendChild(label);
            choices.appendChild(wrapper);
        });
    };

    /** Gather what is on screen and send it. */
    Player.prototype.submit = function() {
        var item = this.current;
        if (!item) {
            return;
        }

        if (item.type === 'shorttext') {
            var typed = this.panel.querySelector('[data-region="typedinput"]').value;
            // An empty box is not an answer. Sending it would record a wrong
            // answer they did not give and, where retries are off, spend their
            // only attempt on a mis-click.
            if (!typed.trim()) {
                return;
            }
            this.answer(item, typed);
            return;
        }

        var picked = [];
        Array.prototype.forEach.call(
            this.panel.querySelectorAll('[data-region="choices"] input:checked'),
            function(box) {
                picked.push(parseInt(box.getAttribute('data-choice'), 10));
            });
        if (!picked.length) {
            return;
        }
        this.answer(item, JSON.stringify(picked));
    };

    /**
     * An info card: shown, acknowledged, recorded, never marked.
     *
     * It goes through the same endpoint as an answer so that "they reached this
     * point" is a fact in the same table as everything else, rather than
     * something a report has to infer from the progress row.
     *
     * @param {Object} item
     */
    Player.prototype.acknowledge = function(item) {
        var self = this;

        Ajax.call([{
            methodname: 'mod_kaivideo_answer_item',
            args: {cmid: this.config.cmid, itemid: item.id, response: ''}
        }])[0].then(function(result) {
            self.answered[item.id] = {itemid: item.id, response: '', correct: true};
            return self.outcome(item, result);
        }).catch(function(error) {
            self.fail(null, (error && error.message) || null);
        });
    };

    /**
     * @param {Object} item
     * @param {String} response JSON option indexes, or the typed text
     */
    Player.prototype.answer = function(item, response) {
        var self = this;
        var fields = this.panel.querySelectorAll(
            '[data-region="choices"] button, [data-region="choices"] input,' +
            ' [data-region="typedinput"], [data-action="submit"]');
        var enable = function(state) {
            Array.prototype.forEach.call(fields, function(field) {
                field.disabled = state;
            });
        };

        enable(true);

        Ajax.call([{
            methodname: 'mod_kaivideo_answer_item',
            args: {cmid: this.config.cmid, itemid: item.id, response: response}
        }])[0].then(function(result) {
            self.answered[item.id] = {itemid: item.id, response: response,
                correct: result.correct};
            return self.outcome(item, result);
        }).catch(function(error) {
            // The answer did not reach the server, so it did not happen. Let
            // them try again rather than recording nothing and moving on.
            self.fail(null, (error && error.message) || null);
            enable(false);
        });
    };

    Player.prototype.outcome = function(item, result) {
        var self = this;
        var outcome = this.panel.querySelector('[data-region="outcome"]');
        var verdict = this.panel.querySelector('[data-region="verdict"]');
        var feedback = this.panel.querySelector('[data-region="feedback"]');
        var retry = this.panel.querySelector('[data-action="retry"]');

        this.panel.querySelector('[data-region="choices"]').hidden = true;
        this.panel.querySelector('[data-region="typed"]').hidden = true;
        this.panel.querySelector('[data-action="submit"]').hidden = true;

        feedback.textContent = result.feedback || '';

        // An info card has no verdict. "Correct" under a message that was only
        // ever meant to be read makes it look like a question they got right,
        // which is exactly the confusion the type exists to avoid.
        if (item.type === 'info') {
            this.root.setAttribute('data-state', 'info');
            verdict.textContent = '';
            retry.hidden = true;
            outcome.hidden = false;
            return Promise.resolve(this);
        }

        this.root.setAttribute('data-state', result.correct ? 'correct' : 'wrong');

        // A wrong answer is worth another go when the activity allows it: the
        // point of a question in the middle of a lesson is that they learn the
        // answer, not that they are caught out by it.
        retry.hidden = !(this.config.allowreview && !result.correct);

        // Withheld while a retry is still available — the server sends an empty
        // list in that case, and showing it would make the retry free.
        var expected = this.expected(item, result);
        if (expected) {
            feedback.textContent = expected +
                (result.feedback ? ' — ' + result.feedback : '');
        }

        return Str.get_string(result.correct ? 'correct' : 'wrong', 'mod_kaivideo')
            .then(function(text) {
                verdict.textContent = text;
                verdict.className = result.correct
                    ? 'mb-1 fw-bold text-success' : 'mb-1 fw-bold text-danger';
                outcome.hidden = false;
                return text;
            }).catch(function() {
                outcome.hidden = false;
                return null;
            }).then(function() {
                return self;
            });
    };

    /**
     * The right answer in words, when it is theirs to see.
     *
     * Only after a wrong answer with no retry left. Printing it under a correct
     * one is noise, and printing it while a retry is still on offer is handing
     * out the mark.
     *
     * @param {Object} item
     * @param {Object} result
     * @return {String}
     */
    Player.prototype.expected = function(item, result) {
        if (result.correct) {
            return '';
        }

        var answers = [];
        try {
            answers = JSON.parse(result.answers || '[]');
        } catch (error) {
            return '';
        }
        if (!answers.length) {
            return '';
        }

        if (item.type === 'shorttext') {
            return answers.join(' / ');
        }
        return answers.map(function(index) {
            return item.choices[index];
        }).filter(Boolean).join(' + ');
    };

    Player.prototype.dismiss = function() {
        this.panel.hidden = true;
        this.current = null;
        this.root.setAttribute('data-state', 'playing');

        // Another question may be due at the same moment, or the learner may
        // have seeked past several. check() finds the next one; if there is
        // none, play resumes.
        if (this.due()) {
            this.check();
            return;
        }
        this.backend.play();
    };

    /**
     * @param {String|null} stringkey
     * @param {String|null} literal
     */
    Player.prototype.fail = function(stringkey, literal) {
        var problem = this.root.querySelector('[data-region="problem"]');
        var show = function(text) {
            problem.textContent = text;
            problem.hidden = false;
        };

        if (literal) {
            show(literal);
            return;
        }
        Str.get_string(stringkey || 'error:playerfailed', 'mod_kaivideo')
            .then(show).catch(function() {
                show('error');
                return null;
            });
    };

    Player.prototype.maybeReport = function() {
        var now = Date.now();
        if (now - this.lastReported < PROGRESS_EVERY) {
            return;
        }
        this.lastReported = now;
        this.report(false);
    };

    Player.prototype.report = function(finished) {
        Ajax.call([{
            methodname: 'mod_kaivideo_record_progress',
            args: {
                cmid: this.config.cmid,
                seconds: this.backend.currentTime(),
                finished: !!finished
            }
        }])[0].catch(function() {
            // Progress is advisory — the grade comes from the answers — so a
            // failure here must not interrupt the lesson.
            return null;
        });
    };

    return {
        init: function(config) {
            new Player(config);
        }
    };
});
