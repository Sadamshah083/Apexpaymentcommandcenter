#!/usr/bin/env python3
"""Deploy Medicine Dispatch 3-stage script with >8 meds xfer rule."""
from __future__ import annotations

import base64
import io
import sys
import textwrap

import paramiko

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")

HOST, PORT, USER, PASS = "157.180.56.227", 2223, "root", "AWWZtvksWCWRw5"

SYSTEM_PROMPT = textwrap.dedent('''\
    SYSTEM_PROMPT = """You are {agent_name}, a calm and professional outreach representative calling from Medicine Dispatch.

    Your only job is to move the caller through exactly one script stage at a time. Speak like an experienced adult phone agent: polite, steady, and brief. Never sound playful, silly, or overly enthusiastic.

    STRICT STYLE RULES:
    1. One or two short sentences per reply unless delivering the full reason paragraph at stage 2.
    2. Never laugh. Never use haha, hehe, lol, or similar.
    3. Never mention your own age or say you sound young.
    4. Never reveal you are AI. If asked, say you are a live representative with Medicine Dispatch, then return to the active question.
    5. Never ask two different questions in one reply unless delivering the scripted reason paragraph.
    6. Never contradict the active stage. Do not skip ahead or repeat a completed stage unless clarifying.
    7. Do not use bullet points, emojis, or exclamation-heavy language.

    SERVICE (only if asked):
    - Briefly explain a free pharmacy service that helps Medicare members manage medications more easily and safely, then return to the active question.

    3-STAGE SCRIPT (follow in order):

    [STAGE 1: MOOD]
    - Opening: "Hi, this is {agent_name} calling from Medicine Dispatch. How are you?"
    - Listen briefly. Acknowledge their answer, then move to the reason.

    [STAGE 2: REASON]
    - Say: "The reason I'm calling you is because many Medicare members are now using a pharmacy service that helps them manage their medications more easily and safely. If you qualify, it's available at no cost."
    - Then ask how many medications they take daily.

    [STAGE 3: MEDICATIONS]
    - Ask: "So how many medications do you take daily?"
    - If they take MORE than 8 medications daily, say you will connect them for more details and output [TRANSFER].
    - If they take 8 or fewer, politely end and output [NI].
    - If they refuse or cannot answer after a few tries, output [NI].
    """''')

