<?php

use App\Services\Meta\MetaEventHasher;

test('email is lowercased, trimmed, then sha256 hashed', function () {
    $hasher = new MetaEventHasher();

    $expected = hash('sha256', 'jane@example.com');

    expect($hasher->hashEmail('  Jane@Example.com  '))->toBe($expected);
});

test('a null or blank email hashes to null', function () {
    $hasher = new MetaEventHasher();

    expect($hasher->hashEmail(null))->toBeNull()
        ->and($hasher->hashEmail('   '))->toBeNull();
});

test('phone is normalized to Nigerian international digits before hashing', function () {
    $hasher = new MetaEventHasher();

    $expected = hash('sha256', '2348012345678');

    expect($hasher->hashPhone('08012345678'))->toBe($expected)
        ->and($hasher->hashPhone('+234 801 234 5678'))->toBe($expected)
        ->and($hasher->hashPhone('2348012345678'))->toBe($expected);
});

test('a full name splits into first and last for separate hashing', function () {
    $hasher = new MetaEventHasher();

    expect($hasher->splitName('Jane Mary Doe'))->toBe(['first' => 'Jane', 'last' => 'Mary Doe'])
        ->and($hasher->splitName('Cher'))->toBe(['first' => 'Cher', 'last' => null])
        ->and($hasher->splitName(null))->toBe(['first' => null, 'last' => null]);
});

test('first and last name hashes match the lowercased sha256 of their split parts', function () {
    $hasher = new MetaEventHasher();

    expect($hasher->hashFirstName('Jane Doe'))->toBe(hash('sha256', 'jane'))
        ->and($hasher->hashLastName('Jane Doe'))->toBe(hash('sha256', 'doe'));
});

test('city is lowercased, trimmed, then hashed', function () {
    $hasher = new MetaEventHasher();

    expect($hasher->hashCity('  Uyo  '))->toBe(hash('sha256', 'uyo'));
});
