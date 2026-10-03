<?php

namespace justinholtweb\exactly\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use justinholtweb\exactly\helpers\Vat;

/**
 * Exactly settings.
 *
 * Nothing here is ever marked `required`. Craft validates plugin settings wholesale, so a single
 * `required` rule on the client ID would make a fresh install unable to save *any* setting until
 * an Exact Online app exists — which is the wrong way round, because the redirect URI on this
 * screen is what you need in order to register that app.
 *
 * OAuth tokens are deliberately *not* settings. They rotate on every refresh and they are
 * secrets; they live encrypted in `{{%exactly_connections}}` and never touch project config.
 */
class Settings extends Model
{
    /**
     * The site path Exact Online redirects back to. Registered as a site URL rule by the plugin.
     */
    public const CALLBACK_PATH = 'exactly/oauth/callback';

    // Connection
    // -------------------------------------------------------------------------

    /**
     * Exact Online is deployed per country and the API host follows: an account created in
     * Belgium cannot be reached on `start.exactonline.nl` at all.
     */
    public const REGIONS = [
        'nl' => 'https://start.exactonline.nl',
        'be' => 'https://start.exactonline.be',
        'de' => 'https://start.exactonline.de',
        'fr' => 'https://start.exactonline.fr',
        'es' => 'https://start.exactonline.es',
        'uk' => 'https://start.exactonline.co.uk',
        'com' => 'https://start.exactonline.com',
    ];

    public string $region = 'nl';

    /**
     * Overrides the region entirely. For Exact regions added after this release.
     */
    public string $customBaseUrl = '';

    /**
     * Exact Online app credentials, from apps.exactonline.com. Env-parseable.
     */
    public string $clientId = '';
    public string $clientSecret = '';

    /**
     * The administration (division) invoices are written into. Set by picking one after
     * connecting; `0` means "whichever division the connected user is currently in".
     */
    public int $division = 0;

    // Invoicing
    // -------------------------------------------------------------------------

    /**
     * When an order becomes an invoice.
     *
     * `manual` — only from the order screen, the console or the Twig API.
     * `completed` — as soon as the cart becomes an order.
     * `status` — when the order reaches one of `triggerStatusHandles`.
     * `paid` — when the order is fully paid.
     *
     */
    public string $pushTrigger = 'manual';

    /**
     * @var string[]
     */
    // `array|string` because a checkbox group with nothing ticked posts '' rather than [], and an
    // `array` property would throw on that before validation ever ran.
    public array|string $triggerStatusHandles = [];

    /**
     * Exact journal code for sales invoices. `70` is the Exact default sales journal.
     */
    public string $journalCode = '70';

    /**
     * `orderDate`, `paidDate` or `today`.
     */
    public string $invoiceDateSource = 'orderDate';

    /**
     * Which Craft order identifier goes on the invoice as the customer's reference.
     * One of `reference`, `number`, `shortNumber`, `id`.
     */
    public string $orderNumberSource = 'reference';

    /**
     * Object templates rendered against the order.
     */
    public string $descriptionTemplate = 'Order {{ object.reference ?? object.shortNumber }}';
    public string $lineDescriptionTemplate = '';

    /**
     * Optional remarks placed on the invoice.
     */
    public string $remarksTemplate = '';

    /**
     * Shipping cost becomes its own invoice line.
     */
    public bool $includeShippingLine = true;
    public string $shippingItemCode = '';
    public string $shippingGlAccountCode = '';

    /**
     * Discounts become one (negative) invoice line. Commerce keeps both order-level and line-level
     * discounts out of a line's subtotal, so both land on this one line and nothing is counted
     * twice. Switched off, the discount is left off the invoice and the push warns about it.
     */
    public bool $includeDiscountLine = true;
    public string $discountItemCode = '';
    public string $discountGlAccountCode = '';

    /**
     * Exact recomputes VAT from the line amounts, so its total can land a cent or two away from
     * what the customer was actually charged. Anything inside this tolerance is corrected with a
     * rounding line; anything outside it fails the push rather than quietly booking a wrong total.
     * `0` disables the correction.
     */
    public float $roundingTolerance = 0.02;
    public string $roundingItemCode = '';
    public string $roundingGlAccountCode = '';

    /**
     * VAT code for the rounding line. It has to be a 0% code: the line exists to move the total by
     * a cent, and a line that attracts VAT would move it by a cent plus VAT and never converge.
     */
    public string $roundingVatCode = '';

    /**
     * Exact payment condition code (payment terms) to stamp on the invoice.
     */
    public string $paymentConditionCode = '';

    public string $costCenterCode = '';
    public string $costUnitCode = '';

    // Accounts
    // -------------------------------------------------------------------------

