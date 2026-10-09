<?php

namespace justinholtweb\exactly\errors;

/**
 * A payment that cannot be entered in Exact as configured — no journal, no receivables account,
 * a currency the invoice was not raised in. Never retried: it answers the same way until a
 * person changes a setting, and the message says which.
 */
class PaymentEntryException extends \RuntimeException
{
}
