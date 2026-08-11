// The interactive part of an interactive video.
//
// The whole module is about one decision: when is a question due? Watching for
// the playhead to *cross* a timestamp is the obvious answer and the wrong one,
// because a learner can drag the seek bar straight past it. So a question is
// due whenever the playhead is at or beyond it and it has not been answered —
// which makes seeking, resuming and ordinary playback the same case, and
// leaves no route around a question that "must be answered".
//
// Nothing here decides whether an answer was right. The timeline arrives
// without correct answers in it; the server says what happened, afterwards.
define([
    'core/ajax',
    'core/str'
], function(Ajax, Str) {

    /** Close enough to "now" — timeupdate fires about four times a second. */
    var EPSILON = 0.25;

    /** How often to tell the server where we are. Often enough to be useful
     *  after a browser crash, rare enough not to be chatty. */
    var PROGRESS_EVERY = 15000;

    var Player = function(config) {
        this.config = config;
        this.root = document.querySelector('[data-region="kaivideo"]');
        if (!this.root) {
            return;
        }

        this.video = this.root.querySelector('[data-region="video"]');
        this.panel = this.root.querySelector('[data-region="question"]');
        this.answered = {};
        this.current = null;
        this.lastReported = 0;

        (config.answered || []).forEach(function(row) {
            this.answered[row.itemid] = row;
        }.bind(this));

        this.attach();
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
        var now = this.video.currentTime + EPSILON;
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

        this.video.addEventListener('timeupdate', function() {
            self.check();
            self.maybeReport();
        });

        // Covers the seek bar and the resume jump: both change currentTime
        // without any playing having happened.
        this.video.addEventListener('seeked', function() {
            self.check();
        });

        this.video.addEventListener('play', function() {
            // A question that is due must not be playable past. Pausing on
            // play looks abrupt, and is the point.
            if (self.config.mustanswer && self.due()) {
                self.check();
            }
        });

        this.video.addEventListener('ended', function() {
            self.report(true);
        });

        this.panel.querySelector('[data-action="continue"]')
            .addEventListener('click', function() {
                self.dismiss();
            });

        this.panel.querySelector('[data-action="retry"]')
            .addEventListener('click', function() {
                self.show(self.current);
            });

        if (this.config.resumeat > 5 && this.config.resumeat < this.video.duration) {
            this.video.currentTime = this.config.resumeat;
        } else if (this.config.resumeat > 5) {
            // duration is not known until metadata loads.
            this.video.addEventListener('loadedmetadata', function() {
                if (self.config.resumeat < self.video.duration) {
                    self.video.currentTime = self.config.resumeat;
                }
            }, {once: true});
        }
    };

    Player.prototype.check = function() {
        if (this.current) {
            return;
        }
        var item = this.due();
        if (item) {
            this.video.pause();
            this.show(item);
        }
    };

    Player.prototype.show = function(item) {
        var self = this;
        this.current = item;
        this.root.setAttribute('data-state', 'question');

        this.panel.querySelector('[data-region="questiontext"]').textContent =
            item.questiontext;
        this.panel.querySelector('[data-region="outcome"]').hidden = true;

        var choices = this.panel.querySelector('[data-region="choices"]');
        choices.textContent = '';
        choices.hidden = false;

        item.choices.forEach(function(text, index) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-outline-primary text-start';
            button.textContent = text;
            button.setAttribute('data-action', 'choose');
            button.setAttribute('data-choice', index);
            button.addEventListener('click', function() {
                self.answer(item, index);
            });
            choices.appendChild(button);
        });

        this.panel.hidden = false;
    };

    Player.prototype.answer = function(item, choice) {
        var self = this;
        var choices = this.panel.querySelector('[data-region="choices"]');

        Array.prototype.forEach.call(choices.querySelectorAll('button'), function(button) {
            button.disabled = true;
        });

        Ajax.call([{
            methodname: 'mod_kaivideo_answer_item',
            args: {cmid: this.config.cmid, itemid: item.id, choice: choice}
        }])[0].then(function(result) {
            self.answered[item.id] = {itemid: item.id, choice: choice,
                correct: result.correct};
            return self.outcome(item, result);
        }).catch(function(error) {
            // The answer did not reach the server, so it did not happen. Let
            // them try again rather than recording nothing and moving on.
            var problem = self.root.querySelector('[data-region="problem"]');
            problem.textContent = (error && error.message) || 'error';
            problem.hidden = false;
            Array.prototype.forEach.call(choices.querySelectorAll('button'),
                function(button) {
                    button.disabled = false;
                });
        });
    };

    Player.prototype.outcome = function(item, result) {
        var self = this;
        var outcome = this.panel.querySelector('[data-region="outcome"]');
        var verdict = this.panel.querySelector('[data-region="verdict"]');
        var feedback = this.panel.querySelector('[data-region="feedback"]');
        var retry = this.panel.querySelector('[data-action="retry"]');

        this.panel.querySelector('[data-region="choices"]').hidden = true;
        this.root.setAttribute('data-state', result.correct ? 'correct' : 'wrong');

        feedback.textContent = result.feedback || '';

        // A wrong answer is worth another go when the activity allows it: the
        // point of a question in the middle of a lesson is that they learn the
        // answer, not that they are caught out by it.
        retry.hidden = !(this.config.allowreview && !result.correct);

        return Str.get_string(result.correct ? 'correct' : 'wrong', 'mod_kaivideo')
            .then(function(text) {
                verdict.textContent = text;
                verdict.className = result.correct ? 'mb-1 text-success' : 'mb-1 text-danger';
                outcome.hidden = false;
                return text;
            }).catch(function() {
                outcome.hidden = false;
                return '';
            }).then(function() {
                return self;
            });
    };

    Player.prototype.dismiss = function() {
        this.panel.hidden = true;
        this.current = null;
        this.root.setAttribute('data-state', 'playing');

        // Another question may be due at the same moment, or the learner may
        // have seeked past several. check() finds the next one; if there is
        // none, play resumes.
        var next = this.due();
        if (next) {
            this.check();
            return;
        }
        this.video.play().catch(function() {
            // Autoplay refused after an interaction is unusual but harmless:
            // the controls are right there.
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
                seconds: this.video.currentTime,
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
