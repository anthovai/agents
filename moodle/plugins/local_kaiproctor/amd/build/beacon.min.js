// A web service call that survives the page closing.
//
// An ordinary request issued from pagehide is cancelled along with the
// document, so anything that has to be told "the learner is leaving now"
// cannot use one. navigator.sendBeacon hands the request to the browser to
// deliver afterwards; there is no response to read, which is the trade.
//
// Two callers need this and both are about the moment somebody leaves: the
// video module flushing how far they watched, and the proctoring monitor
// closing the sitting. It lives here, once, because the shape of Moodle's
// AJAX endpoint is the sort of detail that gets fixed in one copy and not the
// other.
define([], function() {

    return {
        /**
         * Post a web service call that will outlive this page.
         *
         * Fire and forget by design — a caller that needs to know the server
         * agreed should not be using this.
         *
         * @param {String} methodname external function name
         * @param {Object} args
         * @return {Boolean} whether the browser accepted it for delivery
         */
        send: function(methodname, args) {
            if (!navigator.sendBeacon || !window.M || !M.cfg) {
                return false;
            }

            var url = M.cfg.wwwroot + '/lib/ajax/service.php?sesskey='
                + encodeURIComponent(M.cfg.sesskey)
                + '&info=' + encodeURIComponent(methodname);
            var body = JSON.stringify([{
                index: 0,
                methodname: methodname,
                args: args
            }]);

            try {
                return navigator.sendBeacon(url,
                    new Blob([body], {type: 'application/json'}));
            } catch (error) {
                return false;
            }
        }
    };
});
