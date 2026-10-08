#!/usr/bin/env python3

"""
Open Data Repository Data Publisher
ufw_unblock_crawlers.py

Finds UFW DENY rules that block search engine crawlers we actually want, and removes them.

Bot traffic gets blocked at the firewall in bulk, which means a range belonging to Googlebot or
bingbot occasionally ends up in the block list -- and a firewall DENY is invisible to anything
inside the application, so nothing else will ever notice.  Run this now and then to check.

It reads the IP ranges that Google, Bing, and DuckDuckGo publish for their crawlers (the same
three sources as ODR's `app/console odr:crawler_ips:update`), compares them against
`ufw status numbered`, and deletes the offending rules HIGHEST NUMBER FIRST so the lower numbers
don't shift underneath the deletions.

Nothing is deleted without --apply.  Without it, this only reports.

Usage:
    ./ufw_unblock_crawlers.py                      # report what would be removed
    sudo ./ufw_unblock_crawlers.py --apply         # actually remove it
    ./ufw_unblock_crawlers.py --from-file ufw.txt  # audit a saved `ufw status numbered`
    ./ufw_unblock_crawlers.py --save-ranges r.json # cache the published ranges
    ./ufw_unblock_crawlers.py --ranges-file r.json # ...and work from that cache, offline

Exit codes:
    0  nothing to remove, or --apply succeeded
    1  something went wrong (and nothing was deleted)
    2  rules were found but not removed (no --apply)

Requires python3 only.  No third-party modules.
"""

import argparse
import ipaddress
import json
import os
import re
import subprocess
import sys
import urllib.request


# Where each search engine publishes the IP ranges its crawlers use.  All three happen to use the
# same schema: {"creationTime": ..., "prefixes": [{"ipv4Prefix": ...}|{"ipv6Prefix": ...}]}
#
# Keep in step with UpdateCrawlerIpsCommand::SOURCES in the ODR codebase.
SOURCES = {
    'google': 'https://developers.google.com/static/crawling/ipranges/common-crawlers.json',
    'bing': 'https://www.bing.com/toolbox/bingbot.json',
    'duckduckgo': 'https://duckduckgo.com/duckduckbot.json',
}

# A source returning fewer prefixes than this is treated as broken, so a truncated response can't
# make it look like nothing is blocked.
MINIMUM_PREFIXES = 5

TIMEOUT = 30


def fetch_ranges(timeout=TIMEOUT):
    """Downloads every source.  Returns {name: [prefix strings]}, raising on any failure."""
    out = {}
    for name, url in SOURCES.items():
        try:
            request = urllib.request.Request(url, headers={'User-Agent': 'ODR ufw crawler check'})
            with urllib.request.urlopen(request, timeout=timeout) as response:
                payload = json.load(response)
        except Exception as e:
            raise RuntimeError('could not fetch %s (%s): %s' % (name, url, e))

        prefixes = [
            entry.get('ipv4Prefix') or entry.get('ipv6Prefix')
            for entry in payload.get('prefixes', [])
        ]
        prefixes = [p for p in prefixes if p]
        if len(prefixes) < MINIMUM_PREFIXES:
            raise RuntimeError(
                'source %s returned only %d prefixes, expected at least %d -- refusing to act on it'
                % (name, len(prefixes), MINIMUM_PREFIXES)
            )

        out[name] = prefixes

    return out


def parse_ranges(raw):
    """Turns {name: [prefix strings]} into {name: [ip_network]}, skipping anything unparseable."""
    out = {}
    for name, prefixes in raw.items():
        networks = []
        for prefix in prefixes:
            try:
                networks.append(ipaddress.ip_network(prefix, strict=False))
            except ValueError:
                continue
        out[name] = networks
    return out


# UFW prints:  [ 12] To            Action      From
# ...where Action is e.g. "DENY IN", and columns are separated by runs of two or more spaces.
RULE_RE = re.compile(r'^\[\s*(\d+)\]\s+(.*\S)\s*$')
ACTION_RE = re.compile(r'^(ALLOW|DENY|REJECT|LIMIT)\b\s*(IN|OUT)?', re.IGNORECASE)


def parse_ufw_status(text):
    """
    Parses `ufw status numbered` output.

    Returns a list of dicts: number, action, direction, source, line.  Rules whose source isn't an
    IP or CIDR (e.g. "Anywhere") come back with source None, so callers can ignore them.
    """
    rules = []
    for line in text.splitlines():
        match = RULE_RE.match(line)
        if not match:
            continue

        number = int(match.group(1))
        columns = re.split(r'\s{2,}', match.group(2))
        # a rule comment shows up as a trailing "# ..." column
        columns = [c for c in columns if not c.startswith('#')]

        action = direction = source = None
        for index, column in enumerate(columns):
            found = ACTION_RE.match(column.strip())
            if not found:
                continue
            action = found.group(1).upper()
            direction = (found.group(2) or 'IN').upper()
            if index + 1 < len(columns):
                source = columns[index + 1].strip()
            break

        if action is None:
            continue

        network = None
        if source:
            try:
                network = ipaddress.ip_network(source, strict=False)
            except ValueError:
                network = None

        rules.append({
            'number': number,
            'action': action,
            'direction': direction,
            'source': source,
            'network': network,
            'line': line.rstrip(),
        })

    return rules


