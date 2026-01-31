<?php

namespace Leantime\Plugins\ClickupListener\Controllers;

use Leantime\Core\Controller\Controller;
use Leantime\Plugins\ClickupListener\Repositories\ClickupListenerRepository;
use Leantime\Domain\Comments\Repositories\Comments as CommentRepository;
use Leantime\Domain\Tickets\Repositories\Tickets as TicketsRepository;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class Hook extends Controller
{
    private ClickupListenerRepository $repository;
    private TicketsRepository $ticketsRepository;
    private CommentRepository $commentRepository;

    public function init(ClickupListenerRepository $repository, TicketsRepository $ticketsRepository, CommentRepository $commentRepository): void
    {
        $this->repository = $repository;
        $this->ticketsRepository = $ticketsRepository;
        $this->commentRepository = $commentRepository;
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
        $allowedEvents = array_merge(
            ['taskcreated', 'taskupdated', 'taskstatusupdated', 'tasknameupdated', 'taskdescriptionupdated', 'taskpriorityupdated'],
            $this->getCommentEvents()
        );
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
        if (empty($taskData) && is_array($payload['payload'] ?? null) && $this->isTaskPayload($payload['payload'])) {
            $taskData = $payload['payload'];
        }
        $taskId = $this->extractTaskId($payload, $taskData);
        if ($taskId === '') {
            Log::warning('ClickupListener Hook: missing task id');
            return new Response(json_encode(['success' => false, 'message' => 'Missing task id']), 400, ['Content-Type' => 'application/json']);
        }

        if ($this->isCommentEvent($event)) {
            return $this->handleCommentEvent($payload, $taskData, (int)$matched['id'], $taskId, $event);
        }

        $historyItems = is_array($payload['history_items'] ?? null) ? $payload['history_items'] : [];
        if (empty($historyItems) && is_array($taskData['history_items'] ?? null)) {
            $historyItems = $taskData['history_items'];
        }
        if ($this->hasCommentHistoryItems($historyItems)) {
            $this->upsertCommentsFromHistory($historyItems, (int)$matched['id'], $taskId);
        }

        $projectId = (int)($matched['project_id'] ?? 0);
        if ($projectId <= 0) {
            Log::warning('ClickupListener Hook: config missing project_id');
            return new Response(json_encode(['success' => false, 'message' => 'Configuration missing project id']), 500, ['Content-Type' => 'application/json']);
        }

        $headline = $this->extractHeadline($taskData, $historyItems, $taskId);
        $description = $this->extractDescription($taskData, $historyItems);
        $statusId = $this->extractStatusId($historyItems, $projectId);
        $priorityId = $this->extractPriorityId($historyItems);
        $customFieldUpdates = $this->extractCustomFieldUpdates($historyItems);
        $tag = trim((string)($matched['task_tag'] ?? ''));
        [$parentClickupId, $hasParentField] = $this->extractParentTaskId($taskData, $payload);

        $map = $this->repository->getTaskMapByClickupId((int)$matched['id'], $taskId);
        if ($parentClickupId === '' && $map !== null && !empty($map['parent_clickup_task_id'])) {
            $parentClickupId = (string)$map['parent_clickup_task_id'];
        }
        if ($map !== null) {
            $ticketId = (int)($map['ticket_id'] ?? 0);
            $updates = $this->buildTicketUpdates($ticketId, $headline, $description, $statusId, $priorityId, $customFieldUpdates, $tag);

            if (!empty($updates) && $ticketId > 0) {
                $this->ticketsRepository->patchTicket($ticketId, $updates);
            }

            if ($hasParentField && $ticketId > 0) {
                $this->repository->updateTaskMapParent((int)$matched['id'], $taskId, $parentClickupId !== '' ? $parentClickupId : null);
            }
        } else {
            $ticketId = 0;
            if ($headline !== '' && $tag !== '') {
                $existingTicketId = $this->repository->findTicketIdByHeadlineAndTags($projectId, $headline, $this->splitTags($tag));
                if ($existingTicketId !== null && $existingTicketId > 0) {
                    $ticketId = $existingTicketId;
                    $updates = $this->buildTicketUpdates($ticketId, $headline, $description, $statusId, $priorityId, $customFieldUpdates, $tag);
                    if (!empty($updates)) {
                        $this->ticketsRepository->patchTicket($ticketId, $updates);
                    }
                    $this->repository->saveTaskMap((int)$matched['id'], $taskId, $ticketId, $hasParentField ? ($parentClickupId !== '' ? $parentClickupId : null) : null);
                }
            }

            if ($ticketId > 0) {
                $this->syncParentLinks((int)$matched['id'], $taskId, $ticketId, $parentClickupId);
                return new Response(json_encode(['success' => true]), 200, ['Content-Type' => 'application/json']);
            }

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
            if ($statusId !== null) {
                $values['status'] = $statusId;
            }
            if ($priorityId !== null) {
                $values['priority'] = $priorityId;
            }
            if (!empty($customFieldUpdates)) {
                foreach ($customFieldUpdates as $key => $value) {
                    if (!array_key_exists($key, $values)) {
                        $values[$key] = $value;
                    }
                }
            }
            $ticketId = $this->ticketsRepository->addTicket($values);
            if (is_int($ticketId) && $ticketId > 0) {
                $this->repository->saveTaskMap((int)$matched['id'], $taskId, $ticketId, $hasParentField ? ($parentClickupId !== '' ? $parentClickupId : null) : null);
            }
        }

        if (isset($ticketId) && is_int($ticketId) && $ticketId > 0) {
            $this->syncParentLinks((int)$matched['id'], $taskId, $ticketId, $parentClickupId);
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
        if ($taskId === '' && is_array($payload['payload'] ?? null)) {
            $payloadTask = $payload['payload'];
            if ($this->isTaskPayload($payloadTask)) {
                if (!empty($payloadTask['task_id'])) {
                    $taskId = (string)$payloadTask['task_id'];
                } elseif (!empty($payloadTask['taskId'])) {
                    $taskId = (string)$payloadTask['taskId'];
                } elseif (!empty($payloadTask['id'])) {
                    $taskId = (string)$payloadTask['id'];
                }
            }
        }
        return trim($taskId);
    }

    private function extractParentTaskId(array $taskData, array $payload): array
    {
        $parentId = '';
        $hasParentField = false;
        $sources = [$taskData, $payload];
        if (is_array($payload['payload'] ?? null)) {
            $sources[] = $payload['payload'];
        }

        foreach ($sources as $source) {
            foreach (['parent', 'parent_id', 'parentId'] as $key) {
                if (!is_array($source) || !array_key_exists($key, $source)) {
                    continue;
                }
                $hasParentField = true;
                $value = $source[$key];
                if (is_array($value)) {
                    if (isset($value['id'])) {
                        $parentId = (string)$value['id'];
                    }
                } elseif ($value !== null && $value !== '') {
                    $parentId = (string)$value;
                }
                if ($parentId !== '') {
                    break 2;
                }
            }
        }

        return [trim($parentId), $hasParentField];
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
            $description = trim((string)($taskData['text_content'] ?? ''));
        }
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

    private function extractStatusId(array $historyItems, int $projectId): ?int
    {
        $statusLabel = $this->findHistoryValue($historyItems, ['status']);
        if ($statusLabel !== '') {
            $statusId = $this->ticketsRepository->getStatusIdByName($statusLabel, $projectId);
            if ($statusId !== false) {
                return (int)$statusId;
            }
            $statusId = $this->mapStatusLabelToId($statusLabel, $projectId);
            if ($statusId !== null) {
                return $statusId;
            }
        }

        $statusType = $this->extractStatusType($historyItems);
        if ($statusType !== '') {
            return $this->mapStatusTypeToId($statusType, $projectId);
        }

        return null;
    }

    private function mapStatusLabelToId(string $statusLabel, int $projectId): ?int
    {
        $normalized = $this->normalizeLabel($statusLabel);
        if ($normalized === '') {
            return null;
        }

        $statusLabels = $this->ticketsRepository->getStateLabels($projectId);
        foreach ($statusLabels as $id => $status) {
            $name = (string)($status['name'] ?? '');
            if ($this->normalizeLabel($name) === $normalized) {
                return (int)$id;
            }
        }

        return null;
    }

    private function normalizeLabel(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/', '', $normalized);
        return $normalized ?? '';
    }

    private function extractPriorityId(array $historyItems): ?int
    {
        if (empty($historyItems)) {
            return null;
        }

        foreach ($historyItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $field = (string)($item['field'] ?? $item['type'] ?? '');
            if ($field !== 'priority') {
                continue;
            }

            $after = $item['after'] ?? null;
            $priority = null;
            $priorityId = null;

            if (is_array($after)) {
                if (isset($after['priority'])) {
                    $priority = (string)$after['priority'];
                }
                if (isset($after['id'])) {
                    $priorityId = (string)$after['id'];
                }
            } elseif (is_string($after) || is_numeric($after)) {
                $priority = (string)$after;
            }

            $mapped = $this->mapPriorityValue($priority ?? $priorityId ?? '');
            if ($mapped !== null) {
                return $mapped;
            }
        }

        return null;
    }

    private function mapPriorityValue(string $value): ?int
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        if (is_numeric($normalized)) {
            $numeric = (int)$normalized;
            if ($numeric >= 1 && $numeric <= 5) {
                return $numeric;
            }
        }

        $aliases = [
            'critical' => 1,
            'urgent' => 1,
            'blocker' => 1,
            'highest' => 1,
            'high' => 2,
            'medium' => 3,
            'normal' => 3,
            'low' => 4,
            'lowest' => 5,
            'none' => null,
            'n/a' => null,
        ];

        if (array_key_exists($normalized, $aliases)) {
            return $aliases[$normalized];
        }

        return null;
    }

    private function extractCustomFieldUpdates(array $historyItems): array
    {
        if (empty($historyItems)) {
            return [];
        }

        $columns = $this->repository->getTicketColumns();
        if (empty($columns)) {
            return [];
        }

        $updates = [];
        foreach ($historyItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $field = (string)($item['field'] ?? $item['type'] ?? '');
            if ($field !== 'custom_field') {
                continue;
            }

            $customField = is_array($item['custom_field'] ?? null) ? $item['custom_field'] : null;
            $fieldName = $customField['name'] ?? '';
            if ($fieldName === '') {
                continue;
            }

            $column = $this->mapCustomFieldToColumn($fieldName, $columns);
            if ($column === null) {
                continue;
            }

            $value = $this->extractCustomFieldValue($item, $customField);
            if ($value === null) {
                continue;
            }

            if ($this->isDateColumn($column)) {
                $value = $this->normalizeDateValue($value);
            } elseif ($this->isBoolColumn($column)) {
                $value = $this->normalizeBooleanValue($value);
            }

            $updates[$column] = $value;
        }

        return $updates;
    }

    private function mapCustomFieldToColumn(string $fieldName, array $columns): ?string
    {
        $normalized = $this->normalizeFieldName($fieldName);
        if ($normalized === '') {
            return null;
        }

        $map = $this->getCustomFieldColumnMap();
        if (isset($map[$normalized])) {
            $column = $map[$normalized];
            return isset($columns[$column]) ? $column : null;
        }

        return null;
    }

    private function extractCustomFieldValue(array $item, ?array $customField): ?string
    {
        $hasAfter = array_key_exists('after', $item);
        $raw = $hasAfter ? $item['after'] : ($item['value'] ?? null);
        if ($raw === null) {
            return $hasAfter ? '' : null;
        }

        if (is_array($raw)) {
            $values = [];
            foreach ($raw as $entry) {
                $values[] = $this->stringifyCustomFieldValue($entry, $customField);
            }
            $values = array_filter($values, static fn($val) => $val !== '');
            return implode(', ', $values);
        }

        return $this->stringifyCustomFieldValue($raw, $customField);
    }

    private function stringifyCustomFieldValue($value, ?array $customField): string
    {
        if (is_array($value)) {
            if (isset($value['name'])) {
                return trim((string)$value['name']);
            }
            if (isset($value['value'])) {
                return trim((string)$value['value']);
            }
            return '';
        }

        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        $stringValue = trim((string)$value);
        if ($stringValue === '') {
            return '';
        }

        if ($customField !== null) {
            $type = (string)($customField['type'] ?? '');
            if ($type === 'drop_down') {
                $mapped = $this->resolveCustomFieldOptionValue($customField, $stringValue);
                return $mapped !== '' ? $mapped : $stringValue;
            }
            if ($type === 'labels' && is_array($customField['type_config']['options'] ?? null)) {
                $mapped = $this->resolveCustomFieldOptionValue($customField, $stringValue);
                return $mapped !== '' ? $mapped : $stringValue;
            }
        }

        return $stringValue;
    }

    private function resolveCustomFieldOptionValue(array $customField, string $value): string
    {
        $options = $customField['type_config']['options'] ?? null;
        if (!is_array($options)) {
            return '';
        }
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }
            if ((string)($option['id'] ?? '') === $value) {
                $resolved = $option['value'] ?? $option['name'] ?? $option['id'] ?? '';
                return trim((string)$resolved);
            }
        }

        return '';
    }

    private function normalizeFieldName(string $name): string
    {
        $normalized = strtolower($name);
        $normalized = preg_replace('/[^a-z0-9]+/', '', $normalized);
        return $normalized ?? '';
    }

    private function getCustomFieldColumnMap(): array
    {
        return [
            'acceptancecriteria' => 'acceptanceCriteria',
            'priority' => 'priority',
            'planhours' => 'planHours',
            'hourremaining' => 'hourRemaining',
            'storypoints' => 'storypoints',
            'sprint' => 'sprint',
            'tags' => 'tags',
            'duedate' => 'dateToFinish',
            'datetofinish' => 'dateToFinish',
            'due' => 'dateToFinish',
            'startdate' => 'editFrom',
            'enddate' => 'editTo',
            'editfrom' => 'editFrom',
            'editto' => 'editTo',
            'url' => 'url',
            'component' => 'component',
            'version' => 'version',
            'os' => 'os',
            'browser' => 'browser',
            'resolution' => 'resolution',
            'production' => 'production',
            'staging' => 'staging',
            'type' => 'type',
        ];
    }

    private function isDateColumn(string $column): bool
    {
        return in_array($column, ['date', 'dateToFinish', 'editFrom', 'editTo'], true);
    }

    private function isBoolColumn(string $column): bool
    {
        return in_array($column, ['production', 'staging'], true);
    }

    private function normalizeDateValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (is_numeric($value)) {
            $timestamp = (int)$value;
            if ($timestamp > 9999999999) {
                $timestamp = (int)floor($timestamp / 1000);
            }
            return gmdate('Y-m-d H:i:s', $timestamp);
        }

        $parsed = strtotime($value);
        if ($parsed !== false) {
            return gmdate('Y-m-d H:i:s', $parsed);
        }

        return $value;
    }

    private function normalizeBooleanValue(string $value): string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === 'true' || $normalized === 'yes' || $normalized === '1') {
            return '1';
        }
        if ($normalized === 'false' || $normalized === 'no' || $normalized === '0') {
            return '0';
        }
        return $value;
    }

    private function extractStatusType(array $historyItems): string
    {
        foreach ($historyItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $field = (string)($item['field'] ?? $item['type'] ?? '');
            if ($field !== '' && $field !== 'status') {
                continue;
            }
            if (isset($item['data']) && is_array($item['data']) && isset($item['data']['status_type'])) {
                return strtolower((string)$item['data']['status_type']);
            }
            if (isset($item['after']) && is_array($item['after']) && isset($item['after']['type'])) {
                return strtolower((string)$item['after']['type']);
            }
        }

        return '';
    }

    private function mapStatusTypeToId(string $statusType, int $projectId): ?int
    {
        $statusType = strtolower($statusType);
        $statusLabels = $this->ticketsRepository->getStateLabels($projectId);
        $targets = [];

        if (in_array($statusType, ['closed', 'done', 'complete', 'completed'], true)) {
            $targets[] = 'DONE';
        } elseif (in_array($statusType, ['in_progress', 'inprogress', 'progress'], true)) {
            $targets[] = 'INPROGRESS';
        } else {
            $targets[] = 'NEW';
            $targets[] = 'INPROGRESS';
        }

        foreach ($targets as $target) {
            foreach ($statusLabels as $id => $status) {
                if (($status['statusType'] ?? '') === $target) {
                    return (int)$id;
                }
            }
        }

        return null;
    }

    private function isTaskPayload(array $taskData): bool
    {
        return isset($taskData['name']) || isset($taskData['description']) || isset($taskData['text_content']) || isset($taskData['status_id']);
    }

    private function getCommentEvents(): array
    {
        return ['taskcommentposted', 'taskcommentupdated', 'taskcommentdeleted', 'commentcreated', 'commentupdated', 'commentdeleted'];
    }

    private function isCommentEvent(string $event): bool
    {
        return $event !== '' && in_array($event, $this->getCommentEvents(), true);
    }

    private function isCommentUpdateEvent(string $event): bool
    {
        return in_array($event, ['taskcommentupdated', 'commentupdated'], true);
    }

    private function isCommentDeleteEvent(string $event): bool
    {
        return in_array($event, ['taskcommentdeleted', 'commentdeleted'], true);
    }

    private function handleCommentEvent(array $payload, array $taskData, int $configId, string $taskId, string $event): Response
    {
        $map = $this->repository->getTaskMapByClickupId($configId, $taskId);
        if ($map === null) {
            Log::warning('ClickupListener Hook: comment for unsynced task '.$taskId);
            return new Response(json_encode(['success' => true, 'message' => 'Task not synced']), 200, ['Content-Type' => 'application/json']);
        }

        $commentData = $this->extractCommentData($payload, $taskData);
        if ($commentData === null) {
            Log::warning('ClickupListener Hook: missing comment data');
            return new Response(json_encode(['success' => false, 'message' => 'Missing comment data']), 400, ['Content-Type' => 'application/json']);
        }

        $clickupCommentId = $commentData['id'];
        if ($clickupCommentId === '') {
            Log::warning('ClickupListener Hook: missing comment id');
            return new Response(json_encode(['success' => false, 'message' => 'Missing comment id']), 400, ['Content-Type' => 'application/json']);
        }

        $commentMap = $this->repository->getCommentMapByClickupId($configId, $clickupCommentId);
        if ($this->isCommentDeleteEvent($event)) {
            if ($commentMap !== null) {
                $commentId = (int)($commentMap['comment_id'] ?? 0);
                if ($commentId > 0) {
                    $this->commentRepository->deleteComment($commentId);
                }
                $this->repository->deleteCommentMap($configId, $clickupCommentId);
            }
            return new Response(json_encode(['success' => true]), 200, ['Content-Type' => 'application/json']);
        }

        $commentText = $commentData['text'];
        if ($commentText === '') {
            Log::warning('ClickupListener Hook: empty comment text');
            return new Response(json_encode(['success' => false, 'message' => 'Empty comment text']), 400, ['Content-Type' => 'application/json']);
        }

        if ($commentMap !== null) {
            if ($this->isCommentUpdateEvent($event)) {
                $commentId = (int)($commentMap['comment_id'] ?? 0);
                if ($commentId > 0) {
                    $this->commentRepository->editComment($commentText, $commentId);
                }
            }
            return new Response(json_encode(['success' => true]), 200, ['Content-Type' => 'application/json']);
        }

        $parentCommentId = 0;
        if ($commentData['parent_id'] !== '') {
            $parentMap = $this->repository->getCommentMapByClickupId($configId, $commentData['parent_id']);
            if ($parentMap !== null) {
                $parentCommentId = (int)($parentMap['comment_id'] ?? 0);
            }
        }

        $values = [
            'text' => $commentText,
            'userId' => 0,
            'date' => $commentData['date'],
            'moduleId' => (int)($map['ticket_id'] ?? 0),
            'commentParent' => $parentCommentId,
            'status' => '',
        ];
        $commentId = $this->commentRepository->addComment($values, 'ticket');
        if ($commentId !== false) {
            $this->repository->saveCommentMap($configId, $clickupCommentId, $taskId, (int)($map['ticket_id'] ?? 0), (int)$commentId);
        }

        return new Response(json_encode(['success' => true]), 200, ['Content-Type' => 'application/json']);
    }

    private function extractCommentData(array $payload, array $taskData): ?array
    {
        $comment = $this->extractCommentPayload($payload);
        if ($comment === null) {
            return null;
        }

        $id = '';
        foreach (['id', 'comment_id', 'commentId'] as $key) {
            if (!empty($comment[$key])) {
                $id = (string)$comment[$key];
                break;
            }
        }

        $text = '';
        foreach (['comment_text', 'text', 'comment', 'text_content', 'content'] as $key) {
            if (!empty($comment[$key])) {
                $text = (string)$comment[$key];
                break;
            }
        }

        if ($text === '' && !empty($comment['comment_text_rich']) && is_string($comment['comment_text_rich'])) {
            $text = $comment['comment_text_rich'];
        }

        $parentId = '';
        foreach (['parent', 'parent_id', 'parentId'] as $key) {
            if (!array_key_exists($key, $comment)) {
                continue;
            }
            $value = $comment[$key];
            if (is_array($value) && isset($value['id'])) {
                $parentId = (string)$value['id'];
                break;
            }
            if (is_string($value) || is_int($value)) {
                $parentId = (string)$value;
                break;
            }
        }

        $date = $comment['date'] ?? $comment['created_at'] ?? null;
        $date = $this->normalizeCommentDate($date);

        return [
            'id' => trim($id),
            'text' => trim($text),
            'date' => $date,
            'parent_id' => trim($parentId),
        ];
    }

    private function extractCommentPayload(array $payload): ?array
    {
        $candidates = [];
        if (is_array($payload['comment'] ?? null)) {
            $candidates[] = $payload['comment'];
        }
        if (is_array($payload['payload'] ?? null)) {
            if (is_array($payload['payload']['comment'] ?? null)) {
                $candidates[] = $payload['payload']['comment'];
            }
            if ($this->looksLikeCommentPayload($payload['payload'])) {
                $candidates[] = $payload['payload'];
            }
        }
        if (is_array($payload['data'] ?? null) && is_array($payload['data']['comment'] ?? null)) {
            $candidates[] = $payload['data']['comment'];
        }
        if (is_array($payload['history_items'] ?? null)) {
            foreach ($payload['history_items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (($item['field'] ?? '') === 'comment' && is_array($item['comment'] ?? null)) {
                    $candidates[] = $item['comment'];
                }
            }
        }

        foreach ($candidates as $candidate) {
            if ($this->looksLikeCommentPayload($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function looksLikeCommentPayload(array $comment): bool
    {
        return isset($comment['comment_text']) || isset($comment['text']) || isset($comment['comment']) || isset($comment['comment_text_rich']);
    }

    private function normalizeCommentDate($date): string
    {
        if (is_numeric($date)) {
            $timestamp = (int)$date;
            if ($timestamp > 9999999999) {
                $timestamp = (int)floor($timestamp / 1000);
            }
            return gmdate('Y-m-d H:i:s', $timestamp);
        }

        if (is_string($date) && $date !== '') {
            $parsed = strtotime($date);
            if ($parsed !== false) {
                return gmdate('Y-m-d H:i:s', $parsed);
            }
        }

        return gmdate('Y-m-d H:i:s');
    }

    private function hasCommentHistoryItems(array $historyItems): bool
    {
        foreach ($historyItems as $item) {
            if (is_array($item) && ($item['field'] ?? '') === 'comment' && is_array($item['comment'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function upsertCommentsFromHistory(array $historyItems, int $configId, string $taskId): void
    {
        $map = $this->repository->getTaskMapByClickupId($configId, $taskId);
        if ($map === null) {
            return;
        }

        foreach ($historyItems as $item) {
            if (!is_array($item) || ($item['field'] ?? '') !== 'comment' || !is_array($item['comment'] ?? null)) {
                continue;
            }

            $commentPayload = ['comment' => $item['comment']];
            $commentData = $this->extractCommentData($commentPayload, []);
            if ($commentData === null || $commentData['id'] === '' || $commentData['text'] === '') {
                continue;
            }

            $commentMap = $this->repository->getCommentMapByClickupId($configId, $commentData['id']);
            if ($commentMap !== null) {
                $commentId = (int)($commentMap['comment_id'] ?? 0);
                if ($commentId > 0) {
                    $this->commentRepository->editComment($commentData['text'], $commentId);
                }
                continue;
            }

            $values = [
                'text' => $commentData['text'],
                'userId' => 0,
                'date' => $commentData['date'],
                'moduleId' => (int)($map['ticket_id'] ?? 0),
                'commentParent' => 0,
                'status' => '',
            ];
            $commentId = $this->commentRepository->addComment($values, 'ticket');
            if ($commentId !== false) {
                $this->repository->saveCommentMap($configId, $commentData['id'], $taskId, (int)($map['ticket_id'] ?? 0), (int)$commentId);
            }
        }
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

    private function syncParentLinks(int $configId, string $taskId, int $ticketId, string $parentClickupId): void
    {
        if ($parentClickupId !== '') {
            $parentMap = $this->repository->getTaskMapByClickupId($configId, $parentClickupId);
            if ($parentMap !== null) {
                $this->updateTicketParent($ticketId, (int)($parentMap['ticket_id'] ?? 0));
            }
        }

        $childMaps = $this->repository->getTaskMapsByParentClickupId($configId, $taskId);
        if (!empty($childMaps)) {
            foreach ($childMaps as $childMap) {
                $this->updateTicketParent((int)($childMap['ticket_id'] ?? 0), $ticketId);
            }
        }
    }

    private function updateTicketParent(int $ticketId, int $parentTicketId): void
    {
        if ($ticketId <= 0 || $parentTicketId <= 0 || $ticketId === $parentTicketId) {
            return;
        }
        $ticket = $this->ticketsRepository->getTicket($ticketId);
        $currentParentId = is_object($ticket) ? (int)($ticket->dependingTicketId ?? 0) : 0;
        if ($currentParentId !== $parentTicketId) {
            $this->ticketsRepository->patchTicket($ticketId, ['dependingTicketId' => $parentTicketId]);
        }
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

    private function splitTags(string $tags): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $tags)), static function ($tag) {
            return $tag !== '';
        }));
    }

    private function buildTicketUpdates(
        int $ticketId,
        string $headline,
        string $description,
        ?int $statusId,
        ?int $priorityId,
        array $customFieldUpdates,
        string $tag
    ): array {
        $updates = [];
        if ($headline !== '') {
            $updates['headline'] = $headline;
        }
        if ($description !== '') {
            $updates['description'] = $description;
        }
        if ($statusId !== null) {
            $updates['status'] = $statusId;
        }
        if ($priorityId !== null) {
            $updates['priority'] = $priorityId;
        }
        if (!empty($customFieldUpdates)) {
            foreach ($customFieldUpdates as $key => $value) {
                if (!array_key_exists($key, $updates)) {
                    $updates[$key] = $value;
                }
            }
        }
        if ($tag !== '' && $ticketId > 0) {
            $existing = $this->ticketsRepository->getTicket($ticketId);
            $currentTags = is_object($existing) ? (string)($existing->tags ?? '') : '';
            $mergedTags = $this->mergeTags($currentTags, $tag);
            if ($mergedTags !== $currentTags) {
                $updates['tags'] = $mergedTags;
            }
        }

        return $updates;
    }
}
