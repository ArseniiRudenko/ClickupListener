<?php

namespace Leantime\Plugins\ClickupListener\Services;

use Illuminate\Support\Facades\Log;
use Leantime\Plugins\ClickupListener\Repositories\ClickupListenerRepository;

class ClickupListener
{

    private ClickupListenerRepository $repository;

    public function __construct()
    {
        $this->repository = new ClickupListenerRepository();
    }

    public function install(): void
    {
        // Repo call to create tables.
        $this->repository->setup();
        Log::info('ClickUp Listener plugin Installed');
    }

    public function uninstall(): void
    {
        // Remove tables
        $this->repository->teardown();
        Log::info('ClickUp Listener plugin Uninstalled');
    }
}
