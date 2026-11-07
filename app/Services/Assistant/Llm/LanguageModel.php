<?php

namespace App\Services\Assistant\Llm;

/**
 * The one thing the assistant needs from an LLM: given the system prompt, the
 * tools and the conversation so far, the next assistant turn.
 *
 * Behind an interface so the assistant service never touches the SDK, and so
 * tests can script turns without calling the API.
 */
interface LanguageModel
{
    /**
     * @param  array<int, array<string, mixed>>  $system  Text blocks, SDK camelCase shape.
     * @param  array<int, array<string, mixed>>  $tools  Tool definitions, SDK camelCase shape.
     * @param  array<int, array<string, mixed>>  $messages  Conversation, SDK camelCase shape.
     *
     * @throws LanguageModelException
     */
    public function respond(array $system, array $tools, array $messages): ModelTurn;
}
