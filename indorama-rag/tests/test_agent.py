"""The agent loop, with the model replaced by a script.

None of these start a model. What is being tested is the loop and the rules
around it — which tool results the guards see, what happens when nothing is
looked up, what gets replayed as history — and a real model would make each of
those non-deterministic without making any of them better tested.

Every case here comes from a run that went wrong first.
"""

import pytest

from app import agent, config, scope, tools


class FakeModel:
    """Plays back a scripted sequence of assistant messages."""

    def __init__(self, *replies):
        self.replies = list(replies)
        self.seen: list[list[dict]] = []

    def __call__(self, messages, tool_definitions, timeout=None):
        self.seen.append(list(messages))
        return self.replies.pop(0) if self.replies else {"content": "จบ"}


def _call(tool: str, **arguments):
    # The first parameter is "tool", not "name": get_table's own argument is
    # called name, and the two collided.
    import json
    return {"id": tool, "function": {"name": tool,
                                     "arguments": json.dumps(arguments)}}


@pytest.fixture
def model(monkeypatch):
    def install(*replies):
        fake = FakeModel(*replies)
        monkeypatch.setattr(agent.llm, "converse", fake)
        return fake
    return install


@pytest.fixture
def store(monkeypatch):
    """An index of two chunks, enough to be looked up or not found."""
    class Fake:
        def named(self, wanted):
            table = {"chunk_id": "t1", "kind": "table", "ref": "tbl_company",
                     "title": "ตาราง tbl_company",
                     "text": "ตาราง tbl_company\n  - com_mail nvarchar(200)\n"
                             "  - default_user_password nvarchar(255)"}
            digest = {"chunk_id": "d1", "kind": "digest",
                      "ref": "digest_sensitive_columns", "title": "รายการครบ",
                      "text": "มีทั้งหมด 26 ตาราง รวม 62 คอลัมน์"}
            found = {"tbl_company": table, "digest_sensitive_columns": digest}
            return [found[w] for w in wanted if w in found]

        def vocabulary(self):
            return {"tbl_company", "digest_sensitive_columns"}

        def search(self, *a, **k):
            return []
    return Fake()


VOCAB = {"tbl_company"}


# ---------- the gate ----------

def test_a_first_question_off_topic_never_reaches_the_model(store, model):
    fake = model()
    with pytest.raises(agent.Refusal) as caught:
        agent.answer(store, "วันนี้อากาศเป็นยังไง", [], VOCAB)
    assert caught.value.code == "off_topic"
    assert fake.seen == [], "the model was called for an off-topic first question"


def test_a_follow_up_that_names_nothing_is_allowed_through(store, model):
    """The gate cannot judge "แล้วมันมีคอลัมน์อ่อนไหวไหม" — it names nothing.

    So inside a conversation it opens, and grounding is what decides instead.
    """
    model({"tool_calls": [_call("get_table", name="tbl_company")]},
          {"content": "มี com_mail และ default_user_password"})
    turns = [{"role": "user", "text": "tbl_company คืออะไร"},
             {"role": "assistant", "text": "ตารางบริษัท"}]
    result = agent.answer(store, "แล้วมันมีคอลัมน์อ่อนไหวไหม", turns, VOCAB)
    assert result["sources"] == ["tbl_company"]


def test_an_off_topic_turn_inside_a_conversation_is_still_refused(store, model):
    """Nudged once, then refused — decided by whether a lookup happened.

    This is what replaced matching referential words, which classified
    "วันนี้อากาศเป็นยังไง" as a follow-up because "นี้" sits inside "วันนี้".
    """
    fake = model({"content": "วันนี้อากาศดีครับ"},
                 {"content": "วันนี้อากาศดีครับ"})
    turns = [{"role": "user", "text": "tbl_company คืออะไร"},
             {"role": "assistant", "text": "ตารางบริษัท"}]
    with pytest.raises(agent.Refusal) as caught:
        agent.answer(store, "วันนี้อากาศเป็นยังไง", turns, VOCAB)
    assert caught.value.code == "off_topic"
    assert len(fake.seen) == 2, "it should be nudged once before being refused"


