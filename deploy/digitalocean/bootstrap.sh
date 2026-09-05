#!/bin/sh
# Bring a bare Ubuntu droplet up to a running stack, in one command.
#
#     ssh root@<droplet-ip>
#     curl -fsSL <raw-url-of-this-file> -o bootstrap.sh && sh bootstrap.sh
#
# or, if the repository is already on the droplet:
#
#     sh deploy/digitalocean/bootstrap.sh
#
# Everything here is safe to run twice. Docker is not reinstalled if present,
# the firewall rules are idempotent, and the model weights are only fetched
# when missing — so a half-finished run is fixed by running it again rather
# than by working out how far it got.
set -e

REPO_URL="${REPO_URL:-}"
TARGET="${TARGET:-/opt/kai}"

say() { printf '\n\033[1m== %s\033[0m\n' "$1"; }
die() { printf '\n\033[31mERROR: %s\033[0m\n' "$1" >&2; exit 1; }

[ "$(id -u)" = "0" ] || die "run as root (or with sudo)"

# --------------------------------------------------------------------------
say "1/7  Docker"
# --------------------------------------------------------------------------
if command -v docker >/dev/null 2>&1; then
    echo "already installed: $(docker --version)"
else
    curl -fsSL https://get.docker.com | sh
fi

# --------------------------------------------------------------------------
say "2/7  Firewall"
# --------------------------------------------------------------------------
# Only three ways in. Everything else — including the two services — listens
# on loopback and is reachable through the proxy or not at all.
#
# THE FIREWALL IS ONLY TOUCHED IF IT IS ALREADY ON.
#
# An earlier version enabled it unconditionally, on the reasoning that a
# droplet running this should have a firewall. It ran on a machine that had
# ufw switched off and about a dozen other services on it — several of them
# Node apps on host ports, reached by the reverse proxy from inside a
# container. Enabling ufw cut that path, and a deploy that touched none of
# those services took all of them down.
#
# Turning a firewall on is a change to what the whole machine allows, and it
# is not a side effect a deploy script gets to have. If ufw is off, this says
# so and moves on; if it is on, the three rules are added and nothing that
# was already allowed is removed.
if ! command -v ufw >/dev/null 2>&1; then
    echo "ufw not installed — check the provider's cloud firewall instead"
elif ufw status 2>/dev/null | head -1 | grep -qi inactive; then
    echo "ufw is inactive — left that way"
    echo "  To use it, turn it on yourself, and allow the docker bridge first:"
    echo "    ufw allow OpenSSH && ufw allow 80/tcp && ufw allow 443/tcp"
    echo "    ufw allow from 172.16.0.0/12   # containers reaching host ports"
    echo "    ufw enable"
else
    # Already on, so rules are added rather than a policy being changed.
    # OpenSSH first: a firewall enabled without it locks this session out,
    # and the only way back is the provider's console.
    ufw allow OpenSSH >/dev/null
    ufw allow 80/tcp >/dev/null
    ufw allow 443/tcp >/dev/null
    # Anything in a container that talks to a service on the host goes
    # through here. Without it a reverse proxy in a container cannot reach a
    # process outside one, which is a common enough arrangement that leaving
    # it out breaks machines quietly.
    ufw allow from 172.16.0.0/12 >/dev/null
    echo "ufw: $(ufw status | head -1) (rules added, nothing removed)"
fi

# --------------------------------------------------------------------------
say "3/7  Code"
# --------------------------------------------------------------------------
if [ -d "$TARGET/.git" ]; then
    echo "updating $TARGET"
    git -C "$TARGET" pull --ff-only
elif [ -f "$(dirname "$0")/docker-compose.yml" ]; then
    TARGET="$(cd "$(dirname "$0")/../.." && pwd)"
    echo "running from a checkout at $TARGET"
else
    [ -n "$REPO_URL" ] || die "set REPO_URL=<git url>, or run this from a checkout"
    git clone "$REPO_URL" "$TARGET"
fi
cd "$TARGET"

HERE="$TARGET/deploy/digitalocean"

# --------------------------------------------------------------------------
say "4/7  Settings"
# --------------------------------------------------------------------------
if [ ! -f "$HERE/.env" ]; then
    cp "$HERE/.env.example" "$HERE/.env"
    # Keys generated here rather than left blank, so that a run that stops at
    # this step has still produced something usable. A blank key disables the
    # check entirely, which is the one outcome nobody would notice.
    FACE_KEY="$(openssl rand -hex 32)"
    AGENT_KEY="$(openssl rand -hex 32)"
    sed -i "s|^FACE_API_KEY=.*|FACE_API_KEY=$FACE_KEY|" "$HERE/.env"
    sed -i "s|^RAG_API_KEY=.*|RAG_API_KEY=customer:$AGENT_KEY|" "$HERE/.env"
    chmod 600 "$HERE/.env"

    printf '\n\033[33mA new .env was written with fresh keys.\033[0m\n'
    printf 'Before the stack can start you must still set, in %s:\n' "$HERE/.env"
    printf '  SITE_DOMAIN      the name that already resolves to this droplet\n'
    printf '  RAG_LLM_API_KEY  the OpenAI key the agent will spend\n\n'
    printf 'Then run this script again.\n'
    exit 2
