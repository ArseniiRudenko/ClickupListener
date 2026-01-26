<?php
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Leantime\Core\Events\EventDispatcher;
use Leantime\Domain\Plugins\Services\Registration;
use Leantime\Plugins\AuditTrail\Controllers\UiController;

EventDispatcher::add_filter_listener('leantime.core.*.publicActions', 'publicActionsFilterClickUp');

function publicActionsFilterClickUp($payload, $params){
    $payload[] = "ClickupListener.hook";
    $payload[] = "ClickupListener.settings.projectName";
    return $payload;
}

$reg = new Registration("ClickupListener");
$reg->registerLanguageFiles();
