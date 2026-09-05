"""Recompute the learner scope threshold, and write down what it separates.

    python bench_learner.py --index learner-index.sqlite

The number in app/learner_scope.MIN_RUN is not a choice, it is a measurement,
and a measurement nobody can reproduce is a choice with better presentation.
This script prints the two distributions it sits between and says whether they
still separate.

The question set below is small and was written by whoever built the index,
which is the weakest thing about it. Replace it with questions real learners
typed as soon as there are some: fourteen questions cannot resolve better than
"there is a gap roughly here", and a threshold tuned on questions we invented
is tuned on how we imagine people ask.
"""

import argparse
import json
import sys

from app import learner_scope, store as store_mod

# Questions the catalogue should answer.
ON_TOPIC = [
    "มีหลักสูตรเกี่ยวกับการช่วยชีวิตฉุกเฉินไหม",
    "หลักสูตรความปลอดภัยผู้ป่วยใช้เวลานานแค่ไหน",
    "ในระบบมีทั้งหมดกี่หลักสูตร",
    "จะดูหลักสูตรที่ได้รับมอบหมายได้ที่ไหน",
    "อยากเรียนเรื่องการเงิน",
    "มีหลักสูตรเรื่องการสื่อสารไหม",
    "ดูเพลย์ลิสต์ของฉันที่ไหน",
    "หลักสูตรบังคับมีอะไรบ้าง",
    "หลักสูตรไหนสอนเรื่องภาวะผู้นำ",
    "ศูนย์ทรัพยากรอยู่ตรงไหน",
]

# Questions it must refuse. Not nonsense — things a person might plausibly
# type into a chat box that happens to be on a learning site, which is the
# case a gate has to survive.
OFF_TOPIC = [
    "เมืองหลวงของฝรั่งเศสคืออะไร",
    "วันนี้อากาศเป็นยังไง",
    "ราคาทองวันนี้เท่าไหร่",
    "สวัสดีครับ สบายดีไหม",
    "2+2 เท่ากับเท่าไหร่",
    "ช่วยสรุปข่าวเมื่อวานให้หน่อย",
    "แนะนำร้านอาหารแถวนี้",
]


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--index", default="learner-index.sqlite")
    parser.add_argument("--json", default=None)
    args = parser.parse_args()

    store = store_mod.Store(args.index)
    corpus = learner_scope.corpus_text(store)

    def measure(questions):
        rows = []
        for question in questions:
            match = learner_scope.longest_shared(question, corpus)
            rows.append({"question": question, "match": match,
                         "length": len(match)})
        return rows

    on = measure(ON_TOPIC)
    off = measure(OFF_TOPIC)

    print("ตรงประเด็น — ต้องผ่าน")
    for row in on:
        print(f"  {row['length']:3}  {row['match'][:28]:30}  {row['question']}")
    print("\nนอกเรื่อง — ต้องถูกปฏิเสธ")
    for row in off:
        print(f"  {row['length']:3}  {row['match'][:28]:30}  {row['question']}")

    on_min = min(r["length"] for r in on)
    off_max = max(r["length"] for r in off)
    print(f"\nตรงประเด็น สั้นสุด = {on_min}")
    print(f"นอกเรื่อง  ยาวสุด  = {off_max}")
    print(f"เกณฑ์ที่ตั้งไว้    = {learner_scope.MIN_RUN}")

    separated = off_max < on_min
    if separated:
        print(f"\nแยกกันได้ ช่องว่าง {on_min - off_max} ตัวอักษร "
              f"({off_max} < {learner_scope.MIN_RUN} <= {on_min})")
    else:
        # Stated rather than smoothed over. A threshold that does not separate
        # its own test set is not a threshold, and shipping one because the
        # script still exits cleanly is how a gate becomes decorative.
        print("\nไม่แยกกัน — ค่านี้ใช้ไม่ได้กับชุดคำถามนี้")
        print("  มีคำถามนอกเรื่องที่ตรงกับ corpus ยาวกว่าคำถามตรงประเด็นบางข้อ")

    wrong = ([r for r in on if r["length"] < learner_scope.MIN_RUN]
             + [r for r in off if r["length"] >= learner_scope.MIN_RUN])
    if wrong:
        print("\nตัดสินผิดที่เกณฑ์ปัจจุบัน:")
        for row in wrong:
            print(f"  {row['length']:3}  {row['question']}")

    if args.json:
        with open(args.json, "w", encoding="utf-8") as handle:
            json.dump({"on_topic": on, "off_topic": off,
                       "on_min": on_min, "off_max": off_max,
                       "min_run": learner_scope.MIN_RUN,
                       "separated": separated}, handle,
                      ensure_ascii=False, indent=2)

    store.close()
    return 0 if separated and not wrong else 1


if __name__ == "__main__":
    sys.exit(main())