def test_a_turn_answered_without_looking_anything_up_is_nudged_first(store, model):
    """Refusing straight away threw away three legitimate turns in the first run.

    A follow-up gets answered from what the model read a turn ago, which is
    honest and unverifiable here — nothing came back this turn for the guards
    to check. Asked again, it calls the tool and the answer becomes checkable.
    """
    fake = model({"content": "จำได้ว่ามีคอลัมน์อีเมลอยู่"},
                 {"tool_calls": [_call("get_table", name="tbl_company")]},
                 {"content": "ตารางนี้เก็บข้อมูลบริษัท รวมอีเมลติดต่อ"})
    turns = [{"role": "user", "text": "x"}, {"role": "assistant", "text": "y"}]
    result = agent.answer(store, "แล้วมันมีอะไรอีก", turns, VOCAB)
    assert result["sources"] == ["tbl_company"]
    assert len(fake.seen) == 3


# ---------- the guards, over accumulated material ----------

def test_a_name_no_tool_returned_is_refused(store, model):
    model({"tool_calls": [_call("get_table", name="tbl_company")]},
          {"content": "ดูที่ tbl_invented_name ด้วย"},
          {"content": "ดูที่ tbl_invented_name ด้วย"})
    with pytest.raises(agent.Refusal) as caught:
        agent.answer(store, "tbl_company มีอะไร", [], VOCAB)
    assert caught.value.code == "ungrounded_answer"
    assert "tbl_invented_name" in caught.value.message


def test_a_total_the_model_worked_out_is_refused(store, model):
    """The digest says 26. Anything else was counted rather than read."""
    model({"tool_calls": [_call("list_sensitive_tables")]},
          {"content": "รวม 22 ตาราง"},
          {"content": "รวม 22 ตาราง"})
    with pytest.raises(agent.Refusal) as caught:
        agent.answer(store, "ตารางไหนมีข้อมูลอ่อนไหว", [], {"tbl_company"})
    assert caught.value.code == "ungrounded_answer"
    assert "22" in caught.value.message


def test_the_total_from_the_tool_passes(store, model):
    model({"tool_calls": [_call("list_sensitive_tables")]},
          {"content": "มี 26 ตาราง"})
    result = agent.answer(store, "ตารางไหนมีข้อมูลอ่อนไหว", [], {"tbl_company"})
    assert "26" in result["answer"]
    assert result["lists"], "a complete list should come back whole for the caller"


def test_the_answer_must_read_as_plain_thai(store, model):
    """No technical names in the prose, invented or real.

    The stricter rule replaces the "was it in the material" check where it
    applies. The reader is shown the exact names beside the answer, taken from
    the chunks the tools returned, so a name the model never types is a name it
    cannot mistype — the same move the proctor's navigation assistant made when
    it stopped being shown any URLs.
    """
    model({"tool_calls": [_call("get_table", name="tbl_company")]},
          {"content": "ตาราง tbl_company มีคอลัมน์ com_mail"},
          {"content": "ตาราง tbl_company มีคอลัมน์ com_mail"})
    with pytest.raises(agent.Refusal) as caught:
        agent.answer(store, "เก็บอะไรบ้าง", [{"role": "user", "text": "x"}], VOCAB)
    assert caught.value.code == "ungrounded_answer"
    assert "com_mail" in caught.value.message


def test_an_answer_that_drifts_out_of_thai_is_refused(store, model):
    """qwen2.5 is a Chinese model and on a long turn it code-switches.

    Asked in Thai what a table holds, it opened in Thai and finished in
    Mandarin — figures correct, sentence unreadable to the person who asked.
    The prompt already said to answer in Thai; it said so before this happened.
    """
    model({"tool_calls": [_call("get_table", name="tbl_company")]},
          {"content": "ตารางนี้เก็บข้อมูลบริษัท 其中有 3 个敏感列"},
          {"content": "ตารางนี้เก็บข้อมูลบริษัท 其中有 3 个敏感列"})
    with pytest.raises(agent.Refusal) as caught:
        agent.answer(store, "tbl_company เก็บอะไร", [], VOCAB)
    assert caught.value.code == "ungrounded_answer"


def test_thai_with_english_terms_is_not_drift(store, model):
    """English technical words in a Thai sentence are normal here and must not
    trip the language check — only Han characters do."""
    from app import guard
    assert guard.wrong_language("ตารางนี้มี primary key และ index") == []


