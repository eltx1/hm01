<?php

namespace App\Enums;

enum AiProvider: string
{
    case OpenAi = 'OPENAI';
    case Gemini = 'GEMINI';
    case Disabled = 'DISABLED';
}