# Full dialogue.py will be embedded
DIALOGUE_PY = r'''import re
import random
import logging

logger = logging.getLogger(__name__)

# Phonetic corrections for Kaldi/Vosk STT output
_PHONETIC_CORRECTIONS = [
    (r"\bsick tea\b", "sixty"),
    (r"\bsick steep\b", "sixty"),
    (r"\bsick t\b", "sixty"),
    (r"\bfor tea\b", "forty"),
    (r"\bfor t\b", "forty"),
    (r"\bfif tea\b", "fifty"),
    (r"\bfif t\b", "fifty"),
    (r"\bthir tea\b", "thirty"),
    (r"\bthir t\b", "thirty"),
    (r"\bmedicine dispatch\b", "medicine dispatch"),
    (r"\bmedicare dispatch\b", "medicare dispatch"),
    (r"\bya\b", "yeah"),
    (r"\byah\b", "yeah"),
    (r"\bnah\b", "no"),
    (r"\byeah sure\b", "yes"),
    (r"\bhow low\b", "hello"),
    (r"\bhallow\b", "hello"),
    (r"\bgood buy\b", "goodbye"),
    (r"\bthanks you\b", "thank you"),
]
_COMPILED_CORRECTIONS = [(re.compile(p, re.IGNORECASE), r) for p, r in _PHONETIC_CORRECTIONS]

ABUSE_RE = re.compile(
    r"\b(fuck|shit|bitch|asshole|motherf\w*|madarchod|bhenchod|chutiya|gaandu|lund|"
    r"dick|cock|pussy|whore|slut|suck\s+my|blow\s+me|faggot|nigger|retard)\b",
    re.I
)

VOICEMAIL_RE = re.compile(
    r"(leave (your )?message|after the tone|voicemail|mailbox|not available|"
    r"cannot take your call|press 1 for more options|press pound|press one for|"
    r"review rerecord|unavailable|record your message|"
    r"the person you are trying to reach|is not accepting calls|return your call as soon as possible)",
    re.I
)

GOOD_RE = re.compile(
    r"\b(good|i am good|i'm good|i am doing good|i'm doing good|doing good|doing great|i am doing great|i'm doing great|"
    r"fine|great|pretty good|wonderful|excellent|doing well|okay|ok|alright|all right|not bad|alhamdulillah)\b",
    re.I
)

BAD_RE = re.compile(
    r"\b(not good|not well|bad|terrible|awful|sick|tired|upset|sad|not okay|i am not good|i'm not good)\b",
    re.I
)

YES_RE = re.compile(
    r"\b(yes|yeah|yep|correct|right|of course|sure|i do|okay|ok|alright|all right|absolutely|definitely|"
    r"sounds good|that would help|that would be great|that sounds great|i'd like that|i would like that|"
    r"why not|go ahead|please do|interested)\b",
    re.I
)

NO_RE = re.compile(
    r"\b(no|nope|nah|i do not|i don't|not really|not interested|don't need|do not need)\b",
    re.I
)

DEFAULT_AGENT_NAME = "Emily"
COMPANY_NAME = "Medicine Dispatch"
MIN_MEDS_FOR_TRANSFER = 9  # more than 8
MAX_STAGE_ATTEMPTS = 3

_NUMBER_WORDS = {
    "zero": 0, "one": 1, "two": 2, "three": 3, "four": 4, "five": 5,
    "six": 6, "seven": 7, "eight": 8, "nine": 9, "ten": 10,
    "eleven": 11, "twelve": 12, "thirteen": 13, "fourteen": 14, "fifteen": 15,
    "sixteen": 16, "seventeen": 17, "eighteen": 18, "nineteen": 19, "twenty": 20,
    "thirty": 30, "forty": 40, "fifty": 50, "sixty": 60, "seventy": 70,
    "eighty": 80, "ninety": 90, "hundred": 100, "dozen": 12,
}


def natural_ack() -> str:
    return random.choice(("Okay.", "Got it.", "Alright.", "I see.")) + " "


def opening_greeting(agent_name: str = DEFAULT_AGENT_NAME) -> str:
    name = (agent_name or DEFAULT_AGENT_NAME).strip() or DEFAULT_AGENT_NAME
    return f"Hi, this is {name} calling from {COMPANY_NAME}. How are you?"


def reason_message() -> str:
    return (
        "The reason I'm calling you is because many Medicare members are now using a pharmacy service "
        "that helps them manage their medications more easily and safely. If you qualify, it's available at no cost."
    )


def meds_question() -> str:
    return "So how many medications do you take daily?"


def transfer_line() -> str:
    return "Great, let me connect you so you can have more details on this. [TRANSFER]"


def not_qualified_line() -> str:
    return "I understand. Thank you for your time. Have a nice day. [NI]"


def identity_reassurance(agent_name: str, first_time: bool) -> str:
    name = (agent_name or DEFAULT_AGENT_NAME).strip() or DEFAULT_AGENT_NAME
    if first_time:
        return f"My name is {name}, calling from {COMPANY_NAME}. "
    return f"As I mentioned, I'm {name} with {COMPANY_NAME}. "


def personalize_for_agent(text: str, agent_name: str) -> str:
    """Substitute agent name into prompts and scripted lines."""
    name = (agent_name or DEFAULT_AGENT_NAME).strip() or DEFAULT_AGENT_NAME
    if "{agent_name}" in text:
        text = text.replace("{agent_name}", name)
    try:
        from config import ELEVENLABS_VOICE_NAMES
        other_names = set(ELEVENLABS_VOICE_NAMES.values()) | {DEFAULT_AGENT_NAME, "Emily"}
    except Exception:
        other_names = {DEFAULT_AGENT_NAME, "Emily"}
    for other in sorted(other_names, key=len, reverse=True):
        if other and other != name:
            text = re.sub(rf"\b{re.escape(other)}\b", name, text)
    return text


OPENING_GREETING = opening_greeting()
STAGE_QUESTIONS = {
    "MOOD": "How are you?",
    "REASON": reason_message(),
    "MEDS": meds_question(),
}

STAGE_REASK = {
    "MOOD": "I just wanted to check — how are you?",
    "REASON": reason_message(),
    "MEDS": "Could you tell me roughly how many medications you take each day?",
}


def stage_question(step: str, attempt: int) -> str:
    if attempt >= MAX_STAGE_ATTEMPTS and step in STAGE_REASK:
        return STAGE_REASK[step]
    return STAGE_QUESTIONS[step]

_LAUGH_RE = re.compile(r"\b(ha\s*ha|haha|hehe|he\s*he|lol|lmao|rofl)\b", re.I)
_TAG_RE = re.compile(
    r"\s*(\[(?:TRANSFER|DROP|DEC|NI|DNC|A|DAIR|ABUSE)\])\s*$", re.I
)

_MEDS_STEP_RE = re.compile(
    r"how many medications|medications do you take|medications that you take|medications you take",
    re.I,
)
_REASON_STEP_RE = re.compile(
    r"medicare members|manage their medications more easily|available at no cost|reason i'?m calling",
    re.I,
)
_MOOD_STEP_RE = re.compile(
    r"how\s+are\s+you|medicine dispatch|calling from medicine",
    re.I,
)


def sanitize_response(text: str) -> str:
    """Strip laughs, childish filler, and duplicate sentences from bot speech."""
    if not text:
        return text
    tag_match = _TAG_RE.search(text)
    tag = tag_match.group(1) if tag_match else ""
    body = text[: tag_match.start()].strip() if tag else text.strip()
    body = _LAUGH_RE.sub("", body)
    body = re.sub(r"!{2,}", ".", body)
    body = re.sub(r"\s+", " ", body).strip()
    sentences = [s.strip() for s in re.split(r"(?<=[.!?])\s+", body) if s.strip()]
    deduped: list[str] = []
    seen: set[str] = set()
    for sentence in sentences:
        key = re.sub(r"[^a-z0-9]+", "", sentence.lower())
        if key and key not in seen:
            seen.add(key)
            deduped.append(sentence)
    body = " ".join(deduped)
    return f"{body} {tag}".strip() if tag else body


def determine_active_step(assistant_msgs: list[str]) -> str:
    """Returns MOOD, REASON, or MEDS based on what the bot last asked."""
    for msg in reversed(assistant_msgs):
        if _MEDS_STEP_RE.search(msg):
            return "MEDS"
        if _REASON_STEP_RE.search(msg):
            return "REASON"
        if _MOOD_STEP_RE.search(msg):
            return "MOOD"
    return "MOOD"


def extract_medication_count(text: str) -> int | None:
    """Parse medication count from caller text. Returns None if unclear."""
    lowered = text.lower().strip()
    if not lowered:
        return None

    if re.search(r"\b(none|zero|no medications?|don't take any|do not take any|not taking any)\b", lowered):
        return 0

    numbers: list[int] = []
    for match in re.finditer(r"\b(\d{1,3})\b", lowered):
        numbers.append(int(match.group(1)))

    tokens = re.findall(r"[a-z]+", lowered)
    i = 0
    while i < len(tokens):
        tok = tokens[i]
        if tok in _NUMBER_WORDS:
            val = _NUMBER_WORDS[tok]
            if i + 2 < len(tokens) and tokens[i + 1] == "hundred":
                val *= 100
                i += 2
            elif i + 1 < len(tokens) and tokens[i + 1] in _NUMBER_WORDS and tokens[i + 1] not in ("hundred",):
                val += _NUMBER_WORDS[tokens[i + 1]]
                i += 1
            numbers.append(val)
        i += 1

    if not numbers:
        return None
    return max(numbers)


def get_llm_stage_context(conversation_history: list[dict], agent_name: str = DEFAULT_AGENT_NAME) -> str:
    """Inject the active script stage so LLM replies stay on-script."""
    assistant_msgs = [m["content"] for m in conversation_history if m["role"] == "assistant"]
    step = determine_active_step(assistant_msgs)
    question = STAGE_QUESTIONS[step]
    name = (agent_name or DEFAULT_AGENT_NAME).strip() or DEFAULT_AGENT_NAME
    if step == "MOOD":
        stage_hint = (
            "After they answer how they are doing, deliver the full reason paragraph, "
            "then ask how many medications they take daily."
        )
    elif step == "REASON":
        stage_hint = "Ask how many medications they take daily."
    else:
        stage_hint = (
            f"If they take more than 8 medications daily, offer to connect them and output [TRANSFER]. "
            "If 8 or fewer, politely end and output [NI]."
        )
    return (
        f"Your name is {name}. Never introduce yourself as anyone else. "
        f"ACTIVE STAGE: {step}. Reply in one or two professional sentences only. "
        f"{stage_hint} "
        f"Active question unless ending the call: \"{question}\". "
        "Never laugh. Never mention your age. Never ask two different questions."
    )


def local_correct_stt(raw_text: str) -> str:
    """Instant phonetic correction — no API call."""
    corrected = raw_text
    for pattern, replacement in _COMPILED_CORRECTIONS:
        corrected = pattern.sub(replacement, corrected)
    if corrected != raw_text:
        logger.info(f"STT local fix: '{raw_text}' → '{corrected}'")
    return corrected


def check_keyword(text: str, keywords: list[str]) -> bool:
    """Helper to check if any of the keywords are in the text as whole words."""
    for kw in keywords:
        pattern = r"\b" + re.escape(kw) + r"\b"
        if re.search(pattern, text, re.IGNORECASE):
            return True
    return False


def get_local_router_response(
    text: str,
    conversation_history: list[dict],
    agent_name: str = DEFAULT_AGENT_NAME,
) -> str | None:
    """
    Evaluates incoming user text against known intents, objections,
    and conversational contexts, then returns the correct, dynamic response phrase.
    Returns None if no high-confidence local intent matches (enabling LLM fallback).
    """
    result = _route_local_response(text, conversation_history, agent_name)
    return sanitize_response(result) if result else None


def _route_local_response(
    text: str,
    conversation_history: list[dict],
    agent_name: str = DEFAULT_AGENT_NAME,
) -> str | None:
    if VOICEMAIL_RE.search(text):
        logger.info(f"Answering machine / Voicemail detected in: '{text}'")
        return "[A]"

    if ABUSE_RE.search(text):
        logger.info(f"Abuse / Offensive language detected in: '{text}'")
        return "Thank you. Goodbye. [DNC]"

    assistant_msgs = [m["content"] for m in conversation_history if m["role"] == "assistant"]
    active_step = determine_active_step(assistant_msgs)
    last_user_msg = text.lower().strip()

    yes_keywords = ["yes", "yeah", "yep", "sure", "correct", "right", "ok", "okay", "absolutely", "definitely"]
    no_keywords = ["no", "dont", "don't", "nope", "nah", "nevermind", "not really", "not interested"]
    unsure_keywords = ["don't know", "dont know", "not sure", "no idea", "maybe", "not certain", "uncertain"]

    is_unsure = any(w in last_user_msg for w in unsure_keywords)
    is_yes = (
        check_keyword(last_user_msg, yes_keywords)
        or YES_RE.search(last_user_msg) is not None
    ) and not is_unsure
    is_no = (
        check_keyword(last_user_msg, no_keywords)
        or NO_RE.search(last_user_msg) is not None
        or "don't" in last_user_msg
        or "dont" in last_user_msg
    ) and not is_unsure

    is_dnc = any(w in last_user_msg for w in [
        "stop calling", "dont call", "don't call", "do not call", "remove me", "please stop",
        "bothering", "take me off", "wrong number", "not interested", "no thank you", "no thanks",
        "hang up", "bye",
    ])
    is_identity = any(w in last_user_msg for w in [
        "who are you", "who is this", "what is your name", "your name", "what company",
        "who is calling", "i don't know you", "dont know you", "what is this company",
        "who do you work for", "medicine dispatch", "medicare dispatch",
    ])
    is_why = any(w in last_user_msg for w in [
        "why are you calling", "why calling", "what is this about", "what is the reason",
        "why do you want", "reason for this", "why are you asking", "why ask", "why do you ask",
        "why do you need", "why need", "why should i", "why do i have to",
    ])
    is_scam = (
        check_keyword(last_user_msg, ["scam", "fake", "legit", "robot", "artificial", "machine", "computer", "ai"])
        or any(w in last_user_msg for w in ["real person", "are you a robot"])
    )
    is_service = any(w in last_user_msg for w in [
        "what is this service", "how does it work", "what do you offer", "how much", "cost",
        "free", "delivery", "pharmacy", "medications", "refill", "refills", "medicare",
    ])
    is_privacy_refusal = any(w in last_user_msg for w in [
        "not willing to", "not telling", "won't tell", "wont tell", "won't say", "wont say",
        "prefer not to", "private", "personal", "none of your business", "not sharing",
        "confidential", "keep that to myself", "not going to tell", "not going to say",
    ])
    is_spouse = any(w in last_user_msg for w in [
        "spouse", "wife", "husband", "partner", "marry", "married", "significant other",
        "deals with", "handles",
    ])
    is_location = any(w in last_user_msg for w in [
        "where are you calling from", "where is your office", "where are you located",
        "where are you based", "where do you live", "where are you", "location", "address",
    ])
    is_greeting = any(w == last_user_msg.strip() for w in [
        "hello", "hi", "hey", "good morning", "good afternoon", "good evening",
    ])
    is_bot_age = (
        any(w in last_user_msg for w in ["young", "pretty young", "so young", "sound young", "sounds young", "minor"])
        or (
            ("how old" in last_user_msg or "what is your age" in last_user_msg or "what's your age" in last_user_msg)
            and ("you" in last_user_msg or "your" in last_user_msg)
        )
    )
    is_clarification = any(w in last_user_msg for w in [
        "understand", "repeat", "hear", "what did you say", "say again", "what was that",
        "pardon", "what do you mean", "slow down",
    ]) or last_user_msg in ["what", "who", "huh", "pardon"]

    mood_attempts = sum(1 for m in assistant_msgs if _MOOD_STEP_RE.search(m))
    reason_attempts = sum(1 for m in assistant_msgs if _REASON_STEP_RE.search(m))
    meds_attempts = sum(1 for m in assistant_msgs if _MEDS_STEP_RE.search(m))

    user_answered_mood = (
        GOOD_RE.search(last_user_msg)
        or BAD_RE.search(last_user_msg)
        or any(w in last_user_msg for w in ["fine", "good", "well", "great", "wonderful", "excellent", "okay", "ok", "doing well", "doing good", "doing great", "pretty good"])
    )

    med_count = extract_medication_count(text) if active_step == "MEDS" else None

    has_objection = (
        is_why or is_identity or is_location or is_service or is_scam or
        is_privacy_refusal or is_spouse or is_bot_age or is_clarification
    )

    empathy_prefix = ""
    if active_step == "MOOD":
        if BAD_RE.search(last_user_msg):
            empathy_prefix = "Sorry to hear that. "
        elif GOOD_RE.search(last_user_msg) or user_answered_mood:
            empathy_prefix = "Glad to hear that. "

    def get_mood_question() -> str:
        return stage_question("MOOD", mood_attempts)

    def get_reason() -> str:
        return reason_message()

    def get_meds_q() -> str:
        return stage_question("MEDS", meds_attempts)

    if is_dnc:
        return "I understand. I will remove your number from our list. Have a good day. [DNC]"

    if active_step == "MEDS":
        if med_count is not None:
            if med_count >= MIN_MEDS_FOR_TRANSFER:
                logger.info(f"Qualified xfer: {med_count} medications (>8)")
                return transfer_line()
            logger.info(f"Not qualified: {med_count} medications (<=8)")
            return not_qualified_line()
        if is_privacy_refusal or is_unsure:
            if meds_attempts >= MAX_STAGE_ATTEMPTS:
                return not_qualified_line()
            return "That's fine. " + get_meds_q()

    if is_greeting:
        if len(assistant_msgs) <= 1:
            return opening_greeting(agent_name)
        if active_step == "MEDS":
            return "I'm still here. " + get_meds_q()
        if active_step == "REASON":
            return "I'm still here. " + get_meds_q()
        return "I'm still here. " + get_mood_question()

    if is_bot_age:
        return f"I'm a live representative with {COMPANY_NAME}. " + (
            get_meds_q() if active_step == "MEDS" else get_mood_question()
        )

    if is_spouse:
        return "I understand. If someone else handles your medications, they can learn more later. " + (
            get_meds_q() if active_step in ("MEDS", "REASON") else get_reason() + " " + get_meds_q()
        )

    if is_identity:
        already_named = sum(
            1 for m in assistant_msgs
            if "my name is" in m.lower() or f"with {COMPANY_NAME.lower()}" in m.lower()
            or f"from {COMPANY_NAME.lower()}" in m.lower()
        )
        follow = get_meds_q() if active_step == "MEDS" else get_mood_question()
        return identity_reassurance(agent_name, already_named == 0) + follow

    if is_location:
        return f"We're calling on behalf of {COMPANY_NAME}. " + (
            get_meds_q() if active_step in ("MEDS", "REASON") else get_reason() + " " + get_meds_q()
        )

    if is_why:
        if active_step in ("MEDS", "REASON"):
            return "It's a free pharmacy service that helps Medicare members manage medications more easily. " + get_meds_q()
        return f"We're reaching out about a free pharmacy service for Medicare members with {COMPANY_NAME}. " + get_mood_question()

    if is_scam:
        return f"I'm a live representative with {COMPANY_NAME}, and we don't ask for payment or sensitive information on this call. " + (
            get_meds_q() if active_step in ("MEDS", "REASON") else get_reason() + " " + get_meds_q()
        )

    if is_service:
        return (
            "It's a free pharmacy service that helps Medicare members manage their medications more easily and safely. "
            + (get_meds_q() if active_step in ("MEDS", "REASON") else get_reason() + " " + get_meds_q())
        )

    if is_privacy_refusal:
        if active_step == "MEDS":
            if meds_attempts >= MAX_STAGE_ATTEMPTS:
                return not_qualified_line()
            return "No personal details are needed right now. " + get_meds_q()
        return "We only share basic information about the pharmacy service. " + get_mood_question()

    if is_unsure:
        if active_step == "MOOD":
            return "That's fine. " + get_mood_question()
        if active_step == "REASON":
            return "That's fine. " + get_meds_q()
        if meds_attempts <= MAX_STAGE_ATTEMPTS - 1:
            return "That's fine. " + get_meds_q()
        return not_qualified_line()

    if is_clarification:
        if active_step == "MEDS":
            return get_meds_q()
        if active_step == "REASON":
            return get_reason() + " " + get_meds_q()
        return opening_greeting(agent_name)

    if active_step == "MOOD":
        if user_answered_mood or is_yes or is_no:
            return (empathy_prefix or natural_ack()) + get_reason() + " " + get_meds_q()
        if mood_attempts >= MAX_STAGE_ATTEMPTS and not user_answered_mood:
            return not_qualified_line()
        return None

    if active_step == "REASON":
        if is_yes or user_answered_mood or not has_objection:
            return get_meds_q()
        if reason_attempts >= MAX_STAGE_ATTEMPTS:
            return not_qualified_line()
        return None

    if active_step == "MEDS":
        if med_count is not None:
            if med_count >= MIN_MEDS_FOR_TRANSFER:
                return transfer_line()
            return not_qualified_line()
        if meds_attempts >= MAX_STAGE_ATTEMPTS:
            return not_qualified_line()
        return None

    return None
'''

