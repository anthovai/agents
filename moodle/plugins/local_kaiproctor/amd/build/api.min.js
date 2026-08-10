// Thin wrapper over the plugin's web service functions.
//
// The face-re prototype called a bare FastAPI service with fetch(). Inside
// Moodle every call goes through core/ajax so that it carries the session and
// sesskey, and so that a logged-out learner cannot keep a monitor running.
//
// Written in plain AMD rather than ES6 modules: this plugin has no node
// toolchain, and tools/build-amd.sh copies src to build verbatim. Run
// `grunt amd` instead once a toolchain is available.
define(['core/ajax'], function(Ajax) {

    /**
     * Read a Blob as base64 without the data: prefix.
     *
     * @param {Blob} blob
     * @return {Promise<string>}
     */
    var blobToBase64 = function(blob) {
        return new Promise(function(resolve, reject) {
            var reader = new FileReader();
            reader.onloadend = function() {
                var result = String(reader.result);
                var comma = result.indexOf(',');
                resolve(comma >= 0 ? result.slice(comma + 1) : result);
            };
            reader.onerror = reject;
            reader.readAsDataURL(blob);
        });
    };

    var call = function(methodname, args) {
        return Ajax.call([{methodname: methodname, args: args}])[0];
    };

    return {
        blobToBase64: blobToBase64,

        /**
         * Open a sitting and get back the rules that will be enforced.
         *
         * The policy comes from the server rather than being read from the
         * page: the snapshot recorded against the sitting has to come from
         * the same place the enforcement does, or it proves nothing.
         *
         * @param {Number} contextid
         * @param {Number} attemptid 0 when not in a quiz
         * @return {Promise<Object>}
         */
        startSession: function(contextid, attemptid) {
            return call('local_kaiproctor_start_session', {
                contextid: contextid,
                attemptid: attemptid || 0
            });
        },

        /**
         * Close a sitting.
         *
         * @param {Number} sessionid
         * @param {String} status 'completed' or 'terminated'
         * @param {String} reason
         * @return {Promise<Object>}
         */
        endSession: function(sessionid, status, reason) {
            return call('local_kaiproctor_end_session', {
                sessionid: sessionid,
                status: status,
                reason: reason || ''
            });
        },

        /**
         * Presence, head pose and liveness for one frame.
         *
         * @param {Blob} blob
         * @return {Promise<Object>}
         */
        analyze: function(blob) {
            return blobToBase64(blob).then(function(data) {
                return call('local_kaiproctor_analyze_frame', {imagedata: data});
            });
        },

        /**
         * Store the learner's reference face.
         *
         * @param {Blob} blob the frame taken while looking straight ahead
         * @param {Object} challenge the liveness challenge record
         * @return {Promise<Object>}
         */
        enrolFace: function(blob, challenge) {
            return blobToBase64(blob).then(function(data) {
                return call('local_kaiproctor_enrol_face', {
                    imagedata: data,
                    challenge: JSON.stringify(challenge)
                });
            });
        },

        /**
         * Check a live frame against the enrolled face.
         *
         * @param {Number} contextid
         * @param {Blob} blob
         * @param {Number} attemptid 0 when not in a quiz
         * @param {Boolean} storeevidence
         * @return {Promise<Object>}
         */
        verify: function(contextid, blob, attemptid, storeevidence, sessionid) {
            return blobToBase64(blob).then(function(data) {
                return call('local_kaiproctor_verify_frame', {
                    contextid: contextid,
                    imagedata: data,
                    attemptid: attemptid || 0,
                    storeevidence: !!storeevidence,
                    sessionid: sessionid || 0
                });
            });
        },

        /**
         * Record an attention signal.
         *
         * @param {Number} contextid
         * @param {String} type
         * @param {Object} detail
         * @param {Number|null} videotime
         * @return {Promise<Object>}
         */
        logEvent: function(contextid, type, detail, videotime, sessionid) {
            return call('local_kaiproctor_log_event', {
                contextid: contextid,
                type: type,
                detail: JSON.stringify(detail || {}),
                videotime: (videotime === null || videotime === undefined) ? -1 : videotime,
                sessionid: sessionid || 0
            });
        },

        /**
         * Upload a snapshot or clip.
         *
         * @param {Number} contextid
         * @param {String} kind 'snapshot' or 'clip'
         * @param {String} reason
         * @param {Blob} blob
         * @param {Number} attemptid
         * @return {Promise<Object>}
         */
        storeEvidence: function(contextid, kind, reason, blob, attemptid, sessionid) {
            return blobToBase64(blob).then(function(data) {
                return call('local_kaiproctor_store_evidence', {
                    contextid: contextid,
                    kind: kind,
                    reason: reason,
                    data: data,
                    attemptid: attemptid || 0,
                    sessionid: sessionid || 0
                });
            });
        }
    };
});
