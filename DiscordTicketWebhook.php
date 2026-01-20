<?php

namespace Paymenter\Extensions\Others\paymenter_discord_webhook;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Extension;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Paymenter\Extensions\Others\paymenter_discord_webhook\Services;

#[ExtensionMeta(
    name: 'Discord Ticket Webhook',
    description: 'Discord webhook notifications for tickets (created/updated/replies) with per-department webhooks and mentions.',
    version: '1.0.1',
    author: 'ikketim',
    url: 'https://ikketim.nl',
    icon: 'https://raw.githubusercontent.com/ikketimnl/paymenter_discord_webhook/main/images/logo.png'
)]
class paymenter_discord_webhook extends Extension
{
    public function boot()
    {
        // For the Filament test page view
        View::addNamespace('discordticketwebhook', __DIR__ . '/resources/views');

        // Ticket created
        Event::listen(\App\Events\Ticket\Created::class, function ($event) {
            if (!$this->cfgBool('notify_ticket_created', true)) return;

            $ticket = $event->ticket;

            $payload = $this->payload(
                'created',
                "🎫 **New ticket created**: `#{$ticket->id}` — **{$ticket->subject}**",
                $ticket
            );

            $this->webhookForTicket($ticket)->send($payload);
        });

        // Ticket updated
        Event::listen(\App\Events\Ticket\Updated::class, function ($event) {
            if (!$this->cfgBool('notify_ticket_updated', true)) return;

            $ticket = $event->ticket;

            $changes = method_exists($ticket, 'getChanges') ? (array) $ticket->getChanges() : [];
            unset($changes['updated_at']);

            if (empty($changes)) return;

            // Map fields -> config flags (may vary per schema; "other" catches anything else)
            $fieldRules = [
                'status'        => 'notify_update_status',
                'subject'       => 'notify_update_subject',
                'department_id' => 'notify_update_department',
                'priority'      => 'notify_update_priority',
                'priority_id'   => 'notify_update_priority',
                'assigned_to'   => 'notify_update_assignee',
                'assignee_id'   => 'notify_update_assignee',
            ];

            $matchedFlags = [];
            foreach (array_keys($changes) as $field) {
                $flag = $fieldRules[$field] ?? 'notify_update_other';
                if ($this->cfgBool($flag, true)) {
                    $matchedFlags[$flag] = true;
                }
            }

            // If user disabled the relevant update types, skip
            if (empty($matchedFlags)) return;

            $typesLabel = implode(', ', array_map(function ($flag) {
                return match ($flag) {
                    'notify_update_status' => 'Status',
                    'notify_update_subject' => 'Subject',
                    'notify_update_department' => 'Department',
                    'notify_update_priority' => 'Priority',
                    'notify_update_assignee' => 'Assignee',
                    default => 'Other',
                };
            }, array_keys($matchedFlags)));

            $payload = $this->payload(
                'updated',
                "✏️ **Ticket updated**: `#{$ticket->id}` — **{$ticket->subject}**\n🧩 Types: **{$typesLabel}**",
                $ticket
            );

            // Add changed fields
            $diffLines = [];
            foreach ($changes as $field => $newValue) {
                $oldValue = method_exists($ticket, 'getOriginal') ? $ticket->getOriginal($field) : null;
                $diffLines[] = "`{$field}`: **" . (string)($oldValue ?? 'null') . "** → **" . (string)($newValue ?? 'null') . "**";
            }

            $payload['embeds'][0]['fields'][] = [
                'name' => 'Changed fields',
                'value' => $this->truncate(implode("\n", $diffLines), 950),
                'inline' => false,
            ];

            $this->webhookForTicket($ticket)->send($payload);
        });

        // Ticket reply posted
        Event::listen(\App\Events\TicketMessage\Created::class, function ($event) {
            if (!$this->cfgBool('notify_ticket_reply', true)) return;

            $msg = $event->ticketMessage;
            $ticket = $msg->ticket;

            $payload = $this->payload(
                'reply',
                "💬 **New reply** on ticket `#{$ticket->id}` — **{$ticket->subject}**",
                $ticket
            );

            if ($this->cfgBool('include_message_body', false)) {
                $payload['embeds'][0]['fields'][] = [
                    'name' => 'Message',
                    'value' => $this->truncate((string)($msg->message ?? ''), 900),
                    'inline' => false,
                ];
            }

            $author = $msg->user?->email ?? $msg->user?->name ?? null;
            if ($author) {
                $payload['embeds'][0]['fields'][] = [
                    'name' => 'Author',
                    'value' => (string)$author,
                    'inline' => true,
                ];
            }

            $this->webhookForTicket($ticket)->send($payload);
        });
    }

