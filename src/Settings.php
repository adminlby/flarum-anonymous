<?php

namespace AdminLby\Anonymous;

use Flarum\Settings\SettingsRepositoryInterface;

final class Settings
{
    public const PREFIX = 'adminlby-anonymous.';
    public const DEFAULTS = [
        'enabled' => true,
        'discussions' => true,
        'replies' => true,
        'quotes' => true,
        'other' => true,
        'mode' => 'ask',
        'showOwn' => true,
        'editHistoryChoice' => false,
    ];

    public function __construct(private SettingsRepositoryInterface $settings)
    {
    }

    public function enabled(string $kind): bool
    {
        return $this->boolean('enabled') && $this->boolean($kind);
    }

    public function boolean(string $key): bool
    {
        return filter_var($this->settings->get(self::PREFIX.$key, self::DEFAULTS[$key] ?? false), FILTER_VALIDATE_BOOLEAN);
    }

    public function mode(): string
    {
        $mode = $this->settings->get(self::PREFIX.'mode', 'ask');

        return in_array($mode, ['ask', 'real', 'anonymous'], true) ? $mode : 'ask';
    }
}
