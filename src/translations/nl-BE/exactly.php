<?php

/**
 * Exactly — Belgisch Nederlands.
 *
 * Een overlay, geen volledige catalogus: Yii’s PhpMessageSource legt nl-BE over nl heen, dus
 * hier staan alleen de teksten die echt anders zijn. Dat is vooral vaktaal — een Vlaamse
 * boekhouder *punt af*, een Nederlandse *lettert af*; het is hier een *algemene rekening* en
 * daar een *grootboekrekening* — plus een handvol woorden (enkel voor alleen,
 * verzendingskosten voor verzendkosten). De rest valt door naar nl hiernaast.
 *
 * Let op: Craft toetst de taalvoorkeur van een gebruiker aan de 31 talen waarvoor het zelf een
 * vertaald control panel heeft, en nl-BE zit daar niet bij — in het keuzemenu is deze taal dus
 * niet te kiezen. Zet `defaultCpLanguage` in `config/general.php` op 'nl-BE' om hem te gebruiken;
 * die waarde wordt niet getoetst en komt hier wel aan.
 */

return [
    'Reconciles against Exact’s open items. Run `exactly/sync/payments` on a schedule.' => 'Wordt afgepunt tegen de openstaande posten in Exact. Plan `exactly/sync/payments` periodiek in.',
    'Reconciliation' => 'Afpunting',
    'Default GL account' => 'Standaard algemene rekening',
    'Default revenue GL account' => 'Standaard opbrengstenrekening',
    'Discount GL account' => 'Algemene rekening korting',
    'Rounding GL account' => 'Algemene rekening afronding',
    'Shipping GL account' => 'Algemene rekening verzendingskosten',
    'Items and ledger' => 'Artikelen en algemene rekeningen',
    'Ledger code for invoice lines. Blank lets Exact use each item’s own revenue account.' => 'Code van de algemene rekening voor factuurregels. Leeg laat Exact de opbrengstenrekening van het artikel zelf gebruiken.',
    'Forgets cached account, item, VAT-code and ledger lookups for this division.' => 'Vergeet de gecachte relaties, artikelen, btw-codes en algemene rekeningen van deze administratie.',
    'Only when I ask' => 'Enkel als ik het zeg',
    'Email only' => 'Enkel e-mailadres',
    'VAT number only' => 'Enkel btw-nummer',
    'Everything but “Only when I ask” goes through the queue, so an Exact Online outage can never hold up a checkout.' => 'Alles behalve “Enkel als ik het zeg” loopt via de wachtrij, zodat een storing bij Exact Online nooit een bestelling kan ophouden.',
    'Used when the trigger above is “When the order reaches a status”.' => 'Wordt gebruikt als de trigger hierboven “Als de order een status bereikt” is.',
    'shipping' => 'verzendingskosten',
    'Shipping' => 'Verzendingskosten',
    'Shipping item code' => 'Artikelcode verzendingskosten',
    'This order has {amount} of shipping, but shipping lines are switched off.' => 'Deze order heeft {amount} aan verzendingskosten, maar regels voor verzendingskosten staan uit.',
    'Add a shipping line' => 'Regel voor verzendingskosten toevoegen',
    'Optional. Falls back to the address’s organization, then its name.' => 'Optioneel. Valt terug op de vennootschap bij het adres, daarna op de naam.',
    'With this off, any non-empty value in the VAT field is enough to zero-rate the sale.' => 'Staat dit uit, dan volstaat elke niet-lege waarde in het btw-veld om de verkoop op nul te zetten.',
];
