"""The agent: several lookups and a conversation, under the same guards.

What changes when a single question becomes a conversation with tools is not
the model — it is that three of the four guards were written against a single
turn, and each of them breaks in a different way if carried over unchanged.

**Scope.** A follow-up carries no subject. "แล้วมันผูกกับตารางไหน" names
nothing, so the gate that keeps the weather out would refuse it too. The gate
therefore applies in full only to a first question; inside a conversation it
opens, and the turn has to have looked something up before it may answer. What
was tried first, and why matching referential words was the wrong instrument,
is in :func:`assess_turn`.

**What the answer may name.** With one question there was one retrieval to
check against. Here the material is whatever the tools returned this turn,
which the agent accumulates as it goes, plus what the assistant already said
earlier in this conversation — those turns passed the same checks when they
were written, and refusing to let the model refer back to its own verified
answer would make a conversation impossible.

**Numbers.** Same rule, over the same accumulated material.

The fourth guard, the shape of an identifier, needed no changes at all.
"""

from . import config, guard, llm, memory, prompts, scope, tools


class Refusal(Exception):
    """Stopped before answering, with a code and a reason."""

    def __init__(self, code: str, message: str, status: int = 400):
        self.code = code
        self.message = message
        self.status = status
        super().__init__(f"{code}: {message}")


def _history_messages(turns: list[dict]) -> list[dict]:
    """Earlier turns, newest-last, bounded by config.HISTORY_TURNS.

    Bounded because the context also has to hold tool results, which are far
    larger than the turns themselves — a table chunk runs past three thousand
    characters. The file keeps everything; this is only what gets reasoned over.

    Refusals are written to the transcript but never replayed here. They are
    the service's words, not the assistant's, and feeding them back taught the
    model to refuse: after one "[off_topic] this assistant only answers
    about..." landed in the history, the next three turns were refused too,
    including one squarely in scope. The reader of the file still needs to see
    what happened, which is why they are stored and only filtered at this line.
    """
    recent = [t for t in turns if not t["text"].startswith("[")]
    return [{"role": t["role"], "content": t["text"]}
            for t in recent[-config.HISTORY_TURNS:]]


OFF_TOPIC = ("this assistant only answers about the structure of the Indorama "
             "LMS — its tables, routes and source files")


def assess_turn(question: str, vocabulary: set[str], turns: list[dict]):
    """The scope gate, which applies in full only to the first turn.

    A follow-up names nothing: "แล้วมันผูกกับตารางไหน" has no subject, so the
    gate that keeps the weather out would refuse it as well.

    The first attempt at fixing that matched referential words in a short
    question. It classified "วันนี้อากาศเป็นยังไง" as a follow-up on its first
    run, because "นี้" sits inside "วันนี้" — Thai gives a substring test no
    word boundary to anchor on, and the whole heuristic was the same mistake
    this codebase already made once with character trigrams.

    So the question is settled by fact instead. Inside a conversation the gate
    opens, and the turn is required to have looked something up before it may
    answer — see :func:`answer`. A question about the weather reaches no tool
    result and is refused for that, in any language, without anybody having to
    decide what its words meant.

    :raises Refusal: if a first question is out of scope
    """
    assessment = scope.assess(question, vocabulary)
    if assessment.in_scope:
        return assessment
    if turns:
        assessment.followup = True
        return assessment
    raise Refusal("off_topic",
                  f"{OFF_TOPIC}. The question does not name anything in it.")


