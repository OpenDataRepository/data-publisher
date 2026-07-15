<?php
/**
 * validate_instance_config.php
 *
 * Validates a linked / WordPress-integrated ODR instance's app/config directory against the
 * canonical config in *this* (source) checkout, and prints the changes required to bring the
 * instance up to the SF7 baseline.
 *
 * WHY THIS EXISTS
 *   Linked instances keep their OWN copies of app/config (they do NOT symlink/pull from the source
 *   tree), so upgrade fixes made to the source config never reach them. They then fail at kernel
 *   boot, one stale file at a time. This script diffs an instance's config against the source in a
 *   single pass. It intentionally has ZERO dependency on the app booting (the whole point is that a
 *   broken instance can't boot) -- it only needs symfony/yaml, loaded from the source vendor.
 *
 * USAGE
 *   php app/config/validate_instance_config.php <instance-path> [--reference=<dir>] [--fix]
 *
 *     <instance-path>   Path to the instance root (e.g. /home/rruff/data-publisher) OR directly to
 *                       its app/config dir. Both are accepted.
 *     --reference=<dir> Canonical app/config to compare against. Defaults to the directory this
 *                       script lives in (i.e. the source tree's app/config).
 *     --fix             Copy the canonical version of every STALE/MISSING *tracked* file into the
 *                       instance. (Never touches the .dist-backed live files -- those hold
 *                       instance-specific values and must be merged by hand.)
 *
 * EXIT CODE
 *   0 = instance is in sync (no required changes); 1 = drift found (or --fix applied changes).
 *
 * TWO CLASSES OF FILE (see app/config/.gitignore):
 *   TRACKED   -- must be byte-identical to the source's committed copy. Safe to overwrite.
 *   DIST      -- gitignored live files (config/security/routing/parameters) that carry
 *                instance-specific values; compared STRUCTURALLY against their .dist template
 *                (missing keys / removed keys / changed values) so local values don't create noise.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

// ---- locate symfony/yaml (source vendor; fall back to the instance's) -----------------------
$autoloads = [
    __DIR__ . '/../../vendor/autoload.php',                 // source tree (script lives in app/config)
];
$loaded = false;
foreach ($autoloads as $a) {
    if (is_file($a)) { require $a; $loaded = true; break; }
}
if (!$loaded || !class_exists('Symfony\\Component\\Yaml\\Yaml')) {
    fwrite(STDERR, "ERROR: could not load symfony/yaml. Run this from a source checkout with vendor/ installed.\n");
    exit(2);
}
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

// ---- files the instance must keep identical to the source's committed copy -------------------
const TRACKED = [
    'config_dev.yml', 'config_prod.yml', 'config_test.yml',
    'doctrine_extensions.yml', 'routing_dev.yml', 'routing_prefixed.yml',
    'security.openapi',
];
// ---- gitignored live files <=> their .dist template (structural comparison) ------------------
const DIST = [
    'config.yml'     => 'config.yml.dist',
    'security.yml'   => 'security.yml.dist',
    'routing.yml'    => 'routing.yml.dist',
    'parameters.yml' => 'parameters.yml.dist',
];
// parameters.yml is ALL instance-specific values -> compare keys only, never flag value diffs.
const KEYS_ONLY = ['parameters.yml'];

// ---- args -----------------------------------------------------------------------------------
$args = array_slice($argv, 1);
$instanceArg = null; $reference = __DIR__; $fix = false;
foreach ($args as $arg) {
    if ($arg === '--fix') { $fix = true; }
    elseif (str_starts_with($arg, '--reference=')) { $reference = rtrim(substr($arg, 12), '/'); }
    elseif (!str_starts_with($arg, '--')) { $instanceArg = rtrim($arg, '/'); }
}
if ($instanceArg === null) {
    fwrite(STDERR, "usage: php " . basename(__FILE__) . " <instance-path> [--reference=<dir>] [--fix]\n");
    exit(2);
}
// accept either the instance root or its app/config directory
$instance = is_dir($instanceArg . '/app/config') ? $instanceArg . '/app/config' : $instanceArg;
if (!is_dir($instance)) { fwrite(STDERR, "ERROR: instance config dir not found: $instance\n"); exit(2); }
if (!is_dir($reference)) { fwrite(STDERR, "ERROR: reference config dir not found: $reference\n"); exit(2); }
if (realpath($instance) === realpath($reference)) {
    fwrite(STDERR, "ERROR: instance and reference resolve to the same directory (is the instance symlinked to source?).\n");
    exit(2);
}

// ---- helpers --------------------------------------------------------------------------------
function c(string $s, string $code): string { // ANSI colour, auto-off when not a TTY
    static $tty = null; if ($tty === null) $tty = function_exists('posix_isatty') ? @posix_isatty(STDOUT) : getenv('TERM');
    return $tty ? "\033[{$code}m{$s}\033[0m" : $s;
}
function ok($s){return c($s,'32');} function bad($s){return c($s,'31');} function warn($s){return c($s,'33');} function head($s){return c($s,'1;36');}

/** flatten nested YAML into dotted paths => scalar (lists use [i]) */
function flatten($data, string $prefix = ''): array {
    $out = [];
    if (!is_array($data)) { return [$prefix => $data]; }
    $isList = array_is_list($data);
    foreach ($data as $k => $v) {
        $key = $isList ? "{$prefix}[{$k}]" : ($prefix === '' ? (string)$k : "{$prefix}.{$k}");
        if (is_array($v)) { $out += flatten($v, $key); }
        else { $out[$key] = $v; }
    }
    return $out;
}
function scalarStr($v): string {
    if (is_bool($v)) return $v ? 'true' : 'false';
    if (is_null($v)) return '~';
    return (string)$v;
}
/** unified diff via the system `diff` (present on every target box); falls back to a note */
function unifiedDiff(string $refFile, string $instFile): string {
    $out = []; $rc = 0;
    @exec('diff -u ' . escapeshellarg($refFile) . ' ' . escapeshellarg($instFile) . ' 2>/dev/null', $out, $rc);
    return $out ? implode("\n", array_slice($out, 2)) : '(files differ; `diff` unavailable for detail)';
}

