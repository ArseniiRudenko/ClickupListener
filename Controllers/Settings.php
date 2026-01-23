<?php

namespace Leantime\Plugins\ClickupListener\Controllers;

use Illuminate\Support\Facades\Log;
use Leantime\Core\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Leantime\Plugins\ClickupListener\Repositories\ClickupListenerRepository;
use Leantime\Core\Controller\Frontcontroller;

class Settings extends Controller
{
    private ClickupListenerRepository $repository;

    /**
     * init
     *
     * @return void
     */
    public function init(ClickupListenerRepository $repository): void
    {
        $this->repository = $repository;
    }

    /**
     * get
     *
     * @return Response
     *
     * @throws \Exception
     */
    public function get(): Response
    {
        // Load existing configurations and pass to template
        $configs = [];
        try {
            $configs = $this->repository->getAll();
        } catch (\Throwable $e) {
            Log::error('Could not load ClickUp Listener configurations: '.$e->getMessage());
            $this->tpl->setNotification('Could not load ClickUp Listener configurations: '.$e->getMessage(), 'error');
        }

        $this->tpl->assign('clickupConfigs', $configs);

        return $this->tpl->display("ClickupListener.settings");
    }

    /**
     * post
     *
     * @param array $params
     * @return Response
     */
    public function post(array $params): Response
    {
        $req = $this->incomingRequest->request;

        $webhookId = trim((string)$req->get('webhook_id', ''));
        $hookSecret = trim((string)$req->get('hook_secret', ''));
        $projectId = (int)$req->get('project_id', 0);
        $taskTag = trim((string)$req->get('task_tag', ''));

        // Basic validation
        if ($projectId <= 0) {
            $this->tpl->setNotification('Project ID is required.', 'error');
            return Frontcontroller::redirect(BASE_URL.'/ClickupListener/settings');
        }

        if ($webhookId === '' && $hookSecret === '') {
            $this->tpl->setNotification('Webhook ID or webhook secret is required.', 'error');
            return Frontcontroller::redirect(BASE_URL.'/ClickupListener/settings');
        }

        if (!$this->repository->projectExists($projectId)) {
            $this->tpl->setNotification('Project ID does not exist.', 'error');
            return Frontcontroller::redirect(BASE_URL.'/ClickupListener/settings');
        }

        $data = [
            'webhook_id' => $webhookId !== '' ? $webhookId : null,
            'hook_secret' => $hookSecret,
            'project_id' => $projectId,
            'task_tag' => $taskTag,
        ];

        try {
            $savedId = $this->repository->save($data);
            if ($savedId === null) {
                $this->tpl->setNotification('Failed to save configuration.', 'error');
                return Frontcontroller::redirect(BASE_URL.'/ClickupListener/settings');
            }

            $this->tpl->setNotification('ClickUp listener configuration saved.', 'success', 'clickup_config_saved');

        } catch (\Throwable $e) {
            Log::error('Error saving ClickUp Listener configuration: '.$e->getMessage());
            $this->tpl->setNotification('Error saving configuration: '.$e->getMessage(), 'error');
            return Frontcontroller::redirect(BASE_URL.'/ClickupListener/settings');
        }

        return Frontcontroller::redirect(BASE_URL.'/ClickupListener/settings');
    }

    /**
     * update - update project and tag for a configuration (POST: id, project_id, task_tag)
     * Returns JSON {success, message}
     */
    public function update(array $params): Response
    {
        $req = $this->incomingRequest->request;
        $id = $req->get('id');
        $projectId = (int)$req->get('project_id', 0);
        $taskTag = trim((string)$req->get('task_tag', ''));

        if (!is_numeric($id)) {
            return new Response(json_encode(['success' => false, 'message' => 'Invalid id']), 400, ['Content-Type' => 'application/json']);
        }

        if ($projectId <= 0) {
            return new Response(json_encode(['success' => false, 'message' => 'Project ID is required']), 400, ['Content-Type' => 'application/json']);
        }

        if (!$this->repository->projectExists($projectId)) {
            return new Response(json_encode(['success' => false, 'message' => 'Project ID does not exist']), 400, ['Content-Type' => 'application/json']);
        }

        $id = (int)$id;

        try {
            $config = $this->repository->getById($id);
            if ($config === null) {
                return new Response(json_encode(['success' => false, 'message' => 'Configuration not found']), 404, ['Content-Type' => 'application/json']);
            }
            $ok = $this->repository->updateProjectAndTag($id, $projectId, $taskTag);
            if (!$ok) {
                return new Response(json_encode(['success' => false, 'message' => 'Failed to update configuration in DB']), 500, ['Content-Type' => 'application/json']);
            }

            return new Response(json_encode(['success' => true, 'message' => 'Configuration updated']), 200, ['Content-Type' => 'application/json']);
        } catch (\Throwable $e) {
            Log::error('Failed to update ClickUp Listener configuration: '.$e->getMessage());
            return new Response(json_encode(['success' => false, 'message' => 'Error: '.$e->getMessage()]), 500, ['Content-Type' => 'application/json']);
        }
    }

    /**
     * delete - remove a configuration by id (POST: id)
     * Returns JSON {success, message}
     */
    public function delete(array $params): Response
    {
        $req = $this->incomingRequest->request;
        $id = $req->get('id');

        if (!is_numeric($id)) {
            return new Response(json_encode(['success' => false, 'message' => 'Invalid id']), 400, ['Content-Type' => 'application/json']);
        }

        try {
            $ok = $this->repository->deleteById((int)$id);
            if ($ok) {
                return new Response(json_encode(['success' => true, 'message' => 'Configuration deleted']), 200, ['Content-Type' => 'application/json']);
            }

            return new Response(json_encode(['success' => false, 'message' => 'Delete failed']), 500, ['Content-Type' => 'application/json']);
        } catch (\Throwable $e) {
            Log::error('Failed to delete ClickUp Listener configuration: '.$e->getMessage());
            return new Response(json_encode(['success' => false, 'message' => 'Error: '.$e->getMessage()]), 500, ['Content-Type' => 'application/json']);
        }
    }

    /**
     * test - accepts project_id and checks that it exists.
     *
     * @param array $params
     * @return Response
     */
    public function test(array $params): Response
    {
        $req = $this->incomingRequest->request;

        $projectId = (int)$req->get('project_id', 0);
        if ($projectId <= 0) {
            return new Response(json_encode(['success' => false, 'message' => 'Project ID is required']), 400, ['Content-Type' => 'application/json']);
        }

        $exists = $this->repository->projectExists($projectId);
        if ($exists) {
            $result = ['success' => true, 'message' => 'Project found'];
        } else {
            $result = ['success' => false, 'message' => 'Project not found'];
        }

        return new Response(json_encode($result), ($result['success'] ? 200 : 400), ['Content-Type' => 'application/json']);
    }
}
