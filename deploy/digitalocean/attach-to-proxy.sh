#!/bin/sh
# Publish the agent (and the face service) through the Caddy that is already
# running on this machine, without disturbing anything else it serves.
#
#     scp handoff/attach-to-proxy.sh root@152.42.177.130:/tmp/
#     ssh root@152.42.177.130 "sh /tmp/attach-to-proxy.sh"
#
# This edits a reverse proxy that is serving about forty other containers, so:
#
#   * the Caddyfile is copied to a timestamped backup before anything;
#   * the new block is APPENDED — no existing line is touched;
#   * the result is validated before it is loaded, and restored from the
#     backup if validation fails;
#   * it is loaded with `caddy reload`, not a restart, so no other site drops
#     a single request;
#   * running it twice does nothing the second time.
#
# If any step fails the script stops and says so, leaving the proxy as it was.
set -e

PROXY="${PROXY:-proxy}"
DOMAIN="${DOMAIN:-152.42.177.130.sslip.io}"
AGENT="${AGENT:-kai-services-agent-1}"
FACE="${FACE:-kai-services-face-1}"
MARK="# --- kai-services (added by attach-to-proxy.sh) ---"

say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
warn() { printf '\033[33m%s\033[0m\n' "$1"; }
die()  { printf '\n\033[31mERROR: %s\033[0m\n' "$1" >&2; exit 1; }

# --------------------------------------------------------------------------
say "1/6  Checking what is there"
# --------------------------------------------------------------------------
docker inspect "$PROXY" >/dev/null 2>&1 || die "no container called '$PROXY'"

for c in "$AGENT" "$FACE"; do
    state="$(docker inspect "$c" --format '{{.State.Status}}' 2>/dev/null || echo missing)"
    [ "$state" = "running" ] || die "$c is '$state' — start it before attaching it to the proxy"
    echo "  $c: running"
done

# --------------------------------------------------------------------------
say "2/6  Putting our containers on the proxy's network"
# --------------------------------------------------------------------------
# Our services publish only on the droplet's loopback, which a container
# cannot reach — 127.0.0.1 inside the proxy is the proxy. Rather than opening
# them to the host's other interfaces, we join the network the proxy is
# already on, and it reaches them by container name. Nothing new is exposed.
NET="$(docker inspect "$PROXY" --format '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}' | awk '{print $1}')"
[ -n "$NET" ] || die "could not read the proxy's network"
echo "  network: $NET"

for c in "$AGENT" "$FACE"; do
    if docker inspect "$c" --format '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}' | grep -qw "$NET"; then
        echo "  $c: already on it"
    else
        docker network connect "$NET" "$c"
        echo "  $c: connected"
    fi
done

# --------------------------------------------------------------------------
say "3/6  Finding the Caddyfile"
# --------------------------------------------------------------------------
# Read from the container's own mounts rather than guessed at, because a path
# that is right on most machines and wrong on this one would be edited
# confidently and do nothing.
CADDYFILE="$(docker inspect "$PROXY" --format '{{range .Mounts}}{{if eq .Destination "/etc/caddy/Caddyfile"}}{{.Source}}{{end}}{{end}}')"

if [ -z "$CADDYFILE" ]; then
    CADDYFILE="$(docker inspect "$PROXY" --format '{{range .Mounts}}{{.Source}}|{{.Destination}}{{println}}{{end}}' \
        | grep -i caddyfile | head -1 | cut -d'|' -f1)"
fi

[ -n "$CADDYFILE" ] && [ -f "$CADDYFILE" ] || {
    warn "The Caddyfile is not mounted from the host — it is baked into the image."
    warn "Its mounts are:"
    docker inspect "$PROXY" --format '{{range .Mounts}}  {{.Source}} -> {{.Destination}}{{println}}{{end}}'
    die "Edit it where it is built (the work-plane repository), or re-run with CADDYFILE=<path>."
}
echo "  $CADDYFILE"

