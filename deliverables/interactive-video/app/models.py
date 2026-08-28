"""The shapes that go in and out, and the one that must never go out whole.

The distinction that matters here is between `Item` and `PublicItem`. An Item
carries the expected answers; a PublicItem is the same thing with them removed.
The player is only ever sent PublicItems — a timeline delivered to the browser
with its answers in it is a lesson anybody can pass by reading the network tab,
and no amount of front-end care fixes that afterwards.
"""
from __future__ import annotations

import enum

from pydantic import BaseModel, Field, field_validator


class ItemType(str, enum.Enum):
    """What kind of stop this is.

    `info` is here because "pause, say something, carry on" is what authors
    reach for most, and without it they write a question with one obvious
    answer instead — which reads to the learner as busywork and to the report
    as a mark everybody got.
    """

    CHOICE = "choice"
    MULTICHOICE = "multichoice"
    SHORTTEXT = "shorttext"
    INFO = "info"


class Provider(str, enum.Enum):
    """Where the video itself comes from."""

    FILE = "file"
    HLS = "hls"
    YOUTUBE = "youtube"
    VIMEO = "vimeo"


class Item(BaseModel):
    """One stop on the timeline, as the server holds it."""

    id: str
    at: float = Field(ge=0, description="Seconds from the start")
    type: ItemType
    text: str = Field(min_length=1, description="The question or the message")
    choices: list[str] = Field(default_factory=list)
    # Option indexes for choice/multichoice; accepted strings for shorttext;
    # empty for info.
    answers: list = Field(default_factory=list)
    feedback: str = ""
    category: str = ""

    @field_validator("category")
    @classmethod
    def _tidy_category(cls, value: str) -> str:
        # Whitespace only. Case is left alone because the category name is the
        # author's words and a report headed with a lowercased version of them
        # reads like a mistake — but the same name typed with a stray double
        # space must not become a second row in that report.
        return " ".join(value.split())[:100]

    def check(self) -> None:
        """Refuse an item that could never be answered correctly.

        Caught when the item is written rather than when a learner meets it:
        a multichoice with no correct option marks everyone wrong, and the
        first anybody hears of it is a complaint.

        :raises ValueError: if the item is unanswerable as written
        """
        if self.type == ItemType.INFO:
            return
        if self.type == ItemType.SHORTTEXT:
            if not [a for a in self.answers if str(a).strip()]:
                raise ValueError("a shorttext item needs at least one accepted answer")
            return

        if len(self.choices) < 2:
            raise ValueError(f"a {self.type.value} item needs at least two choices")
        indexes = {int(a) for a in self.answers}
        if not indexes:
            raise ValueError(f"a {self.type.value} item needs a correct answer")
        if any(i < 0 or i >= len(self.choices) for i in indexes):
            raise ValueError("a correct answer points at an option that does not exist")
        if self.type == ItemType.CHOICE and len(indexes) != 1:
            raise ValueError("a choice item must have exactly one correct answer")


class PublicItem(BaseModel):
    """An item as the player is allowed to see it: no answers, no feedback."""

    id: str
    at: float
    type: ItemType
    text: str
    choices: list[str] = Field(default_factory=list)

    @classmethod
    def of(cls, item: Item) -> "PublicItem":
        return cls(id=item.id, at=item.at, type=item.type,
                   text=item.text, choices=item.choices)


class Video(BaseModel):
    """A video and everything that happens during it."""

    # Optional in the body because the URL already carries it: PUT /videos/x
    # says which video this is, and demanding it a second time inside the
    # payload only creates a way for the two to disagree. The endpoint sets
    # it from the path, so the path always wins.
    id: str = ""
    title: str = ""
    provider: Provider = Provider.FILE
    # A URL for file/hls, a YouTube id, or a Vimeo id — optionally "id:hash"
    # for an unlisted one, which is what a paid course sits behind.
    source: str = ""
    must_answer: bool = True
    allow_retry: bool = True
    timeline: list[Item] = Field(default_factory=list)

    def ordered(self) -> list[Item]:
        """Timeline in the order a viewer meets it.

        Sorted here rather than trusted from storage: the player shows the
        earliest unanswered item first, so that somebody who seeks past three
        of them answers in the order the author wrote them rather than in
        reverse.
        """
        return sorted(self.timeline, key=lambda item: item.at)


class AnswerIn(BaseModel):
    user_id: str = Field(min_length=1, max_length=190)
    item_id: str
    response: str = ""


class AnswerOut(BaseModel):
    ok: bool = True
    correct: bool
    # Only ever filled once the answer is theirs to see — see grading.disclosure.
    answers: list[str] = Field(default_factory=list)
    feedback: str = ""
    may_retry: bool = False


class ProgressIn(BaseModel):
    user_id: str = Field(min_length=1, max_length=190)
    seconds: float = Field(ge=0)
    finished: bool = False