def test_prose_with_numbers_but_no_names_passes(store, model):
    """Numbers stay. They are what people act on, and each one is checked
    against the tool result that produced it."""
    model({"tool_calls": [_call("list_sensitive_tables")]},
          {"content": "มี 26 ตารางที่เก็บข้อมูลส่วนบุคคล รวม 62 คอลัมน์"})
    result = agent.answer(store, "ตารางไหนมีข้อมูลอ่อนไหว", [], {"tbl_company"})
    assert "26" in result["answer"]


def test_a_name_the_reader_used_may_be_said_back(store, model):
    """Somebody who asked about a table by name is told nothing new by hearing
    it. Refusing to repeat the subject of the question would read as evasion."""
    model({"tool_calls": [_call("get_table", name="tbl_company")]},
          {"content": "tbl_company เก็บข้อมูลบริษัท"})
    result = agent.answer(store, "tbl_company เก็บอะไร", [], VOCAB)
    assert "tbl_company" in result["answer"]


def test_every_chunk_comes_back_for_the_caller_to_render(store, model):
    """The names withheld from the prose have to be on the screen somewhere.

    Withholding a name only works if the reader can still get it exactly; a
    table chunk that never reaches the caller means the answer is prose and
    nothing else.
    """
    model({"tool_calls": [_call("get_table", name="tbl_company")]},
          {"content": "ตารางนี้เก็บข้อมูลบริษัท"})
    result = agent.answer(store, "tbl_company เก็บอะไร", [], VOCAB)
    assert [l["ref"] for l in result["lists"]] == ["tbl_company"]
    assert "com_mail" in result["lists"][0]["text"]


# ---------- history ----------

def test_a_refusal_is_never_replayed_to_the_model(store, model):
    """Feeding refusals back taught the model to refuse.

    After one "[off_topic] ..." landed in the history, the next three turns
    were refused as well, including one squarely in scope.
    """
    fake = model({"tool_calls": [_call("get_table", name="tbl_company")]},
                 {"content": "ok"})
    turns = [{"role": "user", "text": "อะไรสักอย่าง"},
             {"role": "assistant", "text": "[off_topic] this assistant only..."},
             {"role": "user", "text": "tbl_company"},
             {"role": "assistant", "text": "ตารางบริษัท"}]
    agent.answer(store, "tbl_company มีอะไร", turns, VOCAB)
    replayed = [m["content"] for m in fake.seen[0] if m["role"] == "assistant"]
    assert "ตารางบริษัท" in replayed
    assert not any(c.startswith("[off_topic]") for c in replayed)


def test_history_is_bounded(store, model, monkeypatch):
    monkeypatch.setattr(config, "HISTORY_TURNS", 2)
    fake = model({"tool_calls": [_call("get_table", name="tbl_company")]},
                 {"content": "ok"})
    turns = [{"role": "user", "text": f"เทิร์นที่ {n}"} for n in range(10)]
    agent.answer(store, "tbl_company", turns, VOCAB)
    replayed = [m for m in fake.seen[0] if m["role"] in ("user", "assistant")]
    # two history turns plus the question itself
    assert len(replayed) == 3


def test_a_loop_is_stopped_rather_than_left_running(store, model, monkeypatch):
    monkeypatch.setattr(config, "MAX_TOOL_CALLS", 2)
    model(*[{"tool_calls": [_call("get_table", name="tbl_company")]}] * 6)
    with pytest.raises(agent.Refusal) as caught:
        agent.answer(store, "tbl_company", [], VOCAB)
    assert caught.value.code == "tool_limit"


# ---------- tools ----------

def test_a_table_that_does_not_exist_says_so(store):
    result = tools.run(store, "get_table", {"name": "tbl_nope"})
    assert "NOT FOUND" in result.text
    assert result.chunks == []
    # Words the model can use, rather than a silence it will fill.
    assert "does not exist" in result.text or "no table" in result.text


def test_an_unknown_tool_is_refused_not_guessed_at(store):
    result = tools.run(store, "drop_everything", {})
    assert "NOT FOUND" in result.text
    assert "get_table" in result.text


def test_search_applies_the_same_scope_rule(store, monkeypatch):
    """A tool is not a way around the gate.

    An agent that searched for "the weather" would otherwise pull whatever
    ranked least badly into its own context and answer from that.
    """
    result = tools.run(store, "search", {"query": "วันนี้อากาศเป็นยังไง"})
    assert result.chunks == []
    assert "NOT FOUND" in result.text