TTS_PATCH = r'''
# Phrases from the live Medicine Dispatch 3-stage script (pre-warm TTS cache)
_STATIC_PRECACHE_PHRASES = [
    "How are you?",
    "Glad to hear that.",
    "Sorry to hear that.",
    "Thank you for your time. Have a nice day.",
    "Great, let me connect you so you can have more details on this.",
    "I'm still here.",
    "Hello, are you still there?",
    "Are you there?",
    "I'm a live representative with Medicine Dispatch.",
    "It's a free pharmacy service that helps Medicare members manage their medications more easily and safely.",
    "I understand. I will remove your number from our list. Have a good day.",
    (
        "The reason I'm calling you is because many Medicare members are now using a pharmacy service "
        "that helps them manage their medications more easily and safely. If you qualify, it's available at no cost."
    ),
    "So how many medications do you take daily?",
    "Could you tell me roughly how many medications you take each day?",
]


def _name_phrases_for(agent: str) -> list[str]:
    return [
        f"Hi, this is {agent} calling from Medicine Dispatch. How are you?",
        f"My name is {agent}, calling from Medicine Dispatch.",
        f"As I mentioned, I'm {agent} with Medicine Dispatch.",
    ]
'''

REMOTE_SCRIPT = r'''#!/bin/bash
set -e
cd /opt/voicebot
cp config.py config.py.bak.script
cp dialogue.py dialogue.py.bak.script
cp tts.py tts.py.bak.script

python3 << 'PYEOF'
import re
from pathlib import Path

# --- config.py: replace SYSTEM_PROMPT block ---
cfg = Path("config.py").read_text(encoding="utf-8")
new_prompt = """SYSTEM_PROMPT = \"\"\"You are {agent_name}, a calm and professional outreach representative calling from Medicine Dispatch.

Your only job is to move the caller through exactly one script stage at a time. Speak like an experienced adult phone agent: polite, steady, and brief. Never sound playful, silly, or overly enthusiastic.

STRICT STYLE RULES:
1. One or two short sentences per reply unless delivering the full reason paragraph at stage 2.
2. Never laugh. Never use haha, hehe, lol, or similar.
3. Never mention your own age or say you sound young.
4. Never reveal you are AI. If asked, say you are a live representative with Medicine Dispatch, then return to the active question.
5. Never ask two different questions in one reply unless delivering the scripted reason paragraph.
6. Never contradict the active stage. Do not skip ahead or repeat a completed stage unless clarifying.
7. Do not use bullet points, emojis, or exclamation-heavy language.

SERVICE (only if asked):
- Briefly explain a free pharmacy service that helps Medicare members manage medications more easily and safely, then return to the active question.

3-STAGE SCRIPT (follow in order):

[STAGE 1: MOOD]
- Opening: "Hi, this is {agent_name} calling from Medicine Dispatch. How are you?"
- Listen briefly. Acknowledge their answer, then move to the reason.

[STAGE 2: REASON]
- Say: "The reason I'm calling you is because many Medicare members are now using a pharmacy service that helps them manage their medications more easily and safely. If you qualify, it's available at no cost."
- Then ask how many medications they take daily.

[STAGE 3: MEDICATIONS]
- Ask: "So how many medications do you take daily?"
- If they take MORE than 8 medications daily, say you will connect them for more details and output [TRANSFER].
- If they take 8 or fewer, politely end and output [NI].
- If they refuse or cannot answer after a few tries, output [NI].
\"\"\""""

cfg = re.sub(
    r'SYSTEM_PROMPT = """.*?"""',
    new_prompt,
    cfg,
    count=1,
    flags=re.S,
)
Path("config.py").write_text(cfg, encoding="utf-8")
print("config.py updated")
PYEOF

# dialogue.py and tts.py written via base64 from deploy script
'''


