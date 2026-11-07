<?php

use App\Services\Assistant\Tools\FlagUnansweredTool;
use App\Services\Assistant\Tools\SalesTodayTool;

return [

    /*
    |--------------------------------------------------------------------------
    | Ask GadgetPlug — the vendor business assistant
    |--------------------------------------------------------------------------
    |
    | The model is a setting, not code, so it can be moved to a newer or
    | cheaper one without a deploy that touches PHP. ProductImageAiAnalyzer
    | keeps its own model and is not driven by this file.
    |
    */

    'model' => env('ASSISTANT_MODEL', 'claude-sonnet-5-5'),

    // How hard the model thinks before answering. 'medium' suits short
    // look-up questions that may still need two or three tool calls; raise it
    // only if answers measurably improve.
    'effort' => env('ASSISTANT_EFFORT', 'medium'),

    // Thinking counts toward this, so it is well above a chat answer's length.
    'max_tokens' => (int) env('ASSISTANT_MAX_TOKENS', 8000),

    // Server-side refusal fallback: when the model declines on a policy
    // classifier, the API re-runs the request on its default fallback model
    // inside the same call. Claude API only.
    'fallbacks' => (bool) env('ASSISTANT_FALLBACKS', true),

    // Seconds per model request. A panel question runs inside a web request,
    // so this is kept well under PHP's own limit.
    'timeout' => (float) env('ASSISTANT_TIMEOUT', 45),

    // One question may need several look-ups ("compare my branches" calls a
    // tool per branch). The loop stops here rather than running unbounded.
    'max_tool_rounds' => 6,

    // How many earlier turns of the conversation are replayed. Text only —
    // tool calls are never replayed, so a follow-up re-reads live figures.
    'history_messages' => 20,

    // The most rows any single tool result may hand the model.
    'row_cap' => 25,

    // Questions per vendor per Lagos day. A vendor's own
    // vendors.assistant_daily_cap overrides it when set.
    'daily_question_cap' => (int) env('ASSISTANT_DAILY_CAP', 100),

    // Tools the assistant may call, in the order the model sees them. Each is
    // read-only and checks its own permission; see AssistantTool.
    'tools' => [
        SalesTodayTool::class,
        FlagUnansweredTool::class,
    ],

];
