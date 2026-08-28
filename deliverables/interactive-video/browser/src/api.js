// A reference implementation of the four calls the player makes.
//
// **This talks to YOUR backend, not to the interactive video service.** That
// is the whole point of it being a separate file you are expected to adapt.
//
// The service takes a `user_id` on trust and marks answers against it, so a
// key that reaches the browser is a key somebody can answer with as anybody —
// and the admin key would hand over the marking scheme outright. The browser
// therefore calls endpoints of yours, your server adds the key and forwards,
// and your server is where the identity of the person comes from rather than
// from a field the page filled in.
//
// The four calls and what each must return:
//
//   load()                          -> {ok, video, timeline, answered, resume_at}
//        Proxy of GET /videos/{id}/play. The timeline it returns carries no
//        correct answers; do not add them on the way through.
//
//   answer(itemId, response)        -> {ok, correct, answers, feedback, may_retry}
//        Proxy of POST /videos/{id}/answer.
//
//   progress(seconds, finished)     -> Promise
//        Proxy of POST /videos/{id}/progress. The resolved value is ignored.
//
//   beacon(seconds, finished)       -> Boolean
//        The same report, sent so that it survives the page closing. Return
//        false if you cannot, and the player falls back to progress().
//
// Every call must return a Promise (except beacon) and must not throw
// synchronously.
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else {
        root.InteractiveVideo = root.InteractiveVideo || {};
        root.InteractiveVideo.createApi = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {

    /**
     * @param {Object} opts
     * @param {String} opts.baseUrl where your own endpoints live, e.g. '/api/video'
     * @param {Object} [opts.headers] added to every request — a CSRF token, or
     *        whatever your stack needs. NOT the service key.
     * @return {Object} the api object Player expects
     */
    return function createApi(opts) {
        var base = String(opts.baseUrl || '').replace(/\/+$/, '');
        var extra = opts.headers || {};

        var send = function (path, body) {
            var headers = {'Content-Type': 'application/json'};
            Object.keys(extra).forEach(function (name) {
                headers[name] = extra[name];
            });
            return fetch(base + path, {
                method: body ? 'POST' : 'GET',
                credentials: 'same-origin',
                headers: headers,
                body: body ? JSON.stringify(body) : undefined
            }).then(function (response) {
                // A non-200 is still an answer the player can act on as long
                // as it is shaped like one. Rejecting instead would turn a
                // server error into an unhandled rejection at each call site.
                return response.json().catch(function () {
                    return {ok: false, error: {code: 'http_' + response.status}};
                });
            });
        };

        return {
            load: function () {
                return send('/play');
            },

            answer: function (itemId, response) {
                return send('/answer', {item_id: itemId, response: response});
            },

            progress: function (seconds, finished) {
                return send('/progress', {seconds: seconds, finished: !!finished});
            },

            beacon: function (seconds, finished) {
                // sendBeacon survives the page going away, which an ordinary
                // request does not: a learner who watched to 28s and closed
                // the tab would otherwise resume at whatever the last timed
                // report had written.
                if (!navigator.sendBeacon) {
                    return false;
                }
                var payload = JSON.stringify({seconds: seconds,
                                              finished: !!finished});
                // text/plain rather than application/json on purpose: a JSON
                // content type makes this a non-simple request, which needs a
                // CORS preflight, and a preflight cannot be sent from a page
                // that is unloading. Parse it as JSON on the server.
                return navigator.sendBeacon(
                    base + '/progress',
                    new Blob([payload], {type: 'text/plain;charset=UTF-8'}));
            }
        };
    };
}));
