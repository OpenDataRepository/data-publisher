#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<EOF
Usage: $0 [-f] [SOURCE] [DEST]
Default SOURCE: /home/odr/data-publisher
Default DEST: current directory
-f : force overwrite existing files/directories in DEST
EOF
}

FORCE=0
while getopts ":f" opt; do
  case $opt in
    f) FORCE=1 ;;
    *) usage; exit 1 ;;
  esac
done
shift $((OPTIND-1))

SRC="${1:-/home/odr/data-publisher}"
DEST="${2:-.}"

SRC="$(realpath "$SRC")"
DEST="$(realpath "$DEST")"

if [ ! -d "$SRC" ]; then
  echo "Source directory not found: $SRC" >&2
  exit 2
fi

mkdir -p "$DEST"

shopt -s dotglob nullglob

handle_existing() {
  local target="$1"
  if [ -e "$target" ] || [ -L "$target" ]; then
    if [ "$FORCE" -eq 1 ]; then
      rm -rf "$target"
    else
      echo "Skipping existing: $target"
      return 1
    fi
  fi
  return 0
}

echo "Source: $SRC"
echo "Dest:   $DEST"

# iterate top-level entries
for srcpath in "$SRC"/* "$SRC"/.[!.]* "$SRC"/..?*; do
  [ -e "$srcpath" ] || continue
  name="${srcpath#$SRC/}"

  if [ "$name" = "app" ]; then
    mkdir -p "$DEST/app"
    # handle children of app specially
    for child in "$SRC/app"/* "$SRC/app"/.[!.]* "$SRC/app"/..?*; do
      [ -e "$child" ] || continue
      childname="${child#$SRC/app/}"

      if [ "$childname" = "config" ]; then
        if [ "$FORCE" -eq 1 ]; then
          rm -rf "$DEST/app/config"
        fi
        mkdir -p "$DEST/app/config"
        if [ -d "$SRC/app/config" ]; then
          cp -a "$SRC/app/config/." "$DEST/app/config/" || true
        fi
        # Use prefixed routes for WordPress-integrated linked instances
        if [ -f "$DEST/app/config/routing_prefixed.yml" ]; then
          cp "$DEST/app/config/routing_prefixed.yml" "$DEST/app/config/routing.yml"
          echo "Copied routing_prefixed.yml over routing.yml"
        fi
        echo "Copied app/config contents"

      elif [ "$childname" = "logs" ]; then
        if [ "$FORCE" -eq 1 ]; then
          rm -rf "$DEST/app/logs"
        fi
        mkdir -p "$DEST/app/logs"
        echo "Created app/logs (contents ignored)"

      elif [ "$childname" = "cache" ] || [ "$childname" = "tmp" ]; then
        # create physical directories for cache and tmp (contents ignored)
        if [ "$FORCE" -eq 1 ]; then
          rm -rf "$DEST/app/$childname"
        fi
        mkdir -p "$DEST/app/$childname"
        echo "Created app/$childname (contents ignored)"

      else
        tgt="$DEST/app/$childname"
        handle_existing "$tgt" || continue
        mkdir -p "$(dirname "$tgt")"
        ln -s "$child" "$tgt"
        echo "Linked app/$childname -> $child"
      fi
    done

  else
    tgt="$DEST/$name"
    handle_existing "$tgt" || continue
    mkdir -p "$(dirname "$tgt")"
    ln -s "$srcpath" "$tgt"
    echo "Linked $name -> $srcpath"
  fi
done

echo "Settng cache permissions."

HTTPDUSER=`ps axo user,comm | grep -E '[a]pache|[h]ttpd|[_]www|[w]ww-data|[n]ginx' | grep -v root | head -1 | cut -d\  -f1`

echo $HTTPDUSER

# Symfony 7 keeps cache/logs under var/ (was app/cache + app/logs in 3.4).
sudo setfacl -R -m u:"$HTTPDUSER":rwX -m u:`whoami`:rwX app var/cache var/log app/tmp
sudo setfacl -dR -m u:"$HTTPDUSER":rwX -m u:`whoami`:rwX app var/cache var/log app/tmp

echo "Done."
