// The words the viewer actually reads.
//
// Kept apart from the logic so that changing what somebody is told does not
// mean editing the module that decides when to tell them. Pass your own object
// to Player; anything you leave out falls back to the key itself, which reads
// as a missing translation rather than as a blank panel.
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else {
        root.InteractiveVideo = root.InteractiveVideo || {};
        root.InteractiveVideo.strings = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {

    var th = {
        'correct': 'ถูกต้อง',
        'wrong': 'ยังไม่ถูก',
        'submit': 'ส่งคำตอบ',
        'continue': 'ดูต่อ',
        'retry': 'ลองใหม่',
        'answer:expected': 'คำตอบที่ถูกคือ',
        'play': 'เล่น',
        'pause': 'หยุด',
        'back': 'ถอยหลัง 10 วินาที',

        // Failures the viewer can see. Each names what went wrong, because
        // "โหลดวิดีโอไม่สำเร็จ" sends somebody to check their connection when
        // the real answer is that the Vimeo video does not allow this site —
        // which is somebody else's setting and unfindable from a generic
        // message.
        'error:player': 'เปิดวิดีโอไม่สำเร็จ',
        'error:vimeo': 'เปิดวิดีโอ Vimeo ไม่ได้ — วิดีโออาจไม่อนุญาตให้ฝังบนเว็บนี้',
        'error:stream': 'เปิดสตรีมไม่ได้ — เบราว์เซอร์นี้อาจไม่รองรับ',
        'error:youtube': 'เปิดวิดีโอ YouTube ไม่ได้',
        'error:answer': 'ส่งคำตอบไม่สำเร็จ ลองอีกครั้ง'
    };

    var en = {
        'correct': 'Correct',
        'wrong': 'Not quite',
        'submit': 'Submit',
        'continue': 'Continue',
        'retry': 'Try again',
        'answer:expected': 'The correct answer is',
        'play': 'Play',
        'pause': 'Pause',
        'back': 'Back 10 seconds',

        'error:player': 'The video could not be started.',
        'error:vimeo': 'This Vimeo video would not load — it may not allow being embedded here.',
        'error:stream': 'The stream would not play — this browser may not support it.',
        'error:youtube': 'This YouTube video would not load.',
        'error:answer': 'The answer did not reach the server. Try again.'
    };

    return {th: th, en: en};
}));
