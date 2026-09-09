<?php

// A bare double quote inside x-data="…" ends the attribute where it sits, and
// the browser renders the rest of the component as visible text on the page.
// It happened on the counting screen: three quotes, two of them in COMMENTS,
// turned the whole screen into a wall of source code. Nobody proofreads a
// comment for syntax, every Livewire test still passed, and it reached
// production.
//
// This walks every Blade view in the repo rather than that one screen, because
// six of them carry inline Alpine components and any of them can break the same
// way. It reads the files rather than rendering them, so it costs nothing and
// covers views no test happens to render.

/**
 * Blanks out anything Blade evaluates before the browser ever sees it.
 *
 * A quote inside @js(...) or {{ ... }} is a PHP string delimiter, consumed at
 * render time — it never reaches the attribute as a literal quote. Scanning
 * raw source without this flags Filament's own published views, which are
 * perfectly correct.
 *
 * Replaced with spaces rather than removed so every remaining character keeps
 * its original offset, and the line numbers reported below stay true.
 */
function blankBladeExpressions(string $source): string
{
    // One level of nesting, so @js($this->foo()) is consumed whole.
    $patterns = [
        '/@[a-zA-Z]+\((?:[^()]|\([^()]*\))*\)/',
        '/\{\{.*?\}\}/s',
        '/\{!!.*?!!\}/s',
        '/@php.*?@endphp/s',
    ];

    foreach ($patterns as $pattern) {
        $source = preg_replace_callback(
            $pattern,
            fn (array $m) => preg_replace('/[^\n]/', ' ', $m[0]),
            $source,
        );
    }

    return $source;
}

/**
 * Every x-data attribute in the repo, as [file, line, value-as-the-browser-
 * reads-it, whether it terminated where the author intended].
 *
 * The browser ends the attribute at the first double quote after x-data=". So
 * the check is: walking from the opening brace, do the braces balance before
 * that quote is reached? If they do not, the attribute was cut short and
 * everything after it is loose on the page.
 *
 * @return array<int, array{file: string, line: int, truncated: bool}>
 */
function inlineAlpineAttributes(): array
{
    $found = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $source = blankBladeExpressions(file_get_contents($file->getPathname()));
        $offset = 0;

        while (($at = strpos($source, 'x-data="', $offset)) !== false) {
            $start  = $at + strlen('x-data="');
            $offset = $start;

            // Only object-literal components can be truncated mid-expression;
            // x-data="someFunction()" has nothing to balance.
            $brace = strpos($source, '{', $start);
            $quote = strpos($source, '"', $start);

            if ($brace === false || ($quote !== false && $quote < $brace)) {
                continue;
            }

            $depth     = 0;
            $truncated = true;

            for ($i = $brace; $i < strlen($source); $i++) {
                $char = $source[$i];

                if ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;

                    // Balanced. The attribute should close right about here.
                    if ($depth === 0) {
                        $rest = ltrim(substr($source, $i + 1, 4));
                        $truncated = ! str_starts_with($rest, '"');
                        break;
                    }
                } elseif ($char === '"') {
                    // A quote reached while still inside the object: the
                    // browser stops the attribute here, mid-expression.
                    $truncated = true;
                    break;
                }
            }

            $found[] = [
                'file'      => str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname()),
                'line'      => substr_count(substr($source, 0, $at), "\n") + 1,
                'truncated' => $truncated,
            ];
        }
    }

    return $found;
}

test('no inline Alpine component is cut short by a stray double quote', function () {
    $broken = array_values(array_filter(
        inlineAlpineAttributes(),
        fn (array $a) => $a['truncated'],
    ));

    $detail = implode("\n", array_map(
        fn (array $a) => "  {$a['file']}:{$a['line']}",
        $broken,
    ));

    expect($broken)->toBe([], $detail === ''
        ? ''
        : "A double quote inside x-data=\"…\" ends the attribute early, and the browser "
          . "renders the rest of the component as text on the page. Use single quotes "
          . "instead — in comments too:\n{$detail}");
});

test('the repo is actually being scanned, so a passing result means something', function () {
    // Without this the check above passes just as happily on nothing at all.
    expect(count(inlineAlpineAttributes()))->toBeGreaterThan(3);
});
