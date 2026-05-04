<?php

namespace App\Enums;

use App\Contracts\HasCommandOptions;
use App\Contracts\HasLabel;

enum AiProvider: string implements HasCommandOptions, HasLabel
{
    case OPENAI = 'openai';
    case ANTHROPIC = 'anthropic';
    case GEMINI = 'gemini';
    case AZURE = 'azure';
    case GROQ = 'groq';
    case XAI = 'xai';
    case DEEPSEEK = 'deepseek';
    case MISTRAL = 'mistral';
    case OLLAMA = 'ollama';

    public function getLabel(): string
    {
        return match ($this) {
            self::OPENAI => 'OpenAI',
            self::ANTHROPIC => 'Anthropic',
            self::GEMINI => 'Google Gemini',
            self::AZURE => 'Azure OpenAI',
            self::GROQ => 'Groq',
            self::XAI => 'xAI (Grok)',
            self::DEEPSEEK => 'DeepSeek',
            self::MISTRAL => 'Mistral AI',
            self::OLLAMA => 'Ollama (Local)',
        };
    }

    public static function getCommandOptions(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[] = $case->value;
        }

        return $options;
    }

    public static function getCommandOptionArrays(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[] = [
                'name' => $case->value,
                'description' => "Use {$case->getLabel()}",
            ];
        }

        return $options;
    }
}
