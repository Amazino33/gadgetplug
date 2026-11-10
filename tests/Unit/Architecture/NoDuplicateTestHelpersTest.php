<?php

/*
 * Pest test files share one PHP process, so a helper function declared at the
 * top level of two different files is a fatal "Cannot redeclare" the moment
 * both are loaded — and the whole suite stops before a single test runs. That
 * happened with makePosSale() and createVendorWithStore() (ab1860d), and it
 * only showed on a full run: either file on its own was fine.
 *
 * This finds every global function declared under tests/ and fails if a name
 * is declared in more than one file. Prefix helpers with something from their
 * file (assistantQueriesPosSale, cashUpContext) or share them through a
 * Helpers.php loaded with require_once.
 *
 * Functions inside `if (! function_exists(...))` are declared conditionally
 * and cannot collide, so only unconditional top-level declarations count.
 */

/**
 * @return array<string, array<int, string>> function name (lowercased, as PHP compares them) => files
 */
function globalTestFunctions(string $testsDir): array
{
    $found = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testsDir, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(dirname($testsDir)) + 1));

        foreach (topLevelFunctionNames(file_get_contents($file->getPathname())) as $name) {
            $found[strtolower($name)][] = $relative;
        }
    }

    return $found;
}

/**
 * Names of functions declared at brace depth 0 — not inside a class, a closure
 * passed to test(), or a function_exists() guard.
 *
 * @return array<int, string>
 */
function topLevelFunctionNames(string $source): array
{
    $tokens = token_get_all($source);
    $depth = 0;
    $names = [];

    foreach ($tokens as $i => $token) {
        if (! is_array($token)) {
            if ($token === '{') {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
            }

            continue;
        }

        if (in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            $depth++;

            continue;
        }

        if ($token[0] !== T_FUNCTION || $depth !== 0) {
            continue;
        }

        // The next meaningful token is the name for a declaration; a "(" or
        // "&(" means an anonymous function (e.g. `$f = function () {}`).
        for ($j = $i + 1; isset($tokens[$j]); $j++) {
            $next = $tokens[$j];

            if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            // `function &name()`: PHP 8 tokenizes that & as an array token
            // (T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG), older PHP as '&'.
            if ($next === '&' || (is_array($next) && $next[1] === '&')) {
                continue;
            }

            if (is_array($next) && $next[0] === T_STRING) {
                $names[] = $next[1];
            }

            break;
        }
    }

    return $names;
}

test('no global helper function is declared in more than one test file', function () {
    $duplicates = array_filter(globalTestFunctions(dirname(__DIR__, 2)), fn (array $files) => count($files) > 1);

    $report = collect($duplicates)
        ->map(fn (array $files, string $name) => "{$name}(): ".implode(', ', $files))
        ->values()
        ->all();

    expect($report)->toBe([]);
});

test('the scan sees top-level helpers and ignores closures, methods and guarded declarations', function () {
    $source = <<<'PHP'
<?php
function plainHelper(): int { return 1; }
function &byRefHelper(): array { static $a = []; return $a; }
if (! function_exists('guardedHelper')) {
    function guardedHelper() {}
}
class Fixture { public function method() {} }
test('x', function () {
    $inner = function () {};
});
$f = function () {};
$g = fn () => "{$f}";
PHP;

    expect(topLevelFunctionNames($source))->toBe(['plainHelper', 'byRefHelper']);
});
