<?php

namespace Paymenter\Extensions\Others\paymenter-discord-webhook\Filament\Pages;

use Filament\Forms;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Others\paymenter-discord-webhook\src\Services\DiscordWebhook;

class DiscordTicketWebhookTest extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';
    protected static ?string $navigationGroup = 'Support';
    protected static ?string $navigationLabel = 'Discord Webhook Test';
    protected static ?string $title = 'Discord Webhook Test';

    protected static string $view = 'discordticketwebhook::filament.pages.discord-ticket-webhook-test';

    public ?array $data = [
        'department_id' => null,     // null = General
        'target' => 'resolved',      // resolved | general | department
        'message' => '✅ Paymenter DiscordTicketWebhook test message',
    ];

    public function mount(): void
    {
        $this->form->fill($this->data);
    }

    protected function getForms(): array
    {
        return [
            'form' => $this->makeForm()
                ->schema($this->getFormSchema())
                ->statePath('data'),
        ];
    }

    protected function getFormSchema(): array
    {
        return [
            Forms\Components\Select::make('department_id')
                ->label('Department (optional)')
                ->helperText('Pick a department to test its routing. Leave empty for General.')
                ->options($this->departmentOptions())
                ->searchable()
                ->nullable(),

            Forms\Components\Select::make('target')
                ->label('Send to')
                ->options([
                    'resolved' => 'Resolved (respects “Use general webhook” for department)',
                    'general' => 'General webhook only',
                    'department' => 'Department webhook only',
                ])
                ->required(),

            Forms\Components\Textarea::make('message')
                ->label('Test message')
                ->rows(4)
                ->required(),
        ];
    }

    public function sendTest(): void
    {
        $state = $this->form->getState();

        $deptId = $state['department_id'] ?? null;
        $target = $state['target'] ?? 'resolved';
        $message = (string)($state['message'] ?? '');

        $config = $this->loadExtensionConfig();

        $generalUrl = trim((string)($config['webhook_url'] ?? ''));

        $url = match ($target) {
            'general' => $generalUrl ?: null,
            'department' => $this->departmentWebhookUrlOnly($config, $deptId),
            default => $this->resolvedWebhookUrl($config, $deptId, $generalUrl),
        };

        if (!$url) {
            Notification::make()
                ->title('No webhook URL available')
                ->body('Configure the general webhook URL, or set a department webhook URL and choose that department.')
                ->danger()
                ->send();
            return;
        }

        try {
            (new DiscordWebhook(
                webhookUrl: $url,
                username: $config['discord_username'] ?? 'Paymenter',
                avatarUrl: $config['discord_avatar_url'] ?? null,
            ))->send([
                'content' => $message,
            ]);

            Notification::make()
                ->title('Test sent!')
                ->body("Target: {$target}" . ($deptId ? " (department ID {$deptId})" : ''))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Test failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('sendTest')
                ->label('Send test')
                ->action('sendTest')
                ->color('primary'),
        ];
    }

    private function departmentOptions(): array
    {
        try {
            return DB::table('ticket_departments')
                ->orderBy('name')
                ->pluck('name', 'id')
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Load this extension's stored config without depending on a specific model class.
     * Assumes an "extensions" table with "slug" and "config" (common in Paymenter setups).
     */
    private function loadExtensionConfig(): array
    {
        try {
            $row = DB::table('extensions')
                ->whereIn('slug', ['DiscordTicketWebhook', 'discordticketwebhook'])
                ->first();
        } catch (\Throwable $e) {
            $row = null;
        }

        $cfg = $row->config ?? [];

        if (is_string($cfg)) {
            $decoded = json_decode($cfg, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($cfg) ? $cfg : [];
    }

    private function departmentWebhookUrlOnly(array $config, ?int $deptId): ?string
    {
        if (!$deptId) return null;

        $deptUrl = trim((string)($config["dept_webhook_url_{$deptId}"] ?? ''));
        return $deptUrl !== '' ? $deptUrl : null;
    }

    private function resolvedWebhookUrl(array $config, ?int $deptId, string $generalUrl): ?string
    {
        if (!$deptId) {
            return $generalUrl !== '' ? $generalUrl : null;
        }

        $useGeneral = filter_var(($config["dept_use_general_webhook_{$deptId}"] ?? true), FILTER_VALIDATE_BOOL);
        $deptUrl = trim((string)($config["dept_webhook_url_{$deptId}"] ?? ''));

        if (!$useGeneral && $deptUrl !== '') return $deptUrl;

        // If general missing, allow dept as fallback
        if ($generalUrl !== '') return $generalUrl;
        if ($deptUrl !== '') return $deptUrl;

        return null;
    }
}