fi

# Read them without sourcing the file: a value containing a space or a quote
# would otherwise be executed rather than read.
setting() { grep -E "^$1=" "$HERE/.env" | head -1 | cut -d= -f2-; }

[ -n "$(setting SITE_DOMAIN)" ] || die "SITE_DOMAIN is empty in $HERE/.env"
[ -n "$(setting RAG_LLM_API_KEY)" ] || die "RAG_LLM_API_KEY is empty in $HERE/.env"
[ -n "$(setting FACE_API_KEY)" ] || die "FACE_API_KEY is empty in $HERE/.env"
[ -n "$(setting RAG_API_KEY)" ] || die "RAG_API_KEY is empty in $HERE/.env"

DOMAIN="$(setting SITE_DOMAIN)"
echo "domain: $DOMAIN"

# A domain that does not point here yet is the most common way this ends
# badly: Caddy asks Let's Encrypt for a certificate immediately, fails, and
# Let's Encrypt allows five such failures per domain per week.
# getent as well as dig: a fresh Ubuntu has no dnsutils, and skipping the
# check on the machines least likely to be set up correctly is backwards.
resolve() {
    if command -v dig >/dev/null 2>&1; then
        dig +short "$1" | tail -1
    else
        getent hosts "$1" 2>/dev/null | head -1 | awk '{print $1}'
    fi
}

if [ "${DOMAIN#:}" = "$DOMAIN" ]; then
    RESOLVED="$(resolve "$DOMAIN")"
    MINE="$(curl -fsS --max-time 10 https://api.ipify.org 2>/dev/null || echo '')"
    if [ -n "$RESOLVED" ] && [ -n "$MINE" ] && [ "$RESOLVED" != "$MINE" ]; then
        die "$DOMAIN resolves to $RESOLVED but this droplet is $MINE.
   Fix the DNS record and wait for it before running this again — a failed
   certificate request counts against a weekly limit of five."
    fi
    [ -n "$RESOLVED" ] || die "$DOMAIN does not resolve yet. Add the A record first."
fi

# --------------------------------------------------------------------------
say "5/7  Face recognition models"
# --------------------------------------------------------------------------
# Two of the four are fetched rather than committed: one is 38MB.
MODELS="$TARGET/deliverables/face-recognition/models"
if [ -f "$MODELS/face_recognition_sface_2021dec.onnx" ]; then
    echo "already present"
else
    sh "$MODELS/fetch.sh"
fi
echo "models: $(ls "$MODELS"/*.onnx 2>/dev/null | wc -l) of 4"

# --------------------------------------------------------------------------
say "6/7  Agent index"
# --------------------------------------------------------------------------
# The index is a build artefact of one export and is deliberately not in the
# repository. Without it the agent starts and answers {"ok": false,
# "code": "no_index"} — which is honest, and is also not a working service.
if [ -f "$HERE/index/index.sqlite" ]; then
    echo "present: $(du -h "$HERE/index/index.sqlite" | cut -f1)"
else
    printf '\033[33mmissing.\033[0m Copy it up from the machine that built it:\n'
    printf '  scp indorama-rag/index.sqlite root@<this-droplet>:%s/index/\n' "$HERE"
    printf 'The face service will still start; the agent will report no_index.\n'
fi

# --------------------------------------------------------------------------
say "7/7  Start"
# --------------------------------------------------------------------------
docker compose -f "$HERE/docker-compose.yml" --env-file "$HERE/.env" up -d --build

printf '\nwaiting for the health checks'
i=0
while [ "$i" -lt 30 ]; do
    sleep 5
    printf '.'
    i=$((i + 1))
    if curl -fsS --max-time 5 "http://127.0.0.1:18081/health" >/dev/null 2>&1 \
       && curl -fsS --max-time 5 "http://127.0.0.1:18082/health" >/dev/null 2>&1; then
        break
    fi
done
printf '\n\n'

echo "face  (127.0.0.1:18081): $(curl -fsS --max-time 5 http://127.0.0.1:18081/health 2>/dev/null | head -c 80 || echo 'not answering')"
echo "agent (127.0.0.1:18082): $(curl -fsS --max-time 5 http://127.0.0.1:18082/health 2>/dev/null | head -c 80 || echo 'not answering')"

cat <<REPORT

== Done

Public:
  https://$DOMAIN/face/health
  https://$DOMAIN/agent/health

A certificate takes a few seconds on the first request. If https fails, read
what Caddy says before changing anything:
  docker compose -f $HERE/docker-compose.yml logs caddy --tail 30

The keys to hand out are in $HERE/.env — send each customer only their own,
and not through the same channel as the URL.

REPORT
