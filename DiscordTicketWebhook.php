<?php

namespace Paymenter\Extensions\Others\paymenter-discord-webhook;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Extension;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Paymenter\Extensions\Others\paymenter-discord-webhook\src\Services\DiscordWebhook;

#[ExtensionMeta(
    name: 'Discord Ticket Webhook',
    description: 'Discord notifications for ticket create/update/reply with per-department mentions and per-department webhook routing.',
    version: '1.0.0',
    author: 'ikketim',
    url: 'https://ikketim.nl',
    icon: 'https://ikketim.nl/wp-content/uploads/2025/09/cropped-cropped-ikketim-logo-new-300x300-removebg-preview.png'
)]
class DiscordTicketWebhook extends Extension
{
    public function boot()
    {
        // Register extension view namespace for Filament page
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

        // Ticket updated (status/subject/department/etc.)
        Event::listen(\App\Events\Ticket\Updated::class, function ($event) {
            if (!$this->cfgBool('notify_ticket_updated', true)) return;

            $ticket = $event->ticket;

            $changes = method_exists($ticket, 'getChanges') ? $ticket->getChanges() : [];
            unset($changes['updated_at']);

            if (empty($changes)) return;

            // Map fields -> config flags (adjust if your DB columns differ)
            $fieldRules = [
                'status'        => 'notify_update_status',
                'subject'       => 'notify_update_subject',
                'department_id' => 'notify_update_department',
                'priority'      => 'notify_update_priority',
                'priority_id'   => 'notify_update_priority',
                'assigned_to'   => 'notify_update_assignee',
                'assignee_id'   => 'notify_update_assignee',
            ];

            $matched = false;
            $matchedTypes = [];

            foreach (array_keys($changes) as $field) {
                $flag = $fieldRules[$field] ?? 'notify_update_other';
                if ($this->cfgBool($flag, true)) {
                    $matched = true;
                    $matchedTypes[$flag] = true;
                }
            }

            // All changed fields were disabled by settings
            if (!$matched) return;

            $typesLabel = implode(', ', array_map(function ($flag) {
                return match ($flag) {
                    'notify_update_status' => 'Status',
                    'notify_update_subject' => 'Subject',
                    'notify_update_department' => 'Department',
                    'notify_update_priority' => 'Priority',
                    'notify_update_assignee' => 'Assignee',
                    default => 'Other',
                };
            }, array_keys($matchedTypes)));

            $payload = $this->payload(
                'updated',
                "✏️ **Ticket updated**: `#{$ticket->id}` — **{$ticket->subject}**\n🧩 Types: **{$typesLabel}**",
                $ticket
            );

            // Add diff field
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

        // Ticket reply posted (TicketMessage created)
        Event::listen(\App\Events\TicketMessage\Created::class, function ($event) {
            if (!$this->cfgBool('notify_ticket_reply', true)) return;

            $msg = $event->ticketMessage;
            $ticket = $msg->ticket;

            $payload = $this->payload(
                'reply',
                "💬 **New reply** on ticket `#{$ticket->id}` — **{$ticket->subject}**",
                $ticket
            );

            $includeBody = $this->cfgBool('include_message_body', false);

            $author = $msg->user?->email ?? $msg->user?->name ?? null;
            if ($author) {
                $payload['embeds'][0]['fields'][] = [
                    'name' => 'Author',
                    'value' => (string)$author,
                    'inline' => true,
                ];
            }

            if ($includeBody) {
                $payload['embeds'][0]['fields'][] = [
                    'name' => 'Message',
                    'value' => $this->truncate((string)($msg->message ?? ''), 900),
                    'inline' => false,
                ];
            }

            $this->webhookForTicket($ticket)->send($payload);
        });
    }

    public function getConfig($values = [])
    {
        return array_merge(
            [
                // General webhook
                [
                    'name' => 'webhook_url',
                    'label' => 'General Discord Webhook URL',
                    'type' => 'text',
                    'default' => '',
                    'required' => true,
                    'validation' => 'url',
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
                    'validation' => 'nullable|url',
                ],
                [
                    'name' => 'ticket_url_template',
                    'label' => 'Ticket URL Template',
                    'type' => 'text',
                    'default' => 'https://account.ikketim.nl/admin/tickets/{id}/edit',
                    'description' => 'Supported tokens: {id}',
                ],

                // Notification type toggles
                [
                    'name' => 'notify_ticket_created',
                    'label' => 'Notify: Ticket created',
                    'type' => 'checkbox',
                    'default' => true,
                ],
                [
                    'name' => 'notify_ticket_updated',
                    'label' => 'Notify: Ticket updated',
                    'type' => 'checkbox',
                    'default' => true,
                ],
                [
                    'name' => 'notify_ticket_reply',
                    'label' => 'Notify: Ticket reply posted',
                    'type' => 'checkbox',
                    'default' => true,
                ],
                [
                    'name' => 'include_message_body',
                    'label' => 'Include reply content in Discord',
                    'type' => 'checkbox',
                    'default' => false,
                ],

                // Update sub-types
                [
                    'name' => 'notify_update_status',
                    'label' => 'Update type: Status changed',
                    'type' => 'checkbox',
                    'default' => true,
                ],
                [
                    'name' => 'notify_update_subject',
                    'label' => 'Update type: Subject changed',
                    'type' => 'checkbox',
                    'default' => true,
                ],
                [
                    'name' => 'notify_update_department',
                    'label' => 'Update type: Department changed',
                    'type' => 'checkbox',
                    'default' => true,
                ],
                [
                    'name' => 'notify_update_priority',
                    'label' => 'Update type: Priority changed',
                    'type' => 'checkbox',
                    'default' => true,
                ],
                [
                    'name' => 'notify_update_assignee',
                    'label' => 'Update type: Assignee changed',
                    'type' => 'checkbox',
                    'default' => true,
                ],
                [
                    'name' => 'notify_update_other',
                    'label' => 'Update type: Other field changed',
                    'type' => 'checkbox',
                    'default' => true,
                ],

                // Mentions: global + per-type
                [
                    'name' => 'default_mentions',
                    'label' => 'Default mentions (global)',
                    'type' => 'text',
                    'default' => '',
                    'description' => 'Used if the department has no mentions set (or ticket has no department). Comma-separated: role:ID, user:ID, or raw <@...>.',
                ],
                [
                    'name' => 'mentions_on_created',
                    'label' => 'Extra mentions: Ticket created',
                    'type' => 'text',
                    'default' => '',
                    'description' => 'Additional mentions only for “ticket created”. Same format.',
                ],
                [
                    'name' => 'mentions_on_updated',
                    'label' => 'Extra mentions: Ticket updated',
                    'type' => 'text',
                    'default' => '',
                    'description' => 'Additional mentions only for “ticket updated”. Same format.',
                ],
                [
                    'name' => 'mentions_on_reply',
                    'label' => 'Extra mentions: Ticket reply posted',
                    'type' => 'text',
                    'default' => '',
                    'description' => 'Additional mentions only for “ticket reply”. Same format.',
                ],
            ],
            // Dynamic per-department fields (auto-updates when depts change)
            $this->departmentDynamicFields()
        );
    }

    /**
     * Dynamic fields for each department:
     * - use general webhook (default true)
     * - department webhook url
     * - mentions
     */
    private function departmentDynamicFields(): array
    {
        try {
            $departments = DB::table('ticket_departments')->orderBy('name')->get(['id', 'name']);
        } catch (\Throwable $e) {
            // Graceful fallback
            return [[
                'name' => 'department_config_fallback',
                'label' => 'Department config fallback',
                'type' => 'textarea',
                'default' => '',
                'description' => 'Could not load departments from DB table ticket_departments. If your install uses a different table name, update the extension.',
                'required' => false,
            ]];
        }

        $fields = [[
            'name' => 'department_fields_help',
            'label' => 'Department settings',
            'type' => 'description',
            'default' => '',
            'description' =>
                "Per department you can set:\n" .
                "- Mentions (comma-separated)\n" .
                "- Whether to use the general webhook (default ON)\n" .
                "- A department-specific webhook URL (used when general is OFF)\n",
        ]];

        foreach ($departments as $dept) {
            $fields[] = [
                'name' => "dept_use_general_webhook_{$dept->id}",
                'label' => "Use general webhook for: {$dept->name}",
                'type' => 'checkbox',
                'default' => true,
                'description' => 'If enabled, notifications go to the general webhook URL.',
                'required' => false,
            ];

            $fields[] = [
                'name' => "dept_webhook_url_{$dept->id}",
                'label' => "Webhook URL for department: {$dept->name}",
                'type' => 'text',
                'default' => '',
                'validation' => 'nullable|url',
                'description' => 'Used only if “Use general webhook” is disabled for this department.',
                'required' => false,
            ];

            $fields[] = [
                'name' => "mentions_department_{$dept->id}",
                'label' => "Mentions for department: {$dept->name}",
                'type' => 'text',
                'default' => '',
                'description' => 'Comma-separated: role:ID, user:ID, or raw <@...> mentions',
                'required' => false,
            ];
        }

        return $fields;
    }

    /**
     * Resolve webhook sender based on department settings:
     * - If dept "use general" checked => general webhook
     * - Else dept webhook (if set)
     * - Always safe fallback to general
     */
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

        // safety fallback
        if ($url === '') $url = $generalUrl;

        return new DiscordWebhook(
            webhookUrl: $url,
            username: $this->cfg('discord_username', 'Paymenter'),
            avatarUrl: $this->cfg('discord_avatar_url', null),
        );
    }

    private function payload(string $notificationType, string $content, $ticket): array
    {
        $fields = [];

        $status = $ticket->status ?? null;
        if ($status) $fields[] = ['name' => 'Status', 'value' => (string)$status, 'inline' => true];

        $dept = $ticket->department?->name ?? null;
        if ($dept) $fields[] = ['name' => 'Department', 'value' => (string)$dept, 'inline' => true];

        $user = $ticket->user?->email ?? $ticket->user?->name ?? null;
        if ($user) $fields[] = ['name' => 'User', 'value' => (string)$user, 'inline' => true];

        $url = $this->ticketUrl($ticket->id);
        $mentionsPrefix = $this->mentionsPrefixFor($notificationType, $ticket);

        return [
            'content' => $mentionsPrefix . $content . ($url ? "\n🔗 {$url}" : ''),
            'embeds' => [[
                'title' => "Ticket #{$ticket->id}",
                'url' => $url,
                'description' => (string)($ticket->subject ?? '(no subject)'),
                'fields' => $fields,
            ]],
        ];
    }

    private function ticketUrl($id): ?string
    {
        $tpl = trim((string)$this->cfg('ticket_url_template', ''));
        if ($tpl === '') return null;
        return str_replace('{id}', (string)$id, $tpl);
    }

    /**
     * Mentions priority:
     * 1) Department mentions if set
     * 2) Else global default mentions
     * 3) Always add per-notification-type extra mentions
     */
    private function mentionsPrefixFor(string $notificationType, $ticket): string
    {
        $deptMentions = $this->parseMentions((string)$this->cfg($this->departmentMentionKey($ticket), ''));
        $globalMentions = $this->parseMentions((string)$this->cfg('default_mentions', ''));

        $base = !empty($deptMentions) ? $deptMentions : $globalMentions;

        $extra = match ($notificationType) {
            'created' => $this->parseMentions((string)$this->cfg('mentions_on_created', '')),
            'updated' => $this->parseMentions((string)$this->cfg('mentions_on_updated', '')),
            'reply'   => $this->parseMentions((string)$this->cfg('mentions_on_reply', '')),
            default   => [],
        };

        $all = array_values(array_unique(array_merge($base, $extra)));

        return empty($all) ? '' : (implode(' ', $all) . "\n");
    }

    private function departmentMentionKey($ticket): string
    {
        $deptId = $ticket->department_id ?? $ticket->department?->id ?? null;
        return $deptId ? "mentions_department_{$deptId}" : '__none__';
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
        if (mb_strlen($_