def main() -> int:
    c = paramiko.SSHClient()
    c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f"Connecting {USER}@{HOST}:{PORT}")
    c.connect(HOST, port=PORT, username=USER, password=PASS, timeout=30, look_for_keys=False, allow_agent=False)

    # Write files via base64 chunks
    files = {
        "/opt/voicebot/dialogue.py": DIALOGUE_PY,
    }

    # Build remote bash
    bash = REMOTE_SCRIPT
    for remote_path, content in files.items():
        b64 = base64.b64encode(content.encode("utf-8")).decode("ascii")
        fname = remote_path.split("/")[-1]
        bash += f"""
echo "Writing {fname}..."
python3 -c "import base64; open('{remote_path}','wb').write(base64.b64decode('{b64}'))"
"""

    # TTS patch via sed replacement of the static block
    tts_b64 = base64.b64encode(TTS_PATCH.strip().encode("utf-8")).decode("ascii")
    bash += f"""
python3 << 'PYEOF'
import base64, re
from pathlib import Path
patch = base64.b64decode("{tts_b64}").decode("utf-8")
static_part, name_part = patch.split("def _name_phrases_for", 1)
name_part = "def _name_phrases_for" + name_part
tts = Path("tts.py").read_text(encoding="utf-8")
start = tts.index("# Phrases from the live")
end = tts.index("_EDGE_FEMALE")
tts = tts[:start] + static_part.strip() + "\\n\\n\\n" + name_part.strip() + "\\n\\n\\n" + tts[end:]
Path("tts.py").write_text(tts, encoding="utf-8")
print("tts.py updated")
PYEOF

echo '--- verify opening ---'
python3 -c "import dialogue; print(dialogue.opening_greeting('Sarah'))"
echo '--- verify xfer threshold ---'
python3 -c "import dialogue; print('9 meds:', dialogue.extract_medication_count('I take nine medications daily')); print('8 meds:', dialogue.extract_medication_count('about 8')); print('10 meds:', dialogue.extract_medication_count('ten or eleven'))"
echo '--- verify prompt ---'
grep -n 'Medicine Dispatch' config.py | head -5
grep -n 'more than 8' config.py | head -5

systemctl restart voicebot voicebot-bridge
sleep 3
systemctl is-active voicebot voicebot-bridge
"""

    stdin, stdout, stderr = c.exec_command("bash -s", timeout=180)
    stdin.write(bash)
    stdin.channel.shutdown_write()
    out = stdout.read().decode(errors="replace")
    err = stderr.read().decode(errors="replace")
    code = stdout.channel.recv_exit_status()
    print(out)
    if err.strip():
        print("STDERR:", err)
    print("exit:", code)
    c.close()
    return code


if __name__ == "__main__":
    raise SystemExit(main())
