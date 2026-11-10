<?php

namespace App\Portale\MessageHandler;

use App\Portale\IdeeService;
use App\Portale\Message\RiepilogoIdeeMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RiepilogoIdeeHandler
{
    public function __construct(
        private readonly IdeeService $idee,
    ) {
    }

    /** @return array{idee: int, email: int} */
    public function __invoke(RiepilogoIdeeMessage $message): array
    {
        return $this->idee->inviaRiepilogo();
    }
}