def uncovered_portion(network, cover):
    """
    Returns the parts of `network` that no network in `cover` accounts for.

    An empty list means the rule blocks crawler space and nothing else -- which is the case that
    can be removed without thinking about it.  Published ranges are often small prefixes that
    together tile a larger one (Google publishes eight /27s covering 66.249.72.0/24), so testing
    against the union like this matters: comparing against one prefix at a time would wrongly
    report a partial overlap.
    """
    remaining = [network]
    for covering in cover:
        step = []
        for piece in remaining:
            if piece.subnet_of(covering):
                continue
            if not piece.overlaps(covering):
                step.append(piece)
                continue
            if covering.subnet_of(piece):
                step.extend(piece.address_exclude(covering))
            else:
                step.append(piece)
        remaining = step
        if not remaining:
            break
    return remaining


def classify(rules, ranges, directions=('IN',)):
    """
    Splits DENY rules into:
        full     -- block only crawler space; safe to delete
        partial  -- overlap crawler space but also cover other addresses; need a human
        adjacent -- in the same /16 as crawler space but not published; informational only
    """
    every_network = [n for networks in ranges.values() for n in networks]
    # collapse_addresses() refuses to mix families, and the published lists carry both
    union = []
    for version in (4, 6):
        same_family = [n for n in every_network if n.version == version]
        if same_family:
            union.extend(ipaddress.collapse_addresses(same_family))
    sixteens = set()
    for network in every_network:
        size = 16 if network.version == 4 else 32
        if network.prefixlen >= size:
            sixteens.add(ipaddress.ip_network('%s/%d' % (network.network_address, size), strict=False))

    full, partial, adjacent = [], [], []
    for rule in rules:
        if rule['action'] != 'DENY' or rule['network'] is None:
            continue
        if rule['direction'] not in directions:
            continue

        network = rule['network']
        overlapping = [c for c in union if c.version == network.version and network.overlaps(c)]

        if not overlapping:
            if any(network.overlaps(s) for s in sixteens if s.version == network.version):
                adjacent.append(dict(rule, crawlers=[]))
            continue

        who = sorted({
            name for name, networks in ranges.items()
            for n in networks if n.version == network.version and network.overlaps(n)
        })
        leftover = uncovered_portion(network, overlapping)
        entry = dict(rule, crawlers=who, leftover=leftover)
        (full if not leftover else partial).append(entry)

    return full, partial, adjacent


def run_ufw_status(from_file=None):
    if from_file:
        with open(from_file, 'r') as handle:
            return handle.read()

    try:
        result = subprocess.run(
            ['ufw', 'status', 'numbered'],
            capture_output=True, text=True, check=True,
        )
    except FileNotFoundError:
        raise RuntimeError('ufw is not installed (or not on PATH) -- use --from-file to audit saved output')
    except subprocess.CalledProcessError as e:
        message = (e.stderr or e.stdout or '').strip()
        raise RuntimeError('`ufw status numbered` failed: %s' % (message or 'exit %d' % e.returncode))

    return result.stdout


def delete_rules(targets, dry_run=False):
    """
    Deletes the given rules, highest number first so the remaining numbers stay put.

    Re-reads the live rule list immediately beforehand and confirms every target number still
    holds the source we matched.  If anything moved -- someone else edited the firewall, or the
    report is stale -- nothing is deleted.
    """
    current = {r['number']: r['source'] for r in parse_ufw_status(run_ufw_status())}

    mismatched = []
    for rule in targets:
        if current.get(rule['number']) != rule['source']:
            mismatched.append((rule['number'], rule['source'], current.get(rule['number'])))

    if mismatched:
        print('\nThe firewall has changed since those rules were matched -- nothing deleted:')
        for number, expected, found in mismatched:
            print('    [%d] expected %s, found %s' % (number, expected, found if found else '(no such rule)'))
        print('Re-run to get a fresh list.')
        return False

    for rule in sorted(targets, key=lambda r: r['number'], reverse=True):
        label = '[%d] %s' % (rule['number'], rule['source'])
        if dry_run:
            print('    would delete %s' % label)
            continue

        try:
            subprocess.run(
                ['ufw', '--force', 'delete', str(rule['number'])],
                capture_output=True, text=True, check=True,
            )
        except subprocess.CalledProcessError as e:
            message = (e.stderr or e.stdout or '').strip()
            print('    FAILED to delete %s: %s' % (label, message))
            print('    Stopping here.  Lower-numbered rules are untouched; re-run to continue.')
            return False

        print('    deleted %s' % label)

    return True


