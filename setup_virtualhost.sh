#!/usr/bin/env bash
#
# Sets up, or repairs, a linked ODR instance: a directory whose code is symlinks into a shared
# SOURCE checkout, with only its own settings and working files kept as real files.
#
# Run it from the instance directory (or name it), any number of times:
#   ./setup_virtualhost.sh -n                    show what would change, change nothing
#   ./setup_virtualhost.sh                       set up / repair the current directory
#   ./setup_virtualhost.sh SOURCE DEST           a specific instance
#   ./setup_virtualhost.sh --all                 every instance in SOURCE/app/config/instances.list
#
# What an instance looks like afterwards:
#   real    var/ (with cache/, log/, tmp/), app/, and inside app/: config/, logs/, cache/, tmp/
#   links   everything else, top level and in app/, each pointing at the same name in SOURCE
#   left    .git, .idea and other tool folders -- never linked, never removed
#
# What it changes:
#   - a missing link is created; a wrong or broken link is pointed at SOURCE
#   - a real file or directory where a link belongs (e.g. a copied tests/) is replaced by a link
#   - anything no longer in SOURCE is removed (stale links, old files)
#   - app/config: every .dist file is copied in when new or changed.  The instance's own config
#     files (parameters.yml, config.yml, ...) are never touched; ones that have a .dist but are
#     missing are reported.  A brand-new instance gets a full copy of SOURCE's app/config.
#   - var/ and the real app/ directories are created if missing
# Nothing is deleted outright: real files and directories it replaces or removes are moved to
# var/setup-backup/<date-time>/ in the instance.  Symlinks are simply removed.

set -euo pipefail
shopt -s dotglob nullglob

usage() {
  cat <<EOF
Usage: $0 [-n] [--all] [SOURCE] [DEST]
  SOURCE   the shared checkout (default: /home/odr/data-publisher)
  DEST     the instance to set up or repair (default: the current directory)
  -n       dry run: say what would change, change nothing
  --all    repair every instance listed in SOURCE/app/config/instances.list
EOF
}

# Real directories, kept per instance.  Everything else is linked.
TOP_REAL=(app var)
APP_REAL=(config logs cache tmp)
VAR_DIRS=(cache log tmp)
# Never linked into an instance, and never removed from one
LEAVE_ALONE=(.git .gitignore .idea .vscode .claude .agent .gemini .geminiignore .DS_Store)

DRY_RUN=0
ALL=0
ARGS=()
for arg in "$@"; do
  case "$arg" in
    -n|--dry-run) DRY_RUN=1 ;;
    --all) ALL=1 ;;
    -f) echo "note: -f is no longer needed -- repairs are always made (see -n to preview)" ;;
    -h|--help) usage; exit 0 ;;
    -*) usage; exit 1 ;;
    *) ARGS+=("$arg") ;;
  esac
done

SRC="$(realpath "${ARGS[0]:-/home/odr/data-publisher}")"
if [ ! -f "$SRC/app/AppKernel.php" ]; then
  echo "Not an ODR checkout: $SRC" >&2
  exit 2
fi

STAMP="$(date +%Y%m%d-%H%M%S)"
CHANGES=0

in_list() {   # in_list NAME LIST...
  local needle="$1"; shift
  local item
  for item in "$@"; do [ "$item" = "$needle" ] && return 0; done
  return 1
}

act() {       # act DESCRIPTION COMMAND...   -- runs it unless this is a dry run
  CHANGES=$((CHANGES + 1))
  if [ "$DRY_RUN" -eq 1 ]; then
    echo "  would $1"
  else
    echo "  $1"
    shift
    "$@"
  fi
}

backup_dir() {
  echo "$DEST/var/setup-backup/$STAMP"
}

move_aside() {   # move_aside PATH RELATIVE-NAME   -- a real file/dir into the backup
  local path="$1" rel="$2"
  local target
  target="$(backup_dir)/$rel"
  mkdir -p "$(dirname "$target")"
  mv "$path" "$target"
}

remove_entry() {   # remove_entry PATH RELATIVE-NAME WHY
  local path="$1" rel="$2" why="$3"
  if [ -L "$path" ]; then
    act "remove link $rel ($why)" rm "$path"
  else
    act "move $rel to var/setup-backup/$STAMP/ ($why)" move_aside "$path" "$rel"
  fi
}

ensure_link() {   # ensure_link PATH TARGET RELATIVE-NAME
  local path="$1" target="$2" rel="$3"
  if [ -L "$path" ]; then
    if [ "$(readlink "$path")" = "$target" ]; then
      return 0
    fi
    act "re-point $rel -> $target (was -> $(readlink "$path"))" ln -sfn "$target" "$path"
  elif [ -e "$path" ]; then
    act "move the real $rel to var/setup-backup/$STAMP/ and link it -> $target" \
      sh -c 'mkdir -p "$1" && mv "$2" "$1/" && ln -s "$3" "$2"' _ "$(dirname "$(backup_dir)/$rel")" "$path" "$target"
  else
    act "link $rel -> $target" ln -s "$target" "$path"
  fi
}

ensure_real_dir() {   # ensure_real_dir PATH RELATIVE-NAME
  local path="$1" rel="$2"
  if [ -L "$path" ]; then
    act "replace the link $rel with a real directory (it's per instance)" sh -c 'rm "$1" && mkdir -p "$1"' _ "$path"
  elif [ ! -d "$path" ]; then
    act "create $rel/" mkdir -p "$path"
  fi
}

