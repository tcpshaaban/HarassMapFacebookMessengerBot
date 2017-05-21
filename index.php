<?php

require_once __DIR__.'/vendor/autoload.php';

use Slim\App;
use Slim\Http\Request;
use Slim\Http\Response;
use Doctrine\DBAL\DriverManager;
use Tgallice\FBMessenger\Messenger;
use Tgallice\FBMessenger\WebhookRequestHandler;
use Tgallice\FBMessenger\Callback\MessageEvent;
use Tgallice\FBMessenger\Callback\PostbackEvent;
use HarassMapFbMessengerBot\Handlers\GetStartedHandler;
use HarassMapFbMessengerBot\Handlers\ReportIncidentHandler;
use HarassMapFbMessengerBot\Handlers\GetIncidentsHandler;
use Tgallice\FBMessenger\Exception\ApiException;

$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();
$dotenv->required(['PAGE_TOKEN', 'VERIFY_TOKEN']);

$settings = [
    'displayErrorDetails' => (bool) getenv('DEBUG'),
    'addContentLengthHeader' => false
];

$app = new App([
        'settings' => $settings,
        'messenger' => Messenger::create(getenv('PAGE_TOKEN')),
        'webhookHandler' => new WebhookRequestHandler(getenv('PAGE_TOKEN'), getenv('VERIFY_TOKEN')),
        'dbConnection' => function () {
            $dbConnectionParams = require('migrations-db.php');
            return DriverManager::getConnection($dbConnectionParams);
        }
]);

$app->get('/', function (Request $request, Response $response) {
    if ($this->webhookHandler->isValidVerifyTokenRequest()) {
        $response = $response->withStatus(200);
        return $response->write($this->webhookHandler->getChallenge());
    }
});

$app->post('/', function (Request $request, Response $response) {
    $this->webhookHandler->handleRequest($request);

    $events = $this->webhookHandler->getAllCallbackEvents();

    try {
        foreach ($events as $event) {
            if ($event instanceof MessageEvent) {
                if ($event->isQuickReply() && 0 === mb_strpos($event->getQuickReplyPayload(), 'GET_INCIDENTS')) {
                    $eventHandler = new GetIncidentsHandler($this->messenger, $event, $this->dbConnection);
                } else {
                    $eventHandler = new ReportIncidentHandler($this->messenger, $event, $this->dbConnection);
                }
            } elseif ($event instanceof PostbackEvent && $event->getPostbackPayload() === 'GET_STARTED') {
                $eventHandler = new GetStartedHandler($this->messenger, $event, $this->dbConnection);
            }
            if (isset($eventHandler)) {
                $eventHandler->handle();
            }
        }
    } catch (ApiException | Exception $e) {
        return $response->withStatus(200);
    }
});

$app->run();
