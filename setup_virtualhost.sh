#!/usr/bin/env bash
#
# Sets up, or repairs, a linked ODR instance: a directory whose code is symlinks into a shared
# SOURCE checkout, with only its own settings and working files kept as real files.
#
# Nothing is changed unless --setup or --repair is given:
#   ./setup_virtualhost.sh                       help, then what a repair would change here
#   ./setup_virtualhost.sh --repair              repair the instance in the current directory
#   ./setup_virtualhost.sh --setup [SOURCE] DEST create a new instance in DEST
#   ./setup_virtualhost.sh --repair --all        repair every instance in SOURCE/app/config/instances.list
#
# What an instance looks like afterwards:
#   real    var/ (with cache/, log/, tmp/), app/, and inside app/: config/, logs/, tmp/
#   gone    app/cache (no longer used): moved to var/setup-backup/ if present
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
#   - the web server user gets write access to app/logs, app/tmp, var/cache, var/log, var/tmp
#     (ACLs when the filesystem supports them, otherwise the group and g+rwX)
# Nothing is deleted outright: real files and directories it replaces or removes are moved to
# var/setup-backup/<date-time>/ in the instance.  Symlinks are simply removed.

set -euo pipefail
shopt -s dotglob nullglob

usage() {
  cat <<EOF
Usage: $0 [--setup | --repair | -n] [--all] [SOURCE] [DEST]

Sets up or repairs a linked ODR instance: real app/config, app/{logs,tmp} and var/; everything
else a symlink into SOURCE.  Nothing is changed unless --setup or --repair is given.

  (nothing), -n, --dry-run
              show what a repair would change, and change nothing (the default)
  --repair    repair an existing instance: missing/wrong links, real copies where links belong,
              files no longer in SOURCE, new or changed app/config/*.dist, missing var/
  --setup     create a new instance in DEST (refuses if DEST already has one)
  --all       every instance listed in SOURCE/app/config/instances.list (dry run, or --repair)
  SOURCE      the shared checkout (default: /home/odr/data-publisher)
  DEST        the instance (default: the current directory)

Replaced or removed real files are moved to DEST/var/setup-backup/<date-time>/, never deleted.

Examples:
  $0                          preview a repair of the current directory
  $0 --repair                 repair the current directory
  $0 --repair --all           repair every listed instance
  $0 --setup /home/odr/data-publisher /home/newsite/data-publisher
EOF
}

# Real directories, kept per instance.  Everything else is linked.
TOP_REAL=(app var)
APP_REAL=(config logs tmp)
# No longer used: removed from an instance (into the backup), never linked
APP_OBSOLETE=(cache)
VAR_DIRS=(cache log tmp)
# Never linked into an instance, and never removed from one
LEAVE_ALONE=(.git .gitignore .idea .vscode .claude .agent .gemini .geminiignore .DS_Store)

MODE=dry-run
ALL=0
ARGS=()
for arg in "$@"; do
  case "$arg" in
    -n|--dry-run) MODE=dry-run ;;
    --repair) MODE=repair ;;
    --setup) MODE=setup ;;
    --all) ALL=1 ;;
    -h|--help) usage; exit 0 ;;
    -*) echo "Unknown option: $arg" >&2; echo >&2; usage >&2; exit 1 ;;
    *) ARGS+=("$arg") ;;
  esac
done
DRY_RUN=0
[ "$MODE" = dry-run ] && DRY_RUN=1
if [ "$MODE" = setup ] && [ "$ALL" -eq 1 ]; then
  echo "--setup creates one new instance; --all is for --repair (or a dry run)" >&2
  exit 1
fi
# With no arguments at all: the help first, then the preview
if [ $# -eq 0 ]; then
  usage
  echo
  echo "---- dry run: what --repair would change in $(pwd) ----"
fi

SRC="$(realpath "${ARGS[0]:-/home/odr/data-publisher}")"
if [ ! -f "$SRC/app/AppKernel.php" ]; then
  echo "Not an ODR checkout: $SRC" >&2
  exit 2
fi

STAMP="$(date +%Y%m%d-%H%M%S)"
CHANGES=0
FAILED=0

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

sync_children() {   # sync_children SOURCE-DIR DEST-DIR PREFIX OBSOLETE-NAMES -- REAL-NAMES...
  local sdir="$1" ddir="$2" prefix="$3"; shift 3
  local obsolete=()
  while [ "$1" != "--" ]; do obsolete+=("$1"); shift; done
  shift
  local real=("$@")
  local entry name
  # everything in the source: linked, unless it's one of the real or obsolete ones
  for entry in "$sdir"/*; do
    name="$(basename "$entry")"
    in_list "$name" "${LEAVE_ALONE[@]}" && continue
    in_list "$name" "${real[@]}" && continue
    in_list "$name" "${obsolete[@]}" && continue
    ensure_link "$ddir/$name" "$entry" "$prefix$name"
  done
  # anything here that the source no longer has, or that's no longer used
  for entry in "$ddir"/*; do
    name="$(basename "$entry")"
    in_list "$name" "${LEAVE_ALONE[@]}" && continue
    in_list "$name" "${real[@]}" && continue
    if in_list "$name" "${obsolete[@]}"; then
      remove_entry "$entry" "$prefix$name" "no longer used"
    elif [ ! -e "$sdir/$name" ] && [ ! -L "$sdir/$name" ]; then
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

as_root() {   # as_root COMMAND...   -- directly when already root, otherwise through sudo
  if [ "$(id -u)" -eq 0 ]; then "$@"; else sudo "$@"; fi
}

set_permissions() {
  # The web server writes logs, caches and temp files.  Only those directories are touched --
  # never app/config, the links, or anything they point at.
  local httpduser owner dir rel out
  httpduser="$(ps axo user,comm | grep -E '[a]pache|[h]ttpd|[_]www|[w]ww-data|[n]ginx' | grep -v root | head -1 | cut -d' ' -f1 || true)"
  httpduser="${httpduser:-www-data}"
  # the instance's owner (not whoever runs this: under sudo that's root)
  owner="$(stat -c %U "$DEST")"
  local dirs=(app/logs app/tmp var/cache var/log var/tmp)
  if [ "$DRY_RUN" -eq 1 ]; then
    echo "  would give $httpduser and $owner write access to ${dirs[*]}"
    return 0
  fi
  local acl=1
  command -v setfacl > /dev/null || { acl=0; echo "  note: setfacl isn't installed -- using the group instead"; }
  for rel in "${dirs[@]}"; do
    dir="$DEST/$rel"
    [ -d "$dir" ] || { echo "  missing $rel/ -- skipped" >&2; FAILED=1; continue; }
    if [ "$acl" -eq 1 ]; then
      # access ACLs on everything in it; default ACLs (inherited by new files) on directories only
      if out="$(as_root setfacl -R -m u:"$httpduser":rwX -m u:"$owner":rwX "$dir" 2>&1)" \
         && out="$(as_root find "$dir" -type d -exec setfacl -m d:u:"$httpduser":rwX -m d:u:"$owner":rwX {} + 2>&1)"; then
        echo "  permissions: $rel/ writable by $httpduser and $owner (ACL)"
        continue
      fi
      echo "  setfacl failed on $rel/:" >&2
      printf '    %s\n' "$out" >&2
      echo "  falling back to the group" >&2
    fi
    # no ACLs: owner keeps the files, the web server's group can write, and setgid on the
    # directories so new files stay in that group
    if out="$(as_root chgrp -R "$httpduser" "$dir" 2>&1)" \
       && out="$(as_root chmod -R g+rwX "$dir" 2>&1)" \
       && out="$(as_root find "$dir" -type d -exec chmod g+s {} + 2>&1)"; then
      echo "  permissions: $rel/ writable by group $httpduser (chgrp + g+rwX)"
    else
      echo "  couldn't set permissions on $rel/:" >&2
      printf '    %s\n' "$out" >&2
      FAILED=1
    fi
  done
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
  NEW_CONFIG=0
  [ -d "$DEST/app/config" ] || [ -L "$DEST/app/config" ] || NEW_CONFIG=1

  # --setup is for a new instance, --repair (and the preview) for an existing one
  if [ "$MODE" = setup ] && [ "$NEW_CONFIG" -eq 0 ]; then
    echo "  $DEST already has an instance (app/config exists) -- use --repair" >&2
    return 1
  fi
  if [ "$MODE" != setup ] && [ "$NEW_CONFIG" -eq 1 ]; then
    echo "  $DEST isn't an ODR instance (no app/config) -- nothing to repair.  To create one there: --setup" >&2
    return 1
  fi
  [ -d "$DEST" ] || act "create $DEST" mkdir -p "$DEST"

  # the real directories first, so a backup has somewhere to go
  local name
  ensure_real_dir "$DEST/var" "var"
  for name in "${VAR_DIRS[@]}"; do ensure_real_dir "$DEST/var/$name" "var/$name"; done
  ensure_real_dir "$DEST/app" "app"
  for name in "${APP_REAL[@]}"; do ensure_real_dir "$DEST/app/$name" "app/$name"; done

  sync_children "$SRC" "$DEST" "" -- "${TOP_REAL[@]}"
  sync_children "$SRC/app" "$DEST/app" "app/" "${APP_OBSOLETE[@]}" -- "${APP_REAL[@]}"
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
    [ -n "$line" ] && { setup_instance "$line" || FAILED=1; }
  done < "$list"
else
  setup_instance "${ARGS[1]:-.}" || FAILED=1
fi

echo
if [ "$DRY_RUN" -eq 1 ]; then
  echo "Dry run: $CHANGES change(s) would be made.  Nothing was changed; --repair makes them."
else
  echo "Done: $CHANGES change(s)."
fi
[ "$FAILED" -eq 0 ] || echo "Some steps failed -- see the messages above." >&2
exit "$FAILED"