sync_children() {   # sync_children SOURCE-DIR DEST-DIR PREFIX REAL-NAMES...
  local sdir="$1" ddir="$2" prefix="$3"; shift 3
  local real=("$@")
  local entry name
  # everything in the source: linked, unless it's one of the real ones
  for entry in "$sdir"/*; do
    name="$(basename "$entry")"
    in_list "$name" "${LEAVE_ALONE[@]}" && continue
    in_list "$name" "${real[@]}" && continue
    ensure_link "$ddir/$name" "$entry" "$prefix$name"
  done
  # anything here that the source no longer has
  for entry in "$ddir"/*; do
    name="$(basename "$entry")"
    in_list "$name" "${LEAVE_ALONE[@]}" && continue
    in_list "$name" "${real[@]}" && continue
    if [ ! -e "$sdir/$name" ] && [ ! -L "$sdir/$name" ]; then
      remove_entry "$entry" "$prefix$name" "no longer in the source"
    fi
  done
}

sync_config() {   # sync_config DEST
  local cfg="$DEST/app/config" file name base
  if [ "$NEW_CONFIG" -eq 1 ]; then
    # a brand-new instance starts from the source's whole config, as before
    act "copy $SRC/app/config into app/config (new instance -- edit parameters.yml)" cp -a "$SRC/app/config/." "$cfg/"
    if [ -f "$SRC/app/config/routing_prefixed.yml" ]; then
      act "use routing_prefixed.yml as app/config/routing.yml" cp "$SRC/app/config/routing_prefixed.yml" "$cfg/routing.yml"
    fi
    return 0
  fi
  for file in "$SRC/app/config"/*.dist "$SRC/app/config"/*.dist-template; do
    name="$(basename "$file")"
    if [ ! -f "$cfg/$name" ]; then
      act "copy app/config/$name (new)" cp -p "$file" "$cfg/$name"
    elif ! cmp -s "$file" "$cfg/$name"; then
      act "update app/config/$name (changed in the source)" cp -p "$file" "$cfg/$name"
    fi
  done
  # report -- never create -- this instance's own files that a .dist describes
  for file in "$SRC/app/config"/*.dist; do
    base="$(basename "$file" .dist)"
    if [ ! -e "$cfg/$base" ]; then
      echo "  note: app/config/$base doesn't exist (template: app/config/$base.dist)"
    fi
  done
}

set_permissions() {
  local httpduser
  httpduser="$(ps axo user,comm | grep -E '[a]pache|[h]ttpd|[_]www|[w]ww-data|[n]ginx' | grep -v root | head -1 | cut -d' ' -f1 || true)"
  httpduser="${httpduser:-www-data}"
  if ! command -v setfacl > /dev/null; then
    echo "  note: setfacl isn't installed; set var/ and app/ permissions for $httpduser by hand"
    return 0
  fi
  local dirs=("$DEST/app" "$DEST/var/cache" "$DEST/var/log" "$DEST/app/tmp")
  if [ "$DRY_RUN" -eq 1 ]; then
    echo "  would give $httpduser and $(whoami) read/write on app/, var/cache, var/log, app/tmp"
    return 0
  fi
  sudo setfacl -R -m u:"$httpduser":rwX -m u:"$(whoami)":rwX "${dirs[@]}"
  sudo setfacl -dR -m u:"$httpduser":rwX -m u:"$(whoami)":rwX "${dirs[@]}"
  echo "  permissions: $httpduser and $(whoami) can read/write app/, var/cache, var/log, app/tmp"
}

setup_instance() {
  DEST="$(realpath -m "$1")"
  echo
  echo "== $DEST  (source: $SRC)"
  if [ "$DEST" = "$SRC" ]; then
    echo "  skipped: that's the source itself" >&2
    return 0
  fi
  if [ "$DEST" = "/" ] || [ "$DEST" = "$HOME" ]; then
    echo "  refusing to set up an instance in $DEST" >&2
    return 1
  fi
  [ -d "$DEST" ] || act "create $DEST" mkdir -p "$DEST"

  NEW_CONFIG=0
  [ -d "$DEST/app/config" ] || [ -L "$DEST/app/config" ] || NEW_CONFIG=1

  # the real directories first, so a backup has somewhere to go
  local name
  ensure_real_dir "$DEST/var" "var"
  for name in "${VAR_DIRS[@]}"; do ensure_real_dir "$DEST/var/$name" "var/$name"; done
  ensure_real_dir "$DEST/app" "app"
  for name in "${APP_REAL[@]}"; do ensure_real_dir "$DEST/app/$name" "app/$name"; done

  sync_children "$SRC" "$DEST" "" "${TOP_REAL[@]}"
  sync_children "$SRC/app" "$DEST/app" "app/" "${APP_REAL[@]}"
  sync_config
  set_permissions

  if [ -d "$(backup_dir)" ]; then
    echo "  moved-aside files are in $(backup_dir) -- delete it once you're happy"
  fi
}

if [ "$ALL" -eq 1 ]; then
  list="$SRC/app/config/instances.list"
  [ -f "$list" ] || { echo "No $list" >&2; exit 2; }
  while IFS= read -r line; do
    line="${line%%#*}"; line="$(echo "$line" | xargs)"
    [ -n "$line" ] && setup_instance "$line"
  done < "$list"
else
  setup_instance "${ARGS[1]:-.}"
fi

echo
if [ "$DRY_RUN" -eq 1 ]; then
  echo "Dry run: $CHANGES change(s) would be made.  Run without -n to make them."
else
  echo "Done: $CHANGES change(s)."
fi
