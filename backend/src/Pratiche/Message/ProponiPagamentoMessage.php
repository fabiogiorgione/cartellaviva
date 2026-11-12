<?php

namespace App\Pratiche\Message;

/** Dopo l'OCR: cerca nel testo un avviso di pagamento da proporre. */
final class ProponiPagamentoMessage
{
    /** @param bool $rivaluta anche se il documento ha già una proposta (ignorata o aperta); mai se ha già un pagamento */
    public function __construct(public readonly string $documentoId, public readonly bool $rivaluta = false)
    {
    }
}
