<?php

namespace App\Services\Assistant\Llm;

use RuntimeException;

/** The model could not be reached or refused the request outright. */
final class LanguageModelException extends RuntimeException {}
