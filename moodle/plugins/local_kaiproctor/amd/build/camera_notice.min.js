// The notice a learner sees immediately before the camera is switched on.
//
// This is not the site policy. That one is agreed to once, covers the whole
// site, and is what makes "nothing was collected before consent" true — it
// stays exactly as it is. This is the second layer: at the moment the camera
// is about to open, the learner is told what is about to be captured, what is
// kept, and for how long, and has to say yes before anything is recorded.
//
// Asked every time rather than remembered. A consent that was given months ago
// on a different page is a legal record; it is not somebody knowing that their
// camera is about to come on right now.
//
// The acknowledgement is sent to the server before the camera starts, so a
// dispute has a record of it that does not depend on the browser being honest.
define([
    'core/modal_save_cancel',
    'core/modal_events',
    'core/str',
    'core/notification',
    'local_kaiproctor/api'
], function(ModalSaveCancel, ModalEvents, Str, Notification, Api) {

    /**
     * Ask, and resolve only if they agree.
     *
     * @param {Number} contextid where to record the acknowledgement
     * @param {String} purpose 'enrol' or 'verify' — which wording to use
     * @param {Number} retentiondays how long evidence is kept, from the
     *        setting the purge task actually runs on, so the notice cannot
     *        promise a period nobody enforces
     * @return {Promise<Boolean>} true if they agreed, false if they declined
     */
    var ask = function(contextid, purpose, retentiondays) {
        return Str.get_strings([
            {key: 'notice:title', component: 'local_kaiproctor'},
            {key: 'notice:' + purpose, component: 'local_kaiproctor',
                param: retentiondays},
            {key: 'notice:agree', component: 'local_kaiproctor'},
            {key: 'notice:decline', component: 'local_kaiproctor'}
        ]).then(function(strings) {
            return ModalSaveCancel.create({
                title: strings[0],
                body: strings[1],
                buttons: {save: strings[2], cancel: strings[3]},
                removeOnClose: true
            });
        }).then(function(modal) {
            return new Promise(function(resolve) {
                modal.getRoot().on(ModalEvents.save, function() {
                    // Recorded before the camera opens, not after: the point
                    // of the record is that it came first.
                    Api.logEvent(contextid, 'camera_notice_accepted',
                        {purpose: purpose}, null, 0).catch(function() {
                        // Losing the line must not block a learner who agreed.
                        return null;
                    });
                    resolve(true);
                });
                modal.getRoot().on(ModalEvents.hidden, function() {
                    // Covers cancel, Escape and the close button alike: the
                    // only way to true is the button that says yes.
                    resolve(false);
                });
                modal.show();
                return modal;
            });
        }).catch(function(error) {
            Notification.exception(error);
            // A notice that could not be shown is not a notice that was
            // agreed to.
            return false;
        });
    };

    return {ask: ask};
});
