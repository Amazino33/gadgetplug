<?php

// Filament passes a table query into a closure BY NAME: the parameter must be
// called $query. Name it anything else and Filament builds a brand-new,
// model-less Builder from the container and passes that instead. A tab
// (modifyQueryUsing) then crashes the page, and a filter (->query) quietly
// filters the throwaway builder and does nothing. Both happened: every
// Dispatch tab but "All" crashed, and three vendor filters were dead.
//
// This reads the source rather than rendering every page, so it covers screens
// no test happens to open. Ternary filters' queries(true:, false:) are called
// positionally and are fine with any name, so they are not checked.

test('every Filament query closure names its query $query', function () {
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament'), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        preg_match_all(
            '/(?:->modifyQueryUsing|->modifyBaseQueryUsing|->query)\(\s*(?:static\s+)?(?:fn|function)\s*\(\s*\??[\\\w]*Builder\s+\$(\w+)/',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        foreach ($matches[1] as [$name, $offset]) {
            if ($name !== 'query') {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname())
                    .':'.(substr_count(substr($source, 0, $offset), "\n") + 1)." (\${$name})";
            }
        }
    }

    expect($offenders)->toBe([], "Rename these to \$query:\n  ".implode("\n  ", $offenders));
});
