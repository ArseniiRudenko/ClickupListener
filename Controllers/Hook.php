<?php

namespace Leantime\Plugins\ClickupListener\Controllers;

use Leantime\Core\Controller\Controller;
use Leantime\Plugins\ClickupListener\Repositories\ClickupListenerRepository;
use Leantime\Domain\Tickets\Repositories\Tickets as TicketsRepository;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class Hook extends Controller
{
    private ClickupListenerRepository $repository;
    private TicketsRepository $ticketsRepository;

    public function init(ClickupListenerRepository $repository, TicketsRepository $ticketsRepository): void
    {
        $this->repository = $repository;
        $this->ticketsRepository = $ticketsRepository;
    }

    /**
     * Receive webhook POST from ClickUp.
     *
     * @param array $params
     * @return Response
     */
    public function post(array $params): Response
    {
        $raw = $this->incomingRequest->getContent();
        if (empty($raw)) {
            Log::warning('ClickupListener Hook: empty payload');
            return new Response('Empty payload', 400);
        }

        $signature = $this->incomingRequest->headers->get('X-Signature');
        if (!empty($signature) && str_starts_with($signature, 'sha256=')) {
            $signature = substr($signature, 7);
        }

        $payload = @json_decode($raw, true);
        if (!is_array($payload)) {
            Log::warning('ClickupListener Hook: invalid JSON');
            return new Response('Invalid JSON', 400);
        }

        $event = strtolower((string)($payload['event'] ?? ''));
        $allowedEvents = ['taskcreated', 'taskupdated', 'taskstatusupdated', 'tasknameupdated', 'taskdescriptionupdated'];
        if ($event !== '' && !in_array($event, $allowedEvents, true)) {
            Log::info('ClickupListener Hook: ignoring event '.$event);
            return new Response(json_encode(['success' => true, 'message' => 'Event ignored']), 200, ['Content-Type' => 'application/json']);
        }

        Log::info('Incoming ClickUp webhook payload: '.$raw);

        $configs = $this->repository->getAll();
        $matched = null;

        if ($signature !== null && $signature !== '') {
            foreach ($configs as $cfg) {
                $secret = (string)($cfg['hook_secret'] ?? '');
                if ($secret === '') {
                    continue;
                }
                $expected = hash_hmac('sha256', $raw, $secret);
                if (hash_equals($expected, $signature) || hash_equals($secret, $signature)) {
                    $matched = $cfg;
                    break;
                }
            }
        }

        if ($matched === null) {
            $webhookId = (string)($payload['webhook_id'] ?? '');
            if ($webhookId !== '') {
                foreach ($configs as $cfg) {
                    if ((string)($cfg['webhook_id'] ?? '') === $webhookId) {
                        $matched = $cfg;
                        break;
                    }
                }
            }
        }

        if ($matched === null) {
            Log::warning('ClickupListener Hook: no matching config for incoming webhook');
            return new Response(json_encode(['success' => false, 'message' => 'No matching ClickUp configuration found']), 404, ['Content-Type' => 'application/json']);
        }

        $secret = (string)($matched['hook_secret'] ?? '');
        if ($secret !== '') {
            if ($signature === null || $signature === '') {
                Log::warning('ClickupListener Hook: missing signature');
                return new Response(json_encode(['success' => false, 'message' => 'Missing signature']), 403, ['Content-Type' => 'application/json']);
            }
            $expected = hash_hmac('sha256', $raw, $secret);
            if (!hash_equals($expected, $signature) && !hash_equals($secret, $signature)) {
                Log::warning('ClickupListener Hook: signature mismatch');
                return new Response(json_encode(['success' => false, 'message' => 'Signature mismatch']), 403, ['Content-Type' => 'application/json']);
            }
        }

        $taskData = is_array($payload['task'] ?? null) ? $payload['task'] : [];
        $taskId = $this->extractTaskId($payload, $taskData);
        if ($taskId === '') {
            Log::warning('ClickupListener Hook: missing task id');
            return new Response(json_encode(['success' => false, 'message' => 'Missing task id']), 400, ['Content-Type' => 'application/json']);
        }

        $projectId = (int)($matched['project_id'] ?? 0);
        if ($projectId <= 0) {
            Log::warning('ClickupListener Hook: config missing project_id');
            return new Response(json_encode(['success' => false, 'message' => 'Configuration missing project id']), 500, ['Content-Type' => 'application/json']);
        }

        $historyItems = is_array($payload['history_items'] ?? null) ? $payload['history_items'] : [];
        $headline = $this->extractHeadline($taskData, $historyItems, $taskId);
        $description = $this->extractDescription($taskData, $historyItems);
        $tag = trim((string)($matched['task_tag'] ?? ''));

        $map = $this->repository->getTaskMapByClickupId((int)$matched['id'], $taskId);
        if ($map !== null) {
            $ticketId = (int)($map['ticket_id'] ?? 0);
            $updates = [];
            if ($headline !== '') {
                $updates['headline'] = $headline;
            }
            if ($description !== '') {
                $updates['description'] = $description;
            }
            if ($tag !== '' && $ticketId > 0) {
                $existing = $this->ticketsRepository->getTicket($ticketId);
                $currentTags = is_object($existing) ? (string)($existing->tags ?? '') : '';
                $mergedTags = $this->mergeTags($currentTags, $tag);
                if ($mergedTags !== $currentTags) {
                    $updates['tags'] = $mergedTags;
                }
            }

            if (!empty($updates) && $ticketId > 0) {
                $this->ticketsRepository->patchTicket($ticketId, $updates);
            }
        } else {
            $tags = $tag !== '' ? $this->mergeTags('', $tag) : '';
            $values = [
                'headline' => $headline,
                'description' => $description,
                'projectId' => $projectId,
                'tags' => $tags,
                'type' => 'task',
                'userId' => 0,
                'editorId' => 0,
            ];
            $ticketId = $this->ticketsRepository->addTicket($values);
            if (is_int($ticketId) && $ticketId > 0) {
                $this->repository->saveTaskMap((int)$matched['id'], $taskId, $ticketId);
            }
        }

        return new Response(json_encode(['success' => true]), 200, ['Content-Type' => 'application/json']);
    }

    private function extractTaskId(array $payload, array $taskData): string
    {
        $taskId = '';
        if (!empty($taskData['id'])) {
            $taskId = (string)$taskData['id'];
        }
        if ($taskId === '' && !empty($payload['task_id'])) {
            $taskId = (string)$payload['task_id'];
        }
        return trim($taskId);
    }

    private function extractHeadline(array $taskData, array $historyItems, string $taskId): string
    {
        $headline = trim((string)($taskData['name'] ?? ''));
        if ($headline === '') {
            $headline = $this->findHistoryValue($historyItems, ['name', 'title']);
        }
        if ($headline === '') {
            $headline = 'ClickUp Task '.$taskId;
        }
        return $headline;
    }

    private function extractDescription(array $taskData, array $historyItems): string
    {
        $description = trim((string)($taskData['description'] ?? ''));
        if ($description === '') {
            $description = $this->findHistoryValue($historyItems, ['description', 'text']);
        }

        $url = trim((string)($taskData['url'] ?? $taskData['link'] ?? ''));
        if ($url !== '' && !str_contains($description, $url)) {
            if ($description !== '') {
                $description .= "\n\n";
            }
            $description .= 'ClickUp: '.$url;
        }

        return $description;
    }

    private function findHistoryValue(array $historyItems, array $fields): string
    {
        foreach ($historyItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $field = (string)($item['field'] ?? $item['type'] ?? '');
            if ($field === '' || !in_array($field, $fields, true)) {
                continue;
            }
            $after = $item['after'] ?? $item['value'] ?? null;
            if (is_string($after)) {
                return trim($after);
            }
            if (is_array($after)) {
                if (isset($after['name'])) {
                    return trim((string)$after['name']);
                }
                if (isset($after['status'])) {
                    return trim((string)$after['status']);
                }
            }
        }
        return '';
    }

    private function mergeTags(string $existingTags, string $newTags): string
    {
        $existing = array_filter(array_map('trim', explode(',', $existingTags)), static function ($tag) {
            return $tag !== '';
        });
        $incoming = array_filter(array_map('trim', explode(',', $newTags)), static function ($tag) {
            return $tag !== '';
        });
        $merged = array_values(array_unique(array_merge($existing, $incoming)));
        return implode(',', $merged);
    }
}
