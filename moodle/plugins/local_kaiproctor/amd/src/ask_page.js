// The navigation assistant's page.
//
// Deliberately thin: everything that decides anything — which pages this
// learner may see, whether the question matched one, whether the answer's
// links are real — happens on the server. The browser types a question and
// renders a reply.
define([
    'core/ajax',
    'core/str'
], function(Ajax, Str) {

    /**
     * Render the pages the answer was built from, as links.
     *
     * Taken from the returned sources rather than parsed out of the answer:
     * the model is checked for inventing links, but a learner should not have
     * to pick a URL out of a paragraph either way.
     *
     * @param {Element} root
     * @param {Array} sources
     */
    function showSources(root, sources) {
        var wrap = root.querySelector('[data-region="sources-wrap"]');
        var list = root.querySelector('[data-region="sources"]');
        list.textContent = '';

        if (!sources || !sources.length) {
            wrap.hidden = true;
            return;
        }

        sources.forEach(function(source) {
            var item = document.createElement('li');
            var link = document.createElement('a');
            link.href = source.url;
            link.textContent = source.title;
            item.appendChild(link);
            list.appendChild(item);
        });
        wrap.hidden = false;
    }

    return {
        init: function() {
            var root = document.querySelector('[data-region="ask"]');
            if (!root) {
                return;
            }

            var form = root.querySelector('[data-action="ask-form"]');
            var input = root.querySelector('[data-region="question"]');
            var answer = root.querySelector('[data-region="answer"]');
            var answerText = root.querySelector('[data-region="answer-text"]');
            var problem = root.querySelector('[data-region="problem"]');
            var button = root.querySelector('[data-action="send"]');
            var idle = button.textContent;

            function fail(message) {
                // Always with a reason. An assistant that fails silently
                // leaves the learner unsure whether they asked badly or the
                // thing is broken.
                problem.textContent = message;
                problem.hidden = false;
            }

            function done() {
                button.disabled = false;
                button.textContent = idle;
                root.setAttribute('data-state', 'ready');
            }

            form.addEventListener('submit', function(event) {
                event.preventDefault();

                var question = input.value.trim();
                if (!question) {
                    return;
                }

                problem.hidden = true;
                answer.hidden = true;
                button.disabled = true;
                root.setAttribute('data-state', 'asking');

                Str.get_strings([
                    {key: 'ask:thinking', component: 'local_kaiproctor'},
                    {key: 'error:generic', component: 'local_kaiproctor'}
                ]).then(function(strings) {
                    button.textContent = strings[0];

                    return Ajax.call([{
                        methodname: 'local_kaiproctor_ask',
                        args: {question: question}
                    }])[0].then(function(result) {
                        if (result.ok) {
                            answerText.textContent = result.answer;
                            showSources(root, result.sources);
                            answer.hidden = false;
                        } else {
                            fail(result.message || strings[1]);
                        }
                        return result;
                    }).catch(function(error) {
                        fail((error && error.message) || strings[1]);
                    });
                }).catch(function(error) {
                    fail((error && error.message) || 'error');
                }).then(done).catch(done);
            });
        }
    };
});