    public function getConfig($values = [])
    {
        $config = [
            [
                'name' => 'webhook_url',
                'label' => 'General Discord Webhook URL',
                'type' => 'text',
                'default' => '',
                'required' => true,
                'description' => 'Discord webhook URL to send notifications to.',
            ],
            [
                'name' => 'discord_username',
                'label' => 'Webhook Username (optional)',
                'type' => 'text',
                'default' => 'Paymenter',
            ],
            [
                'name' => 'discord_avatar_url',
                'label' => 'Webhook Avatar URL (optional)',
                'type' => 'text',
                'default' => '',
                'description' => 'If set, overrides the webhook avatar.',
            ],
            [
                'name' => 'ticket_url_template',
                'label' => 'Ticket URL Template',
                'type' => 'text',
                'default' => 'https://account.ikketim.nl/admin/tickets/{id}/edit',
                'description' => 'Supported tokens: {id}',
            ],

            // Notification toggles
            ['name' => 'notify_ticket_created', 'label' => 'Notify: Ticket created', 'type' => 'checkbox', 'default' => true],
            ['name' => 'notify_ticket_updated', 'label' => 'Notify: Ticket updated', 'type' => 'checkbox', 'default' => true],
            ['name' => 'notify_ticket_reply', 'label' => 'Notify: Ticket reply posted', 'type' => 'checkbox', 'default' => true],
            ['name' => 'include_message_body', 'label' => 'Include reply content in Discord', 'type' => 'checkbox', 'default' => false],

            // Update sub-types
            ['name' => 'notify_update_status', 'label' => 'Update type: Status changed', 'type' => 'checkbox', 'default' => true],
            ['name' => 'notify_update_subject', 'label' => 'Update type: Subject changed', 'type' => 'checkbox', 'default' => true],
            ['name' => 'notify_update_department', 'label' => 'Update type: Department changed', 'type' => 'checkbox', 'default' => true],
            ['name' => 'notify_update_priority', 'label' => 'Update type: Priority changed', 'type' => 'checkbox', 'default' => true],
            ['name' => 'notify_update_assignee', 'label' => 'Update type: Assignee changed', 'type' => 'checkbox', 'default' => true],
            ['name' => 'notify_update_other', 'label' => 'Update type: Other field changed', 'type' => 'checkbox', 'default' => true],

            // Mentions (global + per event)
            [
                'name' => 'default_mentions',
                'label' => 'Default mentions (global)',
                'type' => 'text',
                'default' => '',
                'description' => 'Comma-separated: role:ID, user:ID, <@...>, <@&...>',
            ],
            ['name' => 'mentions_on_created', 'label' => 'Extra mentions: Ticket created', 'type' => 'text', 'default' => ''],
            ['name' => 'mentions_on_updated', 'label' => 'Extra mentions: Ticket updated', 'type' => 'text', 'default' => ''],
            ['name' => 'mentions_on_reply', 'label' => 'Extra mentions: Ticket reply posted', 'type' => 'text', 'default' => ''],

            // Help block (SUPPORTED type: placeholder) :contentReference[oaicite:1]{index=1}
            [
                'name' => 'mentions_help',
                'label' => 'Mentions format help',
                'type' => 'placeholder',
                'default' => "Use comma-separated values:\nrole:123 -> <@&123>\nuser:456 -> <@456>\nOr raw mentions: <@123>, <@&456>",
            ],
        ];

        return array_merge($config, $this->departmentDynamicFields());
    }

    /**
     * Dynamic per-department fields: webhook routing + mentions.
     * Uses DB table to avoid relying on model class names.
     */
    private function departmentDynamicFields(): array
    {
        try {
            $departments = DB::table('ticket_departments')->orderBy('name')->get(['id', 'name']);
        } catch (\Throwable $e) {
            // If table name differs, don't hard-fail the settings page
            return [[
                'name' => 'departments_error',
                'label' => 'Departments not found',
                'type' => 'placeholder',
                'default' => 'Could not load departments from DB table "ticket_departments". If your table name differs, update the extension.',
            ]];
        }

        $fields = [
            [
                'name' => 'dept_help',
                'label' => 'Department routing & mentions',
                'type' => 'placeholder',
                'default' => 'For each department you can: (1) use general webhook or a custom webhook, and (2) set mentions.',
            ],
        ];

        foreach ($departments as $dept) {
            $fields[] = [
                'name' => "dept_use_general_webhook_{$dept->id}",
                'label' => "Use general webhook for: {$dept->name}",
                'type' => 'checkbox',
                'default' => true,
            ];

            $fields[] = [
                'name' => "dept_webhook_url_{$dept->id}",
                'label' => "Webhook URL for department: {$dept->name}",
                'type' => 'text',
                'default' => '',
                'description' => 'Used only if “Use general webhook” is disabled for this department.',
            ];

            $fields[] = [
                'name' => "mentions_department_{$dept->id}",
                'label' => "Mentions for department: {$dept->name}",
                'type' => 'text',
                'default' => '',
                'description' => 'Comma-separated: role:ID, user:ID, <@...>, <@&...>',
            ];
        }

        return $fields;
    }

