<?php

namespace App\ConnettoreAi\MessageHandler;

use App\ConnettoreAi\LavoriAi;
use App\ConnettoreAi\Message\RiprendiLavoriAiMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RiprendiLavoriAiHandler
{
    public function __construct(private readonly LavoriAi $lavori)
    {
    }

    public function __invoke(RiprendiLavoriAiMessage $message): void
    {
        $this->lavori->riprendi();
    }
}