def answer(store, question: str, turns: list[dict], vocabulary: set[str]) -> dict:
    """One turn: think, look things up, answer, and be checked.

    :raises Refusal: if the turn cannot be answered honestly
    """
    assessment = assess_turn(question, vocabulary, turns)
    offered = tools.for_question(assessment)

    messages = [{"role": "system", "content": prompts.AGENT}]
    history = _history_messages(turns)
    messages += history
    if history:
        # The rule is repeated next to the question, not only at the top.
        #
        # By the fourth turn the system prompt is several thousand tokens
        # behind the question, and qwen2.5:7b stops acting on it: asked "มี
        # ตารางทั้งหมดกี่ตาราง" it answered from the conversation instead of
        # calling list_tables, was nudged, did it again, and the turn was
        # refused for having looked nothing up. The identical question in a
        # fresh conversation was answered correctly from the tool on the first
        # attempt, which is what identified the distance rather than the
        # question as the problem.
        messages.append({"role": "system", "content": prompts.REMINDER})
    messages.append({"role": "user", "content": question})

    # Everything the model has been shown this turn, accumulated as it looks
    # things up. This is what the answer is checked against.
    seen_chunks: list[dict] = []
    material: list[str] = [t["text"] for t in turns if t["role"] == "assistant"]
    calls: list[dict] = []
    nudged = False

    budget = llm.Budget(config.AGENT_TIMEOUT)

    # One extra pass beyond the tool budget, for the nudge.
    for _ in range(config.MAX_TOOL_CALLS + 2):
        reply = llm.converse(messages, offered, budget.remaining())
        requested = reply.get("tool_calls") or []

        if not requested:
            content = (reply.get("content") or "").strip()
            if not content:
                raise Refusal("llm_empty",
                              "the model stopped without saying anything", 502)
            if not calls:
                # No tool was called at all, so there is nothing behind this.
                #
                # The test is whether a lookup *happened*, not whether it found
                # something. A tool that answers "there is no count broken down
                # that way" has done its job: the honest reply is that the
                # breakdown does not exist, and that reply is grounded in the
                # tool saying so. Requiring a chunk instead turned those into
                # off_topic — "this assistant only answers about the structure
                # of the Indorama LMS" in reply to a perfectly on-topic question
                # about courses, which is both wrong and unhelpful.
                #
                # This is what replaces the follow-up heuristic, and it is the
                # rule that keeps a conversation from becoming a way in. Asked
                # about the weather halfway through, the model either answers
                # from itself — caught here — or searches for it and is told by
                # the tool that this index holds nothing of the kind. Either
                # path ends in the same refusal, decided by whether a lookup
                # succeeded rather than by what the words looked like.
                #
                # Asked once first, though. A follow-up like "แล้วมันมีคอลัมน์
                # อ่อนไหวไหม" gets answered straight from what the model read
                # a turn ago, which is honest and still unverifiable here —
                # nothing was returned this turn for the guards to check
                # against. Nudged, it calls the tool again and the answer
                # becomes checkable. Refusing without asking threw away three
                # legitimate turns in the first run of this loop.
                if nudged:
                    raise Refusal("off_topic",
                                  f"{OFF_TOPIC}. Nothing in it matched this "
                                  f"turn, so there is nothing to answer from.")
                nudged = True
                messages.append({"role": "user", "content": prompts.NUDGE})
                continue
            return _checked(content, question, seen_chunks, material, calls,
                            budget, messages, offered)

        # The assistant's own turn has to go back into the transcript before
        # the results do, or the next request describes results with nothing
        # that asked for them.
        messages.append({"role": "assistant", "content": reply.get("content") or "",
                         "tool_calls": requested})

        for call in requested[:config.MAX_TOOL_CALLS]:
            name = call["function"]["name"]
            arguments = llm.arguments_of(call)
            result = tools.run(store, name, arguments, assessment)
            calls.append({"tool": name, "arguments": arguments,
                          "found": bool(result.chunks),
                          "sources": [c["ref"] for c in result.chunks]})
            seen_chunks += result.chunks
            material.append(result.text)
            messages.append({"role": "tool",
                             "tool_call_id": call.get("id", name),
                             "content": result.text})

    raise Refusal(
        "tool_limit",
        f"the model made {config.MAX_TOOL_CALLS} lookups without reaching an "
        f"answer, so it was stopped rather than left running", 502)


def _checked(content: str, question: str, seen_chunks: list[dict],
             material: list[str], calls: list[dict], budget, messages,
             offered: list[dict]) -> dict:
    """Apply the guards, retry once, and refuse rather than show a bad answer."""
    allowed = guard.allowed_from(seen_chunks)
    # Names the assistant already used in this conversation. They passed these
    # same checks when they were written, and a follow-up that cannot refer to
    # the answer before it is not a conversation.
    for earlier in material:
        allowed |= guard.identifiers_in(earlier)
    joined = "\n".join(material)

    def wrong(answer_text: str) -> tuple[list[str], str]:
        """(what is wrong, which note to send back).

        Disclosure is checked before invention, and it subsumes it: an answer
        with no technical names in it has none to have invented. Reporting a
        made-up name as "not in the material" when the real instruction is "do
        not write names at all" would send the model back to look the name up
        properly, which is not what it should do next.
        """
        drifted = guard.wrong_language(answer_text)
        if drifted:
            return drifted, guard.LANGUAGE_NOTE
        disclosed = guard.disclosed_identifiers(answer_text, question)
        if disclosed:
            return disclosed, guard.PROSE_NOTE
        return (guard.unsupported_numbers(answer_text, joined, question),
                guard.RETRY_NOTE)

    invented, note = wrong(content)
    if invented and budget.enough_for_another():
        messages.append({"role": "assistant", "content": content})
        messages.append({"role": "user",
                         "content": note.format(names=", ".join(invented))})
        retry = llm.converse(messages, offered, budget.remaining())
        candidate = (retry.get("content") or "").strip()
        if candidate:
            content, (invented, _) = candidate, wrong(candidate)

    if invented:
        raise Refusal("ungrounded_answer",
                      "the model kept producing an answer that was not plain "
                      "Thai, or that carried names or figures it was not "
                      "given: " + ", ".join(invented), 502)

    return {
        "answer": content,
        "tool_calls": calls,
        "sources": sorted({c["ref"] for c in seen_chunks}),
        # Complete lists come back whole for the caller to render, exactly as
        # they do from /ask: the model is asked not to transcribe them, and this
        # is what makes that instruction cost nothing.
        # Every chunk the tools returned, not only the complete lists.
        #
        # The answer is now required to be free of table and column names, so
        # this is the only place the reader can get them — and they are the
        # thing somebody came for. Withholding a name from the prose only works
        # if the name is on the screen somewhere exact and copyable.
        "lists": [{"ref": c["ref"], "title": c["title"], "text": c["text"],
                   "kind": c["kind"]}
                  for c in {c["chunk_id"]: c for c in seen_chunks}.values()],
    }