// ---- run ------------------------------------------------------------------------------------
echo head("ODR instance config validator\n");
echo "  instance : {$instance}\n";
echo "  reference: {$reference}\n\n";

$issues = 0; $fixes = [];

// === 1. TRACKED files: must be byte-identical =================================================
echo head("[1] Tracked files (must match source exactly)\n");
foreach (TRACKED as $f) {
    $ref = "$reference/$f"; $ins = "$instance/$f";
    if (!is_file($ref)) { echo "  " . warn("skip") . "  $f  (not present in reference)\n"; continue; }
    if (!is_file($ins)) {
        echo "  " . bad("MISSING") . "  $f\n"; $issues++; $fixes[] = [$ref, $ins]; continue;
    }
    // normalise trailing whitespace/newline so cosmetic EOL diffs aren't flagged
    $rn = rtrim(file_get_contents($ref)); $in = rtrim(file_get_contents($ins));
    if ($rn === $in) { echo "  " . ok("ok") . "      $f\n"; }
    else {
        echo "  " . bad("STALE") . "   $f\n"; $issues++; $fixes[] = [$ref, $ins];
        foreach (explode("\n", unifiedDiff($ref, $ins)) as $line) echo "          | $line\n";
    }
}

// === 2. DIST-backed live files: structural comparison ========================================
echo "\n" . head("[2] Live files vs .dist template (structural)\n");
foreach (DIST as $live => $dist) {
    $ref = "$reference/$dist"; $ins = "$instance/$live";
    echo "  " . head($live) . "  (template: $dist)\n";
    if (!is_file($ref)) { echo "    " . warn("skip") . " no reference template ($dist)\n"; continue; }
    if (!is_file($ins)) { echo "    " . bad("MISSING") . " instance file absent\n"; $issues++; continue; }

    // parse-check the instance file with the SAME parser the app uses -> catches unquoted %, tabs, etc.
    try { $insData = Yaml::parseFile($ins) ?? []; }
    catch (ParseException $e) {
        echo "    " . bad("PARSE ERROR") . " " . $e->getMessage() . "\n";
        echo "               (this is exactly what the kernel would throw at boot)\n";
        $issues++; continue;
    }
    try { $refData = Yaml::parseFile($ref) ?? []; }
    catch (ParseException $e) { echo "    " . warn("reference template unparseable: " . $e->getMessage()) . "\n"; continue; }

    $rf = flatten($refData); $if = flatten($insData);
    $keysOnly = in_array($live, KEYS_ONLY, true);

    $missing = array_diff_key($rf, $if);              // in template, absent from instance -> ADD
    $extra   = array_diff_key($if, $rf);              // in instance, gone from template   -> REMOVE/REVIEW
    $changed = [];                                    // shared key, different value       -> REVIEW
    if (!$keysOnly) {
        foreach (array_intersect_key($rf, $if) as $k => $v) {
            if (scalarStr($v) !== scalarStr($if[$k])) $changed[$k] = [scalarStr($v), scalarStr($if[$k])];
        }
    }

    if (!$missing && !$extra && !$changed) { echo "    " . ok("ok") . " structurally in sync\n"; continue; }
    $issues++;

    if ($missing) {
        echo "    " . bad("MISSING keys") . " (present in $dist, add to instance):\n";
        foreach ($missing as $k => $v) echo "        + $k: " . scalarStr($v) . "\n";
    }
    if ($extra) {
        echo "    " . warn("EXTRA keys") . " (not in $dist -- likely removed by the upgrade, review/delete):\n";
        foreach ($extra as $k => $v) echo "        - $k: " . scalarStr($v) . "\n";
    }
    if ($changed) {
        echo "    " . warn("CHANGED values") . " (template differs; adopt unless it's a local value):\n";
        foreach ($changed as $k => $pair) echo "        ~ $k: " . $pair[1] . "  ->  " . $pair[0] . "\n";
    }
    if ($keysOnly) echo "    (value differences suppressed -- $live holds instance-specific values)\n";
}

// === 3. summary / remediation ================================================================
echo "\n" . head("Summary\n");
if ($issues === 0) {
    echo "  " . ok("Instance config is in sync. Nothing to change.") . "\n";
    exit(0);
}
echo "  " . bad("$issues file(s) need attention.") . "\n\n";

if ($fixes) {
    if ($fix) {
        echo head("Applying --fix to tracked files:\n");
        foreach ($fixes as [$ref, $ins]) {
            if (@copy($ref, $ins)) echo "  " . ok("copied") . " " . basename($ins) . "\n";
            else { echo "  " . bad("FAILED") . " to copy " . basename($ins) . " (permissions?)\n"; }
        }
        echo "\n  Tracked files fixed. Re-run without --fix to confirm, then hand-merge the [2] items above.\n";
    } else {
        echo "  Tracked files can be synced automatically. Re-run with --fix, or copy manually:\n";
        foreach ($fixes as [$ref, $ins]) echo "    cp " . escapeshellarg($ref) . " " . escapeshellarg($ins) . "\n";
        echo "\n  The [2] live files must be MERGED by hand (they hold instance-specific values).\n";
    }
}
exit(1);
