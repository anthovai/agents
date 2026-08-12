// The assistant panel that opens from any page.
//
// Same web service as the full page, same server-side rules: retrieval decides
// what may be sent, and a question that matches nothing never reaches a model.
// Nothing about being in a corner of the screen changes what it is allowed to
// answer.
//
// Kept deliberately plain. This runs on every page in the site, so it loads no
// dependencies beyond what it uses and renders nothing until somebody opens it.
define([
    'core/ajax',
    'core/str'
], function(Ajax, Str) {

    /**
     * @param {Element} root
     * @param {String} text
     * @param {String} kind 'question' or 'answer' or 'problem'
     * @return {Element} the appended element
     */
    var say = function(root, text, kind) {
        var conversation = root.querySelector('[data-region="conversation"]');
        var intro = conversation.querySelector('[data-region="intro"]');
        if (intro) {
            intro.remove();
        }

        var line = document.createElement('p');
        line.className = kind === 'question'
            ? 'small fw-bold mb-1' : 'small mb-3';
        if (kind === 'problem') {
            line.className = 'small text-danger mb-3';
        }
        line.setAttribute('data-line', kind);
        line.textContent = text;
        conversation.appendChild(line);
        conversation.scrollTop = conversation.scrollHeight;
        return line;
    };

    /**
     * Render the pages an answer came from, as links.
     *
     * From the returned sources rather than parsed out of the prose: the
     * service already refuses answers containing links it did not supply, and
     * a learner should not have to pick a URL out of a paragraph either way.
     *
     * @param {Element} root
     * @param {Array} sources
     */
    var showSources = function(root, sources) {
        if (!sources || !sources.length) {
            return;
        }
        var conversation = root.querySelector('[data-region="conversation"]');
        var list = document.createElement('ul');
        list.className = 'small mb-3 ps-3';
        list.setAttribute('data-region', 'sources');

        sources.forEach(function(source) {
            var item = document.createElement('li');
            var link = document.createElement('a');
            link.href = source.url;
            link.textContent = source.title;
            item.appendChild(link);
            list.appendChild(item);
        });
        conversation.appendChild(list);
        conversation.scrollTop = conversation.scrollHeight;
    };

    return {
        init: function() {
            var root = document.querySelector('[data-region="assistant-widget"]');
            if (!root) {
                return;
            }

            var panel = root.querySelector('[data-region="assistant-panel"]');
            var toggle = root.querySelector('[data-action="assistant-toggle"]');
            var input = root.querySelector('[data-region="question"]');
            var send = root.querySelector('[data-action="assistant-send"]');

            var setOpen = function(open) {
                panel.hidden = !open;
                root.setAttribute('data-open', open ? '1' : '0');
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (open) {
                    input.focus();
                }
            };

            toggle.addEventListener('click', function() {
                setOpen(panel.hidden);
            });
            root.querySelector('[data-action="assistant-close"]')
                .addEventListener('click', function() {
                    setOpen(false);
                });

            // Escape closes it. A panel that covers part of the page and can
            // only be dismissed by finding its small close button is a panel
            // people learn to resent.
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape' && !panel.hidden) {
                    setOpen(false);
                    toggle.focus();
                }
            });

            root.querySelector('[data-action="assistant-form"]')
                .addEventListener('submit', function(event) {
                    event.preventDefault();

                    var question = input.value.trim();
                    if (!question) {
                        return;
                    }

                    say(root, question, 'question');
                    input.value = '';
                    send.disabled = true;
                    root.setAttribute('data-state', 'asking');

                    Str.get_strings([
                        {key: 'ask:thinking', component: 'local_kaiproctor'},
                        {key: 'error:generic', component: 'local_kaiproctor'}
                    ]).then(function(strings) {
                        var waiting = say(root, strings[0], 'answer');

                        return Ajax.call([{
                            methodname: 'local_kaiproctor_ask',
                            args: {question: question}
                        }])[0].then(function(result) {
                            waiting.remove();
                            if (result.ok) {
                                say(root, result.answer, 'answer');
                                showSources(root, result.sources);
                            } else {
                                // Always with a reason. Going quiet leaves the
                                // learner unsure whether they asked badly or
                                // the thing is broken.
                                say(root, result.message || strings[1], 'problem');
                            }
                            return result;
                        }).catch(function(error) {
                            waiting.remove();
                            say(root, (error && error.message) || strings[1], 'problem');
                        });
                    }).catch(function() {
                        say(root, 'error', 'problem');
                    }).then(function() {
                        send.disabled = false;
                        root.setAttribute('data-state', 'ready');
                        return null;
                    }).catch(function() {
                        send.disabled = false;
                        return null;
                    });
                });
        }
    };
});