# --------------------------------------------------------------------------
say "4/6  Backing up"
# --------------------------------------------------------------------------
if grep -qF "$MARK" "$CADDYFILE"; then
    echo "  already attached — nothing to add"
    ALREADY=yes
else
    ALREADY=no
    BACKUP="${CADDYFILE}.before-kai-$(date +%Y%m%d-%H%M%S)"
    cp -p "$CADDYFILE" "$BACKUP"
    echo "  $BACKUP"
fi

# --------------------------------------------------------------------------
say "5/6  Adding the site block"
# --------------------------------------------------------------------------
if [ "$ALREADY" = "no" ]; then
    cat >> "$CADDYFILE" <<BLOCK

$MARK
# One name, two services, separated by path prefix. Added rather than merged
# into anything above: every other site in this file is untouched, and
# removing this again means deleting from the marker to the closing brace.
#
#   https://$DOMAIN/agent/*  -> the LMS assistant
#   https://$DOMAIN/face/*   -> the face recognition service
$DOMAIN {
	handle_path /agent/* {
		reverse_proxy $AGENT:9200 {
			# One agent turn is several model calls. This has to sit above
			# the service's own RAG_AGENT_TIMEOUT (300s) or the proxy gives
			# up first and the caller gets a 504 instead of the service's
			# own named failure, which is the more useful of the two.
			transport http {
				response_header_timeout 360s
			}
		}
	}

	handle_path /face/* {
		reverse_proxy $FACE:9000 {
			transport http {
				response_header_timeout 120s
			}
		}
	}

	handle {
		respond "Not found" 404
	}
}
BLOCK
    echo "  appended"

    # Validate before loading. A broken Caddyfile that reaches a reload takes
    # every site on this machine down, so the restore is automatic rather
    # than a step somebody has to remember while it is already down.
    if ! docker exec "$PROXY" caddy validate --config /etc/caddy/Caddyfile >/tmp/kai-validate.log 2>&1; then
        cp -p "$BACKUP" "$CADDYFILE"
        warn "Validation failed. The Caddyfile has been restored from the backup"
        warn "and the proxy was never reloaded, so nothing changed. Caddy said:"
        sed 's/^/    /' /tmp/kai-validate.log | tail -20
        exit 1
    fi
    echo "  validated"
fi

# --------------------------------------------------------------------------
say "6/6  Reloading and testing"
# --------------------------------------------------------------------------
# reload, not restart: existing connections to every other site are kept.
docker exec "$PROXY" caddy reload --config /etc/caddy/Caddyfile >/dev/null 2>&1 \
    || die "reload failed — the config validated, so read: docker logs $PROXY --tail 30"
echo "  reloaded"

printf '\n  waiting for the certificate'
i=0
while [ "$i" -lt 12 ]; do
    sleep 5
    printf '.'
    i=$((i + 1))
    curl -fsS --max-time 8 "https://$DOMAIN/agent/health" >/dev/null 2>&1 && break
done
printf '\n\n'

echo "  https://$DOMAIN/agent/health"
curl -fsS --max-time 15 "https://$DOMAIN/agent/health" 2>/dev/null | head -c 120 || echo "    not answering yet"
echo
echo "  https://$DOMAIN/face/health"
curl -fsS --max-time 15 "https://$DOMAIN/face/health" 2>/dev/null | head -c 120 || echo "    not answering yet"
echo

cat <<REPORT

== Other sites, unchanged

  $(docker ps --format '{{.Names}}' | wc -l) containers still running, proxy reloaded rather than restarted.

If https is not answering yet, the certificate is still being issued; give it
a minute and try again. If it still fails:

  docker logs $PROXY --tail 40

To undo all of this: delete from the "$MARK" line to its closing brace in
  $CADDYFILE
then  docker exec $PROXY caddy reload --config /etc/caddy/Caddyfile
The backup beside it is the file as it was.

REPORT