def main():
    parser = argparse.ArgumentParser(
        description='Remove UFW DENY rules that block search engine crawlers.',
        epilog='Reports only unless --apply is given.',
    )
    parser.add_argument('--apply', action='store_true',
                        help='actually delete the rules (needs root)')
    parser.add_argument('--from-file', metavar='FILE',
                        help='read `ufw status numbered` output from a file instead of running ufw')
    parser.add_argument('--ranges-file', metavar='FILE',
                        help='read the published ranges from a json cache instead of downloading')
    parser.add_argument('--save-ranges', metavar='FILE',
                        help='write the downloaded ranges to a json cache and exit')
    parser.add_argument('--include-partial', action='store_true',
                        help='also delete rules that cover non-crawler addresses (off by default)')
    args = parser.parse_args()

    # ----------------------------------------
    # the published ranges
    try:
        if args.ranges_file:
            with open(args.ranges_file, 'r') as handle:
                raw = json.load(handle)
            origin = args.ranges_file
        else:
            raw = fetch_ranges()
            origin = 'published sources'
    except Exception as e:
        print('error: %s' % e, file=sys.stderr)
        return 1

    if args.save_ranges:
        with open(args.save_ranges, 'w') as handle:
            json.dump(raw, handle, indent=2, sort_keys=True)
        print('wrote %s' % args.save_ranges)
        return 0

    ranges = parse_ranges(raw)
    print('Crawler ranges from %s:' % origin)
    for name in sorted(ranges):
        print('    %-12s %d prefixes' % (name, len(ranges[name])))

    # ----------------------------------------
    # the firewall
    try:
        status = run_ufw_status(args.from_file)
    except Exception as e:
        print('error: %s' % e, file=sys.stderr)
        return 1

    rules = parse_ufw_status(status)
    denies = [r for r in rules if r['action'] == 'DENY' and r['network'] is not None]
    print('\nFirewall: %d rules, %d of them DENY with an address' % (len(rules), len(denies)))
    if not rules:
        print('error: no rules parsed -- is this `ufw status numbered` output?', file=sys.stderr)
        return 1

    full, partial, adjacent = classify(denies, ranges)

    # ----------------------------------------
    # the report
    if adjacent:
        print('\nIn the same /16 as crawler space but NOT published as a crawler address.')
        print('Not touched -- listed only so you can decide:')
        for rule in sorted(adjacent, key=lambda r: r['number'], reverse=True):
            print('    [%3d] %s' % (rule['number'], rule['source']))

    if partial:
        print('\nOverlap crawler space but also cover other addresses (%d):' % len(partial))
        for rule in sorted(partial, key=lambda r: r['number'], reverse=True):
            extra = sum(p.num_addresses for p in rule['leftover'])
            print('    [%3d] %-20s %-24s removing it would also unblock %d other address(es)'
                  % (rule['number'], rule['source'], ','.join(rule['crawlers']), extra))
        if not args.include_partial:
            print('    Left alone.  Pass --include-partial to remove these too.')

    targets = list(full)
    if args.include_partial:
        targets += partial

    if not full:
        print('\nNo rule blocks crawler space exclusively.')
    else:
        print('\nBlock crawler space and nothing else (%d):' % len(full))
        for rule in sorted(full, key=lambda r: r['number'], reverse=True):
            print('    [%3d] %-20s %s' % (rule['number'], rule['source'], ','.join(rule['crawlers'])))

    if not targets:
        print('\nNothing to remove.')
        return 0

    # ----------------------------------------
    # removal
    if not args.apply:
        print('\nDeleting highest number first, so the lower numbers stay valid:')
        for rule in sorted(targets, key=lambda r: r['number'], reverse=True):
            print('    ufw delete %d        # %s' % (rule['number'], rule['source']))
        print('\nNothing was changed.  Re-run with --apply (as root) to remove them.')
        return 2

    if args.from_file:
        print('\nerror: --apply needs the live firewall, not --from-file', file=sys.stderr)
        return 1

    if os.geteuid() != 0:
        print('\nerror: --apply needs root (try sudo)', file=sys.stderr)
        return 1

    print('\nDeleting %d rule(s), highest number first:' % len(targets))
    if not delete_rules(targets):
        return 1

    # ----------------------------------------
    # confirm
    leftover = classify(
        [r for r in parse_ufw_status(run_ufw_status()) if r['action'] == 'DENY' and r['network']],
        ranges,
    )[0]
    if leftover:
        print('\nStill blocking crawler space -- re-run:')
        for rule in leftover:
            print('    [%3d] %s' % (rule['number'], rule['source']))
        return 1

    print('\nDone.  No remaining rule blocks crawler space exclusively.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