    /**
     * How an order's customer is matched to an Exact account.
     * One of `email`, `vat`, `emailThenVat`, `vatThenEmail`.
     */
    public string $accountMatchStrategy = 'emailThenVat';

    public bool $createMissingAccounts = true;

    /**
     * Keep an existing Exact account's address in step with the order's. Off by default:
     * the merchant's own bookkeeping edits should not be overwritten by a checkout.
     */
    public bool $updateExistingAccounts = false;

    /**
     * `A` none, `S` suspect, `P` prospect, `C` customer.
     */
    public string $accountStatus = 'C';

    /**
     * Handle of a custom field carrying the customer's VAT number, for stores that collected it
     * before Craft had a home for it. Optional: the address's built-in Organization Tax ID
     * (`organizationTaxId`) is always read, from the billing address and then the shipping
     * address. When set, this field wins — looked for on the order, then the billing and shipping
     * addresses.
     */
    public string $vatNumberFieldHandle = '';

    /**
     * Handle of the field carrying a company name, when the store collects one separately.
     */
    public string $companyFieldHandle = '';

    // Items
    // -------------------------------------------------------------------------

    /**
     * `Item` is mandatory on an Exact sales invoice line, so every Commerce line has to resolve to
     * one.
     *
     * `sku` — look the item up by the purchasable's SKU, and fall back to `fallbackItemCode`.
     * `fallback` — put everything on one generic item and carry the detail in the description.
     */
    public string $itemStrategy = 'sku';

    /**
     * Create an Exact item for a SKU that has none.
     */
    public bool $createMissingItems = false;

    /**
     * The item every unmatched line falls back to. Without one, an unmatched SKU fails the push.
     */
    public string $fallbackItemCode = '';

    /**
     * Revenue GL account for invoice lines, when the item itself does not carry one.
     */
    public string $defaultGlAccountCode = '';

    /**
     * Commerce product type handle => GL account code.
     *
     * @var array<string, string>
     */
    public array $glAccountByProductType = [];

    // VAT
    // -------------------------------------------------------------------------

    /**
     * ISO country code the administration invoices from. Blank reads it from Exact.
     */
    public string $sellerCountry = '';

    /**
     * VAT treatment => Exact VAT code. The whole EU story lives in this map.
     *
     * @var array<string, string>
     */
    public array $vatCodeByTreatment = [];

    /**
     * Commerce tax rate ID => Exact VAT code. Takes precedence over the treatment map when
     * a line actually carries that rate, because a merchant with reduced rates knows better than
     * any inference.
     *
     * @var array<string, string>
     */
    public array $vatCodeByTaxRate = [];

    /**
     * Commerce tax categories whose sales are VAT-exempt (medical, education, financial services
     * and the like). A line in one of these categories that **carried no tax** takes the Exempt
     * treatment's VAT code instead of the order's treatment. A line that was charged tax keeps
     * the code for what it was charged — an exempt code there would make Exact's total disagree
     * with what the customer paid, and reconciliation would refuse it.
     *
     * `array|string` because a checkbox group with nothing ticked posts an empty string.
     *
     * @var int[]|string
     */
    public array|string $exemptTaxCategoryIds = [];

    /**
     * Reverse charge needs a structurally valid VAT number, not merely a non-empty one.
     */
    public bool $requireValidVatNumber = true;

    /**
     * Check the number against the EU VIES service before applying reverse charge.
     * Fails *closed*: an unverifiable number is treated as a consumer sale, which charges VAT.
     */
    public bool $viesValidation = false;

    /**
     * Seconds to wait on VIES. It is a public service and it is regularly slow.
     */
    public int $viesTimeout = 6;

    // Delivery
    // -------------------------------------------------------------------------

    /**
     * What Exact does with the invoice once it exists.
     *
     * `none` — leave it for the merchant to print.
     * `account` — whatever the Exact account is configured for.
     * `email` — email the PDF to the customer.
     * `postbox` — Exact's digital postbox (needs a Mailbox licence).
     * `peppol` — send over the Peppol network, for EU e-invoicing mandates.
     */
    public string $deliveryMode = 'none';

    public string $documentLayoutId = '';
    public string $emailLayoutId = '';
    public string $senderEmailAddress = '';
    public string $extraText = '';

    // Automation
    // -------------------------------------------------------------------------

    /**
     * Push through the queue rather than inline. On by default for everything but a manual push:
     * an Exact outage must never be able to stop a customer completing checkout.
     */
    public bool $useQueue = true;

    public int $maxAttempts = 5;

    /**
     * Minutes to wait after a failed attempt before the retry paths (maintenance, `exactly/sync/retry`,
     * the Retry failures button) pick the document up again. `0` retries at the next run. A push
     * refused by Exact's rate limit is not counted as an attempt, but still waits this long when it
     * is left to the retry paths — the queue job re-queues it on Exact's own reset time instead.
     */
    public int $retryDelayMinutes = 15;