    private function webhookForTicket($ticket): DiscordWebhook
    {
        $generalUrl = trim((string)$this->cfg('webhook_url', ''));

        $deptId = $ticket->department_id ?? $ticket->department?->id ?? null;
        if (!$deptId) {
            return new DiscordWebhook(
                webhookUrl: $generalUrl,
                username: $this->cfg('discord_username', 'Paymenter'),
                avatarUrl: $this->cfg('discord_avatar_url', null),
            );
        }

        $useGeneral = $this->cfgBool("dept_use_general_webhook_{$deptId}", true);
        $deptUrl = trim((string)$this->cfg("dept_webhook_url_{$deptId}", ''));

        $url = (!$useGeneral && $deptUrl !== '') ? $deptUrl : $generalUrl;

        // Safety fallback
        if ($url === '') {
            $url = $generalUrl !== '' ? $generalUrl : $deptUrl;
        }

        return new DiscordWebhook(
            webhookUrl: $url,
            username: $this->cfg('discord_username', 'Paymenter'),
            avatarUrl: $this->cfg('discord_avatar_url', null),
        );
    }

    private function payload(string $notificationType, string $content, $ticket): array
    {
        $url = $this->ticketUrl($ticket->id);
        $mentionsPrefix = $this->mentionsPrefixFor($notificationType, $ticket);

        $fields = [];

        $status = $ticket->status ?? null;
        if ($status) $fields[] = ['name' => 'Status', 'value' => (string)$status, 'inline' => true];

        $dept = $ticket->department?->name ?? null;
        if ($dept) $fields[] = ['name' => 'Department', 'value' => (string)$dept, 'inline' => true];

        $user = $ticket->user?->email ?? $ticket->user?->name ?? null;
        if ($user) $fields[] = ['name' => 'User', 'value' => (string)$user, 'inline' => true];

        $embed = [
            'title' => "Ticket #{$ticket->id}",
            'description' => (string)($ticket->subject ?? '(no subject)'),
            'fields' => $fields,
        ];

        if ($url) {
            $embed['url'] = $url; // clickable title
        }

        return [
            'content' => $mentionsPrefix . $content . ($url ? "\n🔗 {$url}" : ''),
            'embeds' => [$embed],
        ];
    }

    private function ticketUrl($id): ?string
    {
        $tpl = trim((string)$this->cfg('ticket_url_template', ''));
        if ($tpl === '') return null;
        return str_replace('{id}', (string)$id, $tpl);
    }

    private function mentionsPrefixFor(string $notificationType, $ticket): string
    {
        $deptMentions = $this->parseMentions($this->departmentMentionsRaw($ticket));
        $globalMentions = $this->parseMentions(trim((string)$this->cfg('default_mentions', '')));

        $base = !empty($deptMentions) ? $deptMentions : $globalMentions;

        $extra = match ($notificationType) {
            'created' => $this->parseMentions(trim((string)$this->cfg('mentions_on_created', ''))),
            'updated' => $this->parseMentions(trim((string)$this->cfg('mentions_on_updated', ''))),
            'reply'   => $this->parseMentions(trim((string)$this->cfg('mentions_on_reply', ''))),
            default   => [],
        };

        $all = array_values(array_unique(array_merge($base, $extra)));
        return empty($all) ? '' : (implode(' ', $all) . "\n");
    }

    private function departmentMentionsRaw($ticket): string
    {
        $deptId = $ticket->department_id ?? $ticket->department?->id ?? null;
        if (!$deptId) return '';
        return (string)$this->cfg("mentions_department_{$deptId}", '');
    }

    private function parseMentions(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') return [];

        $parts = array_map('trim', explode(',', $raw));
        $out = [];

        foreach ($parts as $p) {
            if ($p === '') continue;

            if (preg_match('/^<@&\d+>$/', $p) || preg_match('/^<@\d+>$/', $p)) {
                $out[] = $p;
                continue;
            }

            if (preg_match('/^role:(\d+)$/i', $p, $m)) {
                $out[] = '<@&' . $m[1] . '>';
                continue;
            }

            if (preg_match('/^user:(\d+)$/i', $p, $m)) {
                $out[] = '<@' . $m[1] . '>';
                continue;
            }

            if (preg_match('/^\d+$/', $p)) {
                $out[] = '<@' . $p . '>';
                continue;
            }
        }

        return array_values(array_unique($out));
    }

    private function truncate(string $s, int $max): string
    {
        $s = trim($s);
        if (mb_strlen($s) <= $max) return $s;
        return mb_substr($s, 0, $max - 1) . '…';
    }

    private function cfg(string $key, mixed $default = null): mixed
    {
        if (property_exists($this, 'config') && is_array($this->config) && array_key_exists($key, $this->config)) {
            return $this->config[$key];
        }
        if (property_exists($this, 'settings') && is_array($this->settings) && array_key_exists($key, $this->settings)) {
            return $this->settings[$key];
        }
        return $default;
    }

    private function cfgBool(string $key, bool $default): bool
    {
        $v = $this->cfg($key, $default);
        return filter_var($v, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool)$default;
    }
}
