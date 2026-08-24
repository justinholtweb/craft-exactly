<?php

/**
 * Exactly — français de Belgique.
 *
 * Une surcouche, pas un catalogue complet : PhpMessageSource de Yii superpose fr-BE à fr, donc
 * seuls les textes réellement différents figurent ici. La différence tient à un mot, mais c’est
 * celui qui compte : la Belgique dit *note de crédit* là où la France dit *avoir*. Tout le reste
 * retombe sur fr à côté.
 *
 * À noter : Craft valide la langue préférée d’un utilisateur contre les 31 langues pour lesquelles
 * il fournit lui-même un panneau traduit, et fr-BE n’en fait pas partie — cette langue n’apparaît
 * donc pas dans le menu. Pour l’utiliser, réglez `defaultCpLanguage` sur 'fr-BE' dans
 * `config/general.php` : cette valeur n’est pas validée et arrive bien jusqu’ici.
 */

return [
    'credit' => 'note de crédit',
    'Credit note' => 'Note de crédit',
    'Credit note amounts' => 'Montants des notes de crédit',
    'Credit note {number}' => 'Note de crédit {number}',
    'Credit notes' => 'Notes de crédit',
    'Credit notes on refund' => 'Note de crédit en cas de remboursement',
    'Invoices and credit notes' => 'Factures et notes de crédit',
    'Issue a credit note in Exact Online for this order?' => 'Émettre une note de crédit dans Exact Online pour cette commande ?',
    'Issue credit notes' => 'Émettre des notes de crédit',
    'Exact’s field reference does not state which sign credit-note lines take, so this is a switch rather than an assumption. If credit notes come out doubling the invoice instead of cancelling it, change this.' => 'La documentation des champs d’Exact ne précise pas le signe des lignes d’une note de crédit : c’est donc un réglage et non une hypothèse. Si les notes de crédit doublent la facture au lieu de l’annuler, changez ceci.',
    'There is no Exact Online invoice to credit for this order.' => 'Il n’y a aucune facture Exact Online à créditer par une note de crédit pour cette commande.',
    'Reconciles against Exact’s open items. Run `exactly/sync/payments` on a schedule.' => 'Se rapproche des postes ouverts d’Exact. Planifiez `exactly/sync/payments`.',
];
