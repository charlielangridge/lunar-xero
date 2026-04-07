<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use CharlieLangridge\LunarXero\Data\InvoicePayload;
use CharlieLangridge\LunarXero\Exceptions\XeroConfigurationException;
use CharlieLangridge\LunarXero\Repositories\XeroSettingsRepository;
use CharlieLangridge\LunarXero\Services\XeroClient;
use Illuminate\Support\Facades\Session;
use XeroAPI\XeroPHP\Models\Accounting\Bill;
use XeroAPI\XeroPHP\Models\Accounting\Contact;
use XeroAPI\XeroPHP\Models\Accounting\PaymentTerm;
use XeroAPI\XeroPHP\Models\Accounting\PaymentTermType;

it('builds the OAuth authorization URL with the read-only scopes from the skill', function (): void {
    config()->set('lunarpanel-xero.oauth.read_only', true);
    Session::start();

    $url = app(XeroClient::class)->getAuthorizationUrl();

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($query)
        ->toHaveKeys([
            'response_type',
            'client_id',
            'redirect_uri',
            'scope',
            'state',
            'code_challenge',
            'code_challenge_method',
        ])
        ->and($query['response_type'])->toBe('code')
        ->and($query['client_id'])->toBe('test-client')
        ->and(explode(' ', (string) $query['scope']))->toBe([
            'openid',
            'email',
            'profile',
            'offline_access',
            'accounting.contacts.read',
            'accounting.settings',
            'accounting.transactions.read',
            'accounting.reports.read',
        ])
        ->and($query['code_challenge_method'])->toBe('S256');
});

it('builds the OAuth authorization URL with write scopes when read-only mode is disabled', function (): void {
    config()->set('lunarpanel-xero.oauth.read_only', false);
    Session::start();

    $url = app(XeroClient::class)->getAuthorizationUrl();

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect(explode(' ', (string) $query['scope']))->toBe([
        'openid',
        'email',
        'profile',
        'offline_access',
        'accounting.contacts',
        'accounting.settings',
        'accounting.transactions',
        'accounting.reports.read',
    ]);
});

it('blocks mutating API calls while the client is in read-only mode', function (): void {
    config()->set('lunarpanel-xero.oauth.read_only', true);

    $payload = new InvoicePayload(
        contactId: 'contact-1',
        type: 'ACCREC',
        status: 'DRAFT',
        reference: 'ORDER-1',
        lines: [],
    );

    expect(fn () => app(XeroClient::class)->createInvoice($payload))
        ->toThrow(
            XeroConfigurationException::class,
            'The Xero client is configured for read-only API access and cannot create invoices.',
        );
});

it('uses the contact sales payment terms to calculate invoice due dates', function (): void {
    $salesTerms = new Bill;
    $salesTerms->setType(PaymentTermType::OFFOLLOWINGMONTH);
    $salesTerms->setDay(10);

    $paymentTerms = new PaymentTerm;
    $paymentTerms->setSales($salesTerms);

    $contact = new Contact;
    $contact->setPaymentTerms($paymentTerms);

    $client = new class(app(XeroSettingsRepository::class)) extends XeroClient
    {
        public function dueDateFor(Contact $contact, CarbonImmutable $issueDate): CarbonImmutable
        {
            return $this->calculateDueDateFromContact($contact, $issueDate);
        }
    };

    expect($client->dueDateFor($contact, CarbonImmutable::parse('2026-04-04'))->toDateString())
        ->toBe('2026-05-10');
});