    /**
     * Issue an Exact credit note when a Commerce order is refunded.
     */
    public bool $creditNotesOnRefund = false;

    /**
     * Whether a credit note carries positive or negative amounts.
     *
     * `Type: 8021` is what makes the document a credit note, and Exact's published field reference
     * does not say which sign the lines should take. Positive is what most administrations expect
     * and what this defaults to — but it is the one detail here that was not confirmed against a
     * second independent implementation, so it is a setting rather than an assumption. If credit
     * notes come out doubling the invoice instead of cancelling it, this is the switch.
     */
    public string $creditNoteSign = 'positive';

    /**
     * Read payment status back out of Exact and record it against the order.
     */
    public bool $paymentWriteback = false;

    /**
     * Commerce order status to move an order to once Exact reports the invoice paid. Blank leaves
     * the order alone and only records the payment on the document row.
     */
    public string $paidStatusHandle = '';

    // Logging
    // -------------------------------------------------------------------------

    public bool $loggingEnabled = true;

    /**
     * Store request and response bodies on log rows.
     */
    public bool $logPayloads = true;

    /**
     * Days of log history to keep. 0 keeps everything.
     */
    public int $logRetentionDays = 30;

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['division', 'logRetentionDays'], 'integer', 'min' => 0],
            [['maxAttempts'], 'integer', 'min' => 1, 'max' => 20],
            [['retryDelayMinutes'], 'integer', 'min' => 0, 'max' => 1440],
            [['viesTimeout'], 'integer', 'min' => 1, 'max' => 60],
            [['roundingTolerance'], 'number', 'min' => 0, 'max' => 5],
            [['region'], 'in', 'range' => array_keys(self::REGIONS)],
            [['pushTrigger'], 'in', 'range' => ['manual', 'completed', 'status', 'paid']],
            [['invoiceDateSource'], 'in', 'range' => ['orderDate', 'paidDate', 'today']],
            [['orderNumberSource'], 'in', 'range' => ['reference', 'number', 'shortNumber', 'id']],
            [['accountMatchStrategy'], 'in', 'range' => ['email', 'vat', 'emailThenVat', 'vatThenEmail']],
            [['itemStrategy'], 'in', 'range' => ['sku', 'fallback']],
            [['creditNoteSign'], 'in', 'range' => ['positive', 'negative']],
            [['deliveryMode'], 'in', 'range' => ['none', 'account', 'email', 'postbox', 'peppol']],
            [['accountStatus'], 'in', 'range' => ['A', 'S', 'P', 'C']],
            [['sellerCountry'], 'match', 'pattern' => '/^[A-Za-z]{0,2}$/'],
            [['customBaseUrl'], 'validateCustomBaseUrl'],
            [['senderEmailAddress'], 'email', 'skipOnEmpty' => true],
            [['documentLayoutId', 'emailLayoutId'], 'validateOptionalGuid'],
            [
                [
                    'triggerStatusHandles', 'vatCodeByTreatment', 'vatCodeByTaxRate',
                    'exemptTaxCategoryIds',
                    'glAccountByProductType',
                ],
                'safe',
            ],
            [
                [
                    'clientId', 'clientSecret', 'journalCode', 'descriptionTemplate',
                    'lineDescriptionTemplate', 'remarksTemplate', 'shippingItemCode',
                    'shippingGlAccountCode', 'discountItemCode', 'discountGlAccountCode',
                    'roundingItemCode', 'roundingGlAccountCode', 'paymentConditionCode',
                    'costCenterCode', 'costUnitCode', 'vatNumberFieldHandle', 'companyFieldHandle',
                    'roundingVatCode',
                    'fallbackItemCode', 'defaultGlAccountCode', 'extraText',
                ],
                'string',
            ],
        ];
    }

    /**
     * A base URL that is not a URL turns every call into a confusing DNS error, so it is worth
     * rejecting here. Empty is fine — that means "use the region".
     */
    public function validateCustomBaseUrl(string $attribute): void
    {
        $value = trim((string)App::parseEnv($this->$attribute));

        if ($value === '') {
            return;
        }

        if (!self::isExactHost($value)) {
            $this->addError($attribute, Craft::t('exactly', 'Enter an Exact Online https:// address, such as https://start.exactonline.nl, or leave this blank to use the region above.'));
        }
    }

    /**
     * Whether a base URL is an Exact Online host. Every request carries the bearer token and the
     * token refresh carries the client secret, so this is the one setting that decides where those
     * go — it is pinned to Exact's own domains rather than to "any https URL".
     */
    public static function isExactHost(string $url): bool
    {
        return (bool)preg_match('~^https://([a-z0-9-]+\.)*exactonline\.[a-z]{2,3}(\.[a-z]{2})?/?$~i', $url);
    }

    /**
     * Exact layout IDs are GUIDs. A pasted layout *name* is a 400 from the print endpoint at the
     * worst possible moment — after the invoice already exists.
     */
    public function validateOptionalGuid(string $attribute): void
    {
        $value = trim((string)$this->$attribute);

        if ($value === '') {
            return;
        }

        if (!\justinholtweb\exactly\helpers\Odata::isGuid($value)) {
            $this->addError($attribute, Craft::t('exactly', 'This has to be an Exact layout ID (a GUID), not a name.'));
        }
    }

    /**
     * The API host for the configured region.
     */
    public function getBaseUrl(): string
    {
        $custom = trim((string)App::parseEnv($this->customBaseUrl));

        // Checked again here because an environment variable is never seen by validation.
        if ($custom !== '' && self::isExactHost($custom)) {
            return rtrim($custom, '/');
        }

        return self::REGIONS[$this->region] ?? self::REGIONS['nl'];
    }

    public function getParsedClientId(): string
    {
        return trim((string)App::parseEnv($this->clientId));
    }

    public function getParsedClientSecret(): string
    {
        return trim((string)App::parseEnv($this->clientSecret));
    }

    /**
     * Whether an Exact Online app has been configured at all.
     */
    public function hasCredentials(): bool
    {
        return $this->getParsedClientId() !== '' && $this->getParsedClientSecret() !== '';
    }

    /**
     * The redirect URI to register on the Exact Online app.
     *
     * Exact matches this string exactly, including the scheme and any trailing slash, so it is
     * generated rather than typed. `UrlHelper::actionUrl()` respects the site's own base URL,
     * which is what makes this correct behind a proxy or on a non-standard port.
     */
    public function getRedirectUri(): string
    {
        // A *site* URL, not an action URL. OAuth providers are fussy about redirect URIs, and
        // Craft's action URLs are `?p=admin/actions/…` on any install without
        // `omitScriptNameInUrls` — a query string where a provider expects a path. Exactly
        // registers a site route for this path so the clean form actually resolves.
        return UrlHelper::siteUrl(self::CALLBACK_PATH);
    }

    /**
     * Whether the generated redirect URI carries a query string.
     *
     * It does when `omitScriptNameInUrls` is off, and that is worth saying out loud on the
     * settings screen: the URI has to be registered on the Exact app character for character, and
     * a provider that will not accept `?p=` leaves the merchant with a redirect_uri mismatch and
     * nothing to go on.
     */
    public function redirectUriIsClean(): bool
    {
        return !str_contains($this->getRedirectUri(), '?');
    }

    /**
     * Identifies the app + region a stored token belongs to. Changing either invalidates the
     * connection, and this is how that is noticed rather than discovered through a 401 storm.
     */
    public function getConnectionKey(): string
    {
        return substr(sha1($this->getBaseUrl() . '|' . $this->getParsedClientId()), 0, 40);
    }

    /**
     * The Exact VAT code for a treatment, or null when the merchant has not mapped it.
     */
    public function getVatCodeForTreatment(string $treatment): ?string
    {
        $code = trim((string)($this->vatCodeByTreatment[$treatment] ?? ''));

        return $code !== '' ? $code : null;
    }

    /**
     * The Commerce tax category IDs marked VAT-exempt, normalised.
     *
     * @return int[]
     */
    public function getExemptTaxCategoryIds(): array
    {
        $ids = is_array($this->exemptTaxCategoryIds) ? $this->exemptTaxCategoryIds : [];

        return array_values(array_unique(array_filter(
            array_map('intval', array_filter($ids, 'is_numeric')),
            static fn(int $id) => $id > 0,
        )));
    }

    /**
     * Every treatment that has to be mapped before a push can be trusted.
     *
     * @return string[]
     */
    public function getUnmappedTreatments(): array
    {
        $missing = [];

        foreach (array_keys(Vat::treatments()) as $treatment) {
            // Exempt only needs a code once some tax category is marked exempt.
            if ($treatment === Vat::TREATMENT_EXEMPT && $this->getExemptTaxCategoryIds() === []) {
                continue;
            }

            if ($this->getVatCodeForTreatment($treatment) === null) {
                $missing[] = $treatment;
            }
        }

        return $missing;
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return [
            'clientId' => Craft::t('exactly', 'Client ID'),
            'clientSecret' => Craft::t('exactly', 'Client secret'),
            'region' => Craft::t('exactly', 'Region'),
            'division' => Craft::t('exactly', 'Division'),
            'journalCode' => Craft::t('exactly', 'Sales journal'),
            'fallbackItemCode' => Craft::t('exactly', 'Fallback item code'),
            'defaultGlAccountCode' => Craft::t('exactly', 'Default GL account'),
        ];
    }
}
