<?php

declare(strict_types=1);

namespace Deployer\Notifiers\Infrastructure\Http;

use Deployer\Notifiers\Application\SaveNotifier;
use Deployer\Notifiers\Domain\NotifierConfig;
use Deployer\Notifiers\Domain\NotifierRepository;
use Deployer\Shared\Infrastructure\Http\ApiError;
use Deployer\Shared\Infrastructure\Http\Request;
use Deployer\Shared\Infrastructure\Http\Response;
use Deployer\Shared\Infrastructure\Http\RouteParams;
use Deployer\Shared\Infrastructure\Http\Router;

final class NotifiersController
{
    public function __construct(
        private readonly NotifierRepository $notifiers,
        private readonly SaveNotifier $save,
    ) {
    }

    public function register(Router $router): void
    {
        $router
            ->add('GET', '/api/notifiers', fn (): Response => Response::json([
                'notifiers' => array_map(self::present(...), $this->notifiers->all()),
            ]))
            ->add('POST', '/api/notifiers', fn (Request $r): Response => Response::json(
                ['notifier' => self::present(($this->save)($r->json()))],
                201,
            ))
            ->add('GET', '/api/notifiers/{id}', fn (Request $r, array $p): Response => Response::json(
                ['notifier' => self::present($this->load($p))],
            ))
            ->add('PUT', '/api/notifiers/{id}', function (Request $r, array $p): Response {
                $existing = $this->load($p);

                return Response::json(['notifier' => self::present(($this->save)($r->json(), $existing))]);
            })
            ->add('DELETE', '/api/notifiers/{id}', function (Request $r, array $p): Response {
                $this->notifiers->delete($this->load($p)->id);

                return Response::noContent();
            });
    }

    /** @param array<string,string> $params */
    public function load(array $params): NotifierConfig
    {
        return $this->notifiers->find(RouteParams::id($params)) ?? throw ApiError::notFound('Notifier not found.');
    }

    /** @return array<string,mixed> */
    public static function present(NotifierConfig $notifier): array
    {
        return [
            'id' => $notifier->id,
            'name' => $notifier->name,
            'type' => $notifier->type,
            'chat_id' => $notifier->chatId,
            'bot_token_masked' => $notifier->maskedBotToken(),
        ];
    }
}
